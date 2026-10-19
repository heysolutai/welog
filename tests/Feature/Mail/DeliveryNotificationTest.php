<?php

namespace Tests\Feature\Mail;

use App\Contracts\Integrations\CorreiosGateway;
use App\Integrations\Correios\CorreiosApiGateway;
use App\Integrations\Support\TrackingStatus;
use App\Jobs\FluxoA\PollCarrierTracking;
use App\Mail\ShipmentUpdateMail;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * O e-mail de entrega dependia de o rastreio devolver a string 'entregue' —
 * o que nenhum gateway real faz. Estes testes cobrem o caminho de produção:
 * resposta no formato real dos Correios → status canônico → e-mail.
 */
class DeliveryNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function redemption(string $trackingCode = 'BR123456789WL'): Redemption
    {
        $company = Company::firstOrCreate(['cnpj' => '12345678000190'], ['name' => 'ACME Ltda']);
        $product = Product::firstOrCreate(['omie_code' => 'KIT-A'], ['name' => 'Kit Onboarding']);
        $project = Project::create(['company_id' => $company->id, 'name' => 'Proj', 'released_at' => now()]);
        $user = User::factory()->create(['role' => User::ROLE_END_CUSTOMER, 'company_id' => $company->id]);

        return Redemption::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'recipient_name' => $user->name,
            'zip' => '80010000',
            'street' => 'Rua XV',
            'number' => '100',
            'district' => 'Centro',
            'city' => 'Curitiba',
            'state' => 'PR',
            'status' => Redemption::STATUS_SHIPPED,
            'carrier' => Redemption::CARRIER_CORREIOS,
            'tracking_code' => $trackingCode,
        ]);
    }

    public function test_evento_sro_de_entrega_marca_entregue_e_envia_email(): void
    {
        Mail::fake();
        $redemption = $this->redemption();

        // Resposta no formato real da API SRO-Rastro (CWS)
        $this->fakeCorreiosApi([
            ['codigo' => 'PO', 'tipo' => '01', 'descricao' => 'Objeto postado'],
            ['codigo' => 'BDE', 'tipo' => '01', 'descricao' => 'Objeto entregue ao destinatário'],
        ]);

        app(PollCarrierTracking::class)->handle(app(CorreiosGateway::class));

        $redemption->refresh();
        $this->assertSame(Redemption::STATUS_DELIVERED, $redemption->status);
        $this->assertNotNull($redemption->delivered_at);
        Mail::assertQueued(ShipmentUpdateMail::class, 1);

        // reorder: a relação já ordena por occurred_at desc
        $this->assertSame(
            [TrackingStatus::Posted->value, TrackingStatus::Delivered->value],
            $redemption->trackingEvents()->reorder('occurred_at')->pluck('status')->all(),
        );
    }

    public function test_tentativa_de_entrega_frustrada_nao_fecha_o_pedido(): void
    {
        Mail::fake();
        $redemption = $this->redemption();

        // BDE tipo 02 = destinatário ausente, NÃO é entrega
        $this->fakeCorreiosApi([
            ['codigo' => 'BDE', 'tipo' => '02', 'descricao' => 'Carteiro não atendido'],
        ]);

        app(PollCarrierTracking::class)->handle(app(CorreiosGateway::class));

        $this->assertSame(Redemption::STATUS_SHIPPED, $redemption->fresh()->status);
        Mail::assertNothingQueued();
    }

    public function test_rastreio_com_falha_nao_impede_os_demais_resgates(): void
    {
        Mail::fake();
        $quebrado = $this->redemption('BR000000000WL');
        $ok = $this->redemption('BR999999999WL');

        // Gateway que falha só no primeiro código consultado
        app()->instance(CorreiosGateway::class, new class implements CorreiosGateway
        {
            public function createPrePostagem(array $payload): array
            {
                return ['tracking_code' => ''];
            }

            public function track(string $trackingCode): array
            {
                if ($trackingCode === 'BR000000000WL') {
                    throw new RuntimeException('Objeto não encontrado');
                }

                return [[
                    'occurred_at' => now()->toIso8601String(),
                    'status' => TrackingStatus::Delivered->value,
                    'description' => 'Objeto entregue ao destinatário',
                    'location' => null,
                ]];
            }
        });

        app(PollCarrierTracking::class)->handle(app(CorreiosGateway::class));

        $this->assertSame(Redemption::STATUS_SHIPPED, $quebrado->fresh()->status);
        $this->assertSame(Redemption::STATUS_DELIVERED, $ok->fresh()->status);
        Mail::assertQueued(ShipmentUpdateMail::class, 1);
    }

    /** @param array<int, array<string, string>> $eventos */
    private function fakeCorreiosApi(array $eventos): void
    {
        Cache::forget('correios.cws.token');

        Http::fake([
            '*/token/v1/autentica/contrato' => Http::response(['token' => 'tok-teste']),
            '*/srorastro/v1/objetos/*' => Http::response([
                'objetos' => [[
                    'eventos' => array_map(fn (array $e, int $i) => $e + [
                        'dtHrCriado' => now()->subHours(count($eventos) - $i)->toIso8601String(),
                        'unidade' => ['endereco' => ['cidade' => 'Curitiba', 'uf' => 'PR']],
                    ], $eventos, array_keys($eventos)),
                ]],
            ]),
        ]);

        app()->instance(CorreiosGateway::class, new CorreiosApiGateway(
            'https://api.correios.com.br', 'usuario', 'codigo', 'contrato',
        ));
    }
}

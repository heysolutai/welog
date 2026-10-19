<?php

namespace App\Services\FluxoA;

use App\Exceptions\DomainException;
use App\Mail\NotificaColaborador;
use App\Mail\RedemptionConfirmedMail;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use App\Support\OpcaoDeVariacao;
use App\Support\Values\Cpf;
use Illuminate\Support\Facades\DB;

/**
 * Fluxo A, etapa 10: o Usuário seleciona o kit a que tem direito,
 * informa endereço de entrega e confirma o resgate.
 *
 * Inclusões do UAT: CPF obrigatório (vai para a emissão fiscal e para a baixa
 * na Omie), observações opcionais e o grupo de escolha — autorização com
 * `choice_group` só resgata se NENHUMA irmã do grupo foi resgatada antes.
 */
class RedeemKit
{
    public function __construct(private readonly ProjectStockLedger $ledger) {}

    /**
     * @param  array{recipient_name: string, cpf?: ?string, zip: string, street: string, number: string,
     *               complement?: ?string, district: string, city: string, state: string, notes?: ?string,
     *               variant_sku_id?: ?int}  $data
     */
    public function handle(ProjectEntitlement $entitlement, array $data, User $actor): Redemption
    {
        if ($actor->id !== $entitlement->user_id) {
            throw new DomainException('Este resgate pertence a outro Usuário.');
        }

        if (! $entitlement->project->isReleased()) {
            throw new DomainException('Este Projeto ainda não foi liberado para resgate.');
        }

        // Freio de mão puxado por algum admin: a fila para na hora, mesmo com
        // o Projeto liberado e o kit em estoque.
        if ($entitlement->project->isRedemptionBlocked()) {
            throw new DomainException(
                'Os resgates deste Projeto estão temporariamente bloqueados. Fale com a WeGift.',
            );
        }

        // A trava de estágio é verificada NA HORA do resgate, não só na
        // liberação: item danificado faz o Projeto voltar de "Em Estoque" para
        // Montagem, e a partir daí não há kit para entregar. É por isso que a
        // regressão de estágio não precisa de tela própria — ela já fecha o
        // resgate sozinha.
        if (! $entitlement->project->isInStock()) {
            throw new DomainException(
                'Este kit saiu do estoque e o resgate está suspenso. Fale com a WeGift.',
            );
        }

        // O controller já validou; revalidar aqui protege quem chama o serviço
        // direto (o CPF segue para a Omie e para a NF — não pode entrar torto).
        // Cliente que não coleta CPF (Customizações) resgata sem ele.
        $cpf = ($entitlement->project->company?->pedeCpf() ?? true)
            ? (Cpf::tentar($data['cpf'] ?? null) ?? throw new DomainException(Cpf::mensagemDeErro()))
            : null;

        $variacao = $this->variacaoEscolhida($entitlement, $data['variant_sku_id'] ?? null);

        $redemption = DB::transaction(function () use ($entitlement, $data, $cpf, $variacao) {
            // Trava a autorização para impedir resgate duplo concorrente
            $locked = ProjectEntitlement::lockForUpdate()->findOrFail($entitlement->id);

            if ($locked->isRedeemed()) {
                throw new DomainException('Este kit já foi resgatado.');
            }

            // Grupo de escolha: trava TODAS as irmãs antes de conferir, senão
            // dois cliques em opções diferentes do mesmo grupo passam juntos
            // (mesmo padrão de corrida do resgate duplo acima).
            if ($locked->choice_group !== null) {
                ProjectEntitlement::lockForUpdate()
                    ->where('choice_group', $locked->choice_group)
                    ->get();

                if ($locked->choiceGroupConsumed()) {
                    throw new DomainException('Você já escolheu seu brinde neste projeto.');
                }
            }

            // A baixa é da WeLog e acontece AQUI, na mesma transação e com o
            // saldo travado: dois resgates simultâneos leriam o mesmo número e
            // os dois passariam, entregando mais kits do que o Projeto tem.
            $this->ledger->debitar($locked->project, $locked->product_id);

            return Redemption::create([
                'project_id' => $locked->project_id,
                'user_id' => $locked->user_id,
                'product_id' => $locked->product_id,
                'status' => Redemption::STATUS_CONFIRMED,
                'recipient_name' => $data['recipient_name'],
                // Só dígitos no banco; a máscara é apresentação (cpf_masked)
                'cpf' => $cpf?->digitos,
                'zip' => preg_replace('/\D/', '', $data['zip']),
                'street' => $data['street'],
                'number' => $data['number'],
                'complement' => $data['complement'] ?? null,
                'district' => $data['district'],
                'city' => $data['city'],
                'state' => $data['state'],
                'notes' => $data['notes'] ?? null,
                // Cor/tamanho escolhidos, em cópia: é o que a produção soma.
                // O saldo debitado acima é o do pai — a variação não tem
                // estoque, é feita depois da escolha.
                'variant_sku' => $variacao?->sku,
                'variant_color' => $variacao?->color,
                'variant_size' => $variacao?->size,
            ]);
        });

        // A Omie NÃO recebe a baixa: o estoque do Projeto é da WeLog, e o
        // número de lá é do produto inteiro, somado entre os projetos que usam
        // o mesmo kit. Empurrar a saída para lá mexia num saldo que não é
        // deste Projeto e ainda fazia a confirmação depender de a Omie estar
        // no ar para um resgate que a plataforma já tinha aceitado.

        NotificaColaborador::enfileirar($actor, new RedemptionConfirmedMail($redemption));

        return $redemption;
    }

    /**
     * A opção de cor/tamanho que a pessoa escolheu, conferida contra as
     * opções do pai (parent code) — um id trocado no formulário não pode
     * gravar a variação de outro produto. Sem opções, não há o que escolher.
     */
    private function variacaoEscolhida(ProjectEntitlement $entitlement, mixed $skuId): ?OpcaoDeVariacao
    {
        $opcoes = $entitlement->product->opcoesDeVariacao();

        if ($opcoes->isEmpty()) {
            return null;
        }

        if (blank($skuId)) {
            throw new DomainException('Escolha a cor e o tamanho antes de confirmar.');
        }

        return $opcoes->firstWhere('id', (int) $skuId)
            ?? throw new DomainException('Essa opção não está disponível neste presente. Escolha outra.');
    }
}

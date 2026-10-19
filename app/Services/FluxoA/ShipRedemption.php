<?php

namespace App\Services\FluxoA;

use App\Contracts\Integrations\CorreiosGateway;
use App\Exceptions\DomainException;
use App\Mail\NotificaColaborador;
use App\Mail\ShipmentUpdateMail;
use App\Models\Carrier;
use App\Models\EmailTemplate;
use App\Models\Project;
use App\Models\Redemption;
use App\Models\User;

/**
 * Fluxo A, etapas 11-12: o operador WeGift define a transportadora do
 * resgate.
 *
 * O que ele precisa informar sai do cadastro da transportadora, não de uma
 * lista fixa aqui: quem entrega CÓDIGO pede o código, quem entrega LINK pede
 * a URL pronta. Os Correios são a exceção com integração — com a prepostagem
 * ligada, o código nasce da API e ninguém digita nada.
 */
class ShipRedemption
{
    public function __construct(private readonly CorreiosGateway $correios) {}

    public function handle(
        Redemption $redemption,
        Carrier|string $carrier,
        User $actor,
        ?string $manualTrackingCode = null,
        ?string $trackingUrl = null,
    ): Redemption {
        if (! $actor->canPerformCriticalActions()) {
            throw new DomainException('Este perfil não tem permissão para expedir resgates.');
        }

        if ($redemption->status !== Redemption::STATUS_CONFIRMED) {
            throw new DomainException('Só resgates confirmados podem ser expedidos.');
        }

        $carrier = $this->resolver($carrier);

        if ($carrier->usesLink()) {
            // Sem código a obter: a transportadora entrega o acompanhamento
            // pronto, e o que falta é o endereço dele.
            $trackingCode = null;

            if (($trackingUrl = trim((string) $trackingUrl)) === '') {
                throw new DomainException($carrier->name.' exige o link de rastreio.');
            }
        } else {
            $trackingUrl = null;

            $trackingCode = $carrier->isCorreios() && config('integrations.correios.prepostagem_enabled')
                ? $this->correios->createPrePostagem($this->prePostagemPayload($redemption))['tracking_code']
                // Sem prepostagem habilitada, Correios também aceita código manual
                : $manualTrackingCode;

            // Campo em branco é ausência de código, não um código vazio: sem
            // isto a tela mostraria um rastreio de string vazia.
            $trackingCode = trim((string) $trackingCode) ?: null;

            /*
             * Correios fora da prepostagem é o único caso em que expedir sem
             * código é legítimo: o robô de rastreio descobre depois. Para as
             * demais, código vazio é um resgate que ninguém consegue
             * acompanhar, e não existe outra tela para informá-lo depois.
             */
            if (! $carrier->isCorreios() && $trackingCode === null) {
                throw new DomainException($carrier->name.' exige o código de rastreio.');
            }
        }

        $redemption->update([
            'carrier' => $carrier->slug,
            'tracking_code' => $trackingCode,
            'tracking_url' => $trackingUrl,
            'status' => Redemption::STATUS_SHIPPED,
            'shipped_at' => now(),
        ]);

        NotificaColaborador::enfileirar($redemption->user, new ShipmentUpdateMail(
            $redemption,
            'Seu pedido foi expedido!',
            // "Acompanhe pelo código de rastreio" não servia para quem recebeu
            // um link, e é a maioria das transportadoras sem API.
            'Seu presente saiu para entrega pela '.$carrier->name.'. Acompanhe pelo rastreio abaixo.',
            EmailTemplate::KEY_REDEMPTION_SHIPPED,
        ));

        $this->moverParaEnvio($redemption->project);

        return $redemption;
    }

    /**
     * O slug ainda chega do formulário; a regra de expedição trabalha com a
     * transportadora inteira. Inativa não expede: se ela sumiu da tela e
     * alguém ainda tinha o drawer aberto, o servidor recusa em vez de gravar
     * um contrato encerrado.
     */
    private function resolver(Carrier|string $carrier): Carrier
    {
        if ($carrier instanceof Carrier) {
            return $carrier;
        }

        $encontrada = Carrier::where('slug', $carrier)->first();

        if ($encontrada === null) {
            throw new DomainException('Transportadora não cadastrada.');
        }

        if (! $encontrada->active) {
            throw new DomainException($encontrada->name.' está desativada e não pode receber novas expedições.');
        }

        return $encontrada;
    }

    /**
     * Primeiro kit na rua move o Projeto para "Envio".
     *
     * O Trello governa três movimentações (Design, Produção e Montagem) e o
     * resto da esteira é consequência de fato, não de card arrastado: "Em
     * Estoque" nasce do saldo da Omie e "Envio" nasce daqui — expedir é,
     * literalmente, o kit saindo. Sem este gatilho, "Envio" só existiria pelo
     * ajuste manual, e uma etapa que depende de alguém lembrar de clicar não
     * descreve a operação.
     *
     * Só avança de "Em Estoque": Projeto que regrediu por falta de saldo não
     * é puxado para a frente pela expedição de um resgate antigo, e
     * "Concluído" não volta.
     */
    private function moverParaEnvio(Project $project): void
    {
        if ($project->pipeline_status !== Project::STATUS_IN_STOCK) {
            return;
        }

        $project->update(['pipeline_status' => Project::STATUS_SHIPPING]);
    }

    private function prePostagemPayload(Redemption $redemption): array
    {
        return [
            'destinatario' => [
                'nome' => $redemption->recipient_name,
                'endereco' => [
                    'cep' => $redemption->zip,
                    'logradouro' => $redemption->street,
                    'numero' => $redemption->number,
                    'complemento' => $redemption->complement,
                    'bairro' => $redemption->district,
                    'cidade' => $redemption->city,
                    'uf' => $redemption->state,
                ],
            ],
            'codigoServico' => config('integrations.correios.codigo_servico'),
        ];
    }
}

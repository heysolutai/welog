<?php

namespace App\Integrations\Support;

/**
 * Status canônico de rastreio da WeLog.
 *
 * Cada transportadora tem seu próprio vocabulário (os Correios devolvem
 * códigos SRO como `BDE`/`OEC`; a Flash, rótulos próprios). A normalização
 * acontece **no gateway**, para que o restante do sistema — jobs, e-mails de
 * status e a timeline do portal — dependa só destes valores.
 *
 * Os valores são os mesmos que os gateways `fake` já usavam, então os eventos
 * já gravados continuam válidos.
 */
enum TrackingStatus: string
{
    case Posted = 'postado';
    case InTransit = 'em_transito';
    case OutForDelivery = 'saiu_para_entrega';
    case Delivered = 'entregue';
    case DeliveryFailed = 'entrega_nao_efetuada';
    case Other = 'evento';

    /**
     * Mapa dos eventos SRO dos Correios (API Rastro/CWS).
     *
     * Entrega é sinalizada por `BDE`/`BDI`/`BDR` **com tipo `01`** — os demais
     * tipos do mesmo código são tentativas frustradas ("destinatário ausente",
     * "endereço incorreto"), que não podem marcar o resgate como entregue.
     */
    public static function fromCorreios(?string $codigo, ?string $tipo = null): self
    {
        $codigo = strtoupper(trim((string) $codigo));

        if (in_array($codigo, ['BDE', 'BDI', 'BDR'], true)) {
            return trim((string) $tipo) === '01' ? self::Delivered : self::DeliveryFailed;
        }

        return match ($codigo) {
            'PO', 'PAR' => self::Posted,
            'RO', 'DO', 'TRI', 'CO' => self::InTransit,
            'OEC', 'LDE' => self::OutForDelivery,
            default => self::Other,
        };
    }

    /**
     * Normaliza o status devolvido pela Flash. A especificação da API ainda
     * será fornecida pelo fornecedor (ver `FlashApiGateway`), então aceitamos
     * as grafias usuais em pt-BR e en e caímos em `Other` no que não conhecemos
     * — nunca em `Delivered`, para não fechar um pedido por engano.
     */
    public static function fromFlash(?string $status): self
    {
        $normalized = str_replace([' ', '-'], '_', strtolower(trim((string) $status)));

        return match ($normalized) {
            'entregue', 'delivered' => self::Delivered,
            'coletado', 'postado', 'collected', 'picked_up' => self::Posted,
            'em_transito', 'in_transit', 'transit' => self::InTransit,
            'saiu_para_entrega', 'out_for_delivery' => self::OutForDelivery,
            'nao_entregue', 'entrega_nao_efetuada', 'delivery_failed', 'failed' => self::DeliveryFailed,
            default => self::tryFrom($normalized) ?? self::Other,
        };
    }

    /**
     * Eventos da API de rastreio Flash (layout de eventos Flash, `eventoId`).
     *
     * A documentação lista só os códigos abaixo. Entrega é EXCLUSIVAMENTE o
     * 4300: qualquer código fora da lista vira `Other` e aparece na timeline
     * com a descrição da Flash, sem nunca fechar o pedido como entregue.
     * Código novo descoberto em produção entra aqui, com o teste junto.
     */
    public static function fromFlashEvento(?string $eventoId): self
    {
        return match (trim((string) $eventoId)) {
            '1400' => self::Posted,              // Postado
            '2000' => self::InTransit,           // Enviado para unidade entregadora
            '4100' => self::OutForDelivery,      // Em rota de entrega
            '4300' => self::Delivered,           // Entrega efetuada com sucesso
            default => self::Other,              // inclui 1100 — aguardando postagem
        };
    }

    public function isDelivered(): bool
    {
        return $this === self::Delivered;
    }
}

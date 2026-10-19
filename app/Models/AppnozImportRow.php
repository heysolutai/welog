<?php

namespace App\Models;

use App\Jobs\FluxoB\SendPendingFlashShipments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Linha da importação appnoz — também é o registro do pedido dela decorrente
 * (Omie, Trello, NF e expedição Flash) ao longo de todo o ciclo do Fluxo B.
 */
class AppnozImportRow extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_BLOCKED_STRUCTURAL = 'blocked_structural';

    public const STATUS_BLOCKED_PRODUCT = 'blocked_product';

    public const STATUS_ORDER_CREATED = 'order_created';

    public const STATUS_INVOICED = 'invoiced';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_DELIVERED = 'delivered';

    public const BLOCKED_STATUSES = [
        self::STATUS_BLOCKED_STRUCTURAL,
        self::STATUS_BLOCKED_PRODUCT,
    ];

    protected $fillable = [
        'appnoz_import_id', 'row_number', 'external_id',
        'cpf', 'customer_name', 'email', 'phone',
        'product_code', 'product_name', 'quantity',
        'zip', 'street', 'number', 'complement', 'district', 'city', 'state',
        'status', 'block_reason', 'benefit_code',
        'omie_customer_code', 'customer_auto_created',
        'customer_divergences', 'divergence_resolved_at', 'divergence_resolution',
        'omie_order_id', 'omie_order_number', 'trello_card_id', 'trello_stage',
        // `nf_xml_sent_at` segue na tabela só como histórico: o envio de XML à
        // Flash deixou de existir (a Flash recebe a nota por fora).
        'nf_key', 'nf_simulated', 'nf_issued_at', 'tracking_code', 'delivered_at',
        'flash_sent_at', 'flash_error',
    ];

    protected function casts(): array
    {
        return [
            'customer_auto_created' => 'boolean',
            'nf_simulated' => 'boolean',
            'customer_divergences' => 'array',
            'divergence_resolved_at' => 'datetime',
            'nf_issued_at' => 'datetime',
            'flash_sent_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(AppnozImport::class, 'appnoz_import_id');
    }

    public function trackingEvents(): MorphMany
    {
        return $this->morphMany(TrackingEvent::class, 'trackable')->orderByDesc('occurred_at');
    }

    public function isBlocked(): bool
    {
        return in_array($this->status, self::BLOCKED_STATUSES, true);
    }

    /**
     * Recebido e ainda sem pedido, há mais tempo do que o processamento leva.
     *
     * O normal é virar pedido em segundos. Passado isso, a Omie recusou no
     * meio (consumo redundante, fora do ar) e a fila está tentando de novo —
     * ou já desistiu. Nos dois casos o operador pode destravar na mão.
     */
    public function isStuck(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->created_at !== null
            && $this->created_at->lt(now()->subMinutes(2));
    }

    /**
     * Grava a NF emitida para o pedido deste resgate.
     *
     * Um lugar só para as três portas por onde a nota chega: o webhook
     * NFe.NotaAutorizada, o botão "Buscar NF na Omie" e a varredura de reserva.
     * Nota de verdade substitui a simulada; só avança quem esperava a nota —
     * em transporte ou entregue, não volta.
     */
    public function registrarNota(string $chave, ?\DateTimeInterface $emitidaEm = null): void
    {
        $this->update([
            'nf_key' => $chave,
            'nf_simulated' => false,
            'nf_issued_at' => $emitidaEm ?? now(),
            'status' => $this->status === self::STATUS_ORDER_CREATED ? self::STATUS_INVOICED : $this->status,
        ]);

        // Com a nota, o resgate vira encomenda na Flash. O atraso junta as
        // notas que chegam em rajada num lote só (o job é único na fila).
        if ($this->status === self::STATUS_INVOICED && config('integrations.flash.import.enabled')) {
            SendPendingFlashShipments::dispatch()->delay(now()->addSeconds(30));
        }
    }

    /**
     * Número da NF, tirado da chave de acesso e sem os zeros à esquerda.
     *
     * A chave carrega o número nas posições 26 a 34 (cUF, AAMM, CNPJ, modelo
     * e série vêm antes). É com ele que a Flash conhece a encomenda
     * (WEBOX44883), e tirá-lo da chave poupa uma consulta à Omie por nota.
     */
    public function nfNumber(): ?string
    {
        $chave = preg_replace('/\D/', '', (string) $this->nf_key);

        if (strlen($chave) !== 44) {
            return null;
        }

        $numero = ltrim(substr($chave, 25, 9), '0');

        return $numero === '' ? null : $numero;
    }

    /**
     * Chave de NF fictícia desta linha, para o `welog:nf:simular`.
     *
     * 44 dígitos como uma chave de verdade, começando por 99 — código de UF
     * que não existe, então nunca colide com uma nota real. O id da linha vai
     * na posição do número da NF, para o rastreio por número (WEBOX…) achar a
     * nota simulada no driver fake da Flash.
     */
    public function simulatedNfKey(): string
    {
        return '99'.str_repeat('0', 23).str_pad((string) $this->id, 9, '0', STR_PAD_LEFT).str_repeat('0', 10);
    }

    /** Chave de idempotência do pedido na Omie (reprocessar não duplica). */
    public function omieIntegrationKey(): string
    {
        return "appnoz-{$this->appnoz_import_id}-{$this->row_number}";
    }
}

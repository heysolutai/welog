<?php

namespace App\Models;

use App\Support\Values\Cpf;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Redemption extends Model
{
    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CANCELED = 'canceled';

    /*
     * A transportadora é cadastro (`carriers`), não constante: o que fica
     * gravado aqui é o slug dela. MRB e DDM tinham constantes próprias e uma
     * lista de "sem API" — contratar a quarta transportadora obrigava a editar
     * o código, e é exatamente isso que a tabela desfez.
     *
     * Correios continua nomeada porque é a única com integração: a prepostagem
     * gera o código e o robô de rastreio consulta a API dela.
     */
    public const CARRIER_CORREIOS = 'correios';

    protected $fillable = [
        'project_id', 'user_id', 'product_id', 'status', 'quantity', 'requested_by',
        'recipient_name', 'cpf', 'zip', 'street', 'number', 'complement', 'district', 'city', 'state',
        'notes', 'carrier', 'tracking_code', 'tracking_url', 'shipped_at', 'delivered_at',
        'stock_pushed_at', 'stock_returned_at',
        // Variação escolhida pelo colaborador (pelo parent code), em cópia.
        'variant_sku', 'variant_color', 'variant_size',
    ];

    protected function casts(): array
    {
        return [
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            // Carimbo de idempotência da baixa de estoque na Omie
            'stock_pushed_at' => 'datetime',
            'stock_returned_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Admin que abriu a solicitação (Admin Cliente, Admin Projeto ou WeGift
     * crítico). Nulo = resgate individual feito pelo próprio Usuário no portal.
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** Solicitação de admin (N unidades), e não resgate individual do portal. */
    public function isAdminRequest(): bool
    {
        return $this->requested_by !== null;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function trackingEvents(): MorphMany
    {
        return $this->morphMany(TrackingEvent::class, 'trackable')->orderByDesc('occurred_at');
    }

    /**
     * A transportadora cadastrada, ligada pelo slug que ficou gravado aqui.
     *
     * Relação pelo slug, e não por um `carrier_id`: é o slug que os resgates
     * já expedidos guardam, e trocar a chave obrigaria a reescrever todos eles
     * — com o risco de perder quem entregou o que.
     */
    public function carrierRecord(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'carrier', 'slug');
    }

    /**
     * Para onde o rastreio leva, se leva a algum lugar.
     *
     * Transportadora de link grava a URL pronta; a de código ganha o link
     * montado pelo molde do cadastro. Sem molde, não há link — e é melhor
     * mostrar só o código do que um endereço que não abre nada.
     */
    public function trackingLink(): ?string
    {
        return $this->tracking_url ?: $this->carrierRecord?->trackingUrlFor($this->tracking_code);
    }

    /** "Preto · M" — a cor e o tamanho escolhidos, ou null sem variação. */
    public function rotuloDaVariacao(): ?string
    {
        $partes = array_filter([$this->variant_color, $this->variant_size], 'filled');

        return $partes === [] ? null : implode(' · ', $partes);
    }

    /**
     * O que a pessoa recebe, como os e-mails chamam: "Polo (Preto · M)", ou
     * só o nome do produto sem variação.
     */
    public function nomeDoPresente(): string
    {
        $variacao = $this->rotuloDaVariacao();

        return $this->product->name.($variacao !== null ? " ({$variacao})" : '');
    }

    /**
     * As variáveis que todo e-mail deste resgate entende: quem, o quê, de qual
     * empresa e para onde. Confirmação, expedição e entrega partem daqui; sem
     * um ponto só, cada e-mail esquecia uma e o `{produto}` saía cru.
     *
     * @return array<string, string>
     */
    public function dadosDoEmail(): array
    {
        return [
            'nome' => (string) $this->user->name,
            'produto' => $this->nomeDoPresente(),
            'empresa' => (string) $this->project?->company?->label,
            'endereco' => $this->full_address,
        ];
    }

    /**
     * CPF com máscara (000.000.000-00) para exibição. O banco guarda só os 11
     * dígitos; a máscara é regra de apresentação e vive num lugar só.
     */
    protected function cpfMasked(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->cpf
            ? Cpf::doBanco($this->cpf)->formatado()
            : null);
    }

    /** Endereço resumido, para listagens: "Rua X, 100 - Curitiba/PR". */
    protected function shortAddress(): Attribute
    {
        return Attribute::get(fn () => "{$this->street}, {$this->number} - {$this->city}/{$this->state}");
    }

    /** Endereço completo, para o e-mail de confirmação e conferência. */
    protected function fullAddress(): Attribute
    {
        return Attribute::get(fn () => trim(
            "{$this->street}, {$this->number}"
            .($this->complement ? ", {$this->complement}" : '')
            .", {$this->district}, {$this->city}/{$this->state}"
            .($this->zip ? ', CEP '.preg_replace('/^(\d{5})(\d{3})$/', '$1-$2', $this->zip) : '')
        ));
    }
}

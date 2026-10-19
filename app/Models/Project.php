<?php

namespace App\Models;

use App\Observers\ProjectObserver;
use App\Support\Projects\ProjectTemplate;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

// O observer é o ponto único do aviso de mudança de etapa: as três origens
// (webhook Trello, ajuste manual e sinal de estoque da Omie) mudam o
// pipeline_status via `$project->update()`, então o evento do model cobre todas.
#[ObservedBy(ProjectObserver::class)]
class Project extends Model
{
    public const STATUS_DESIGN = 'design';

    public const STATUS_PRODUCTION = 'production';

    public const STATUS_ASSEMBLY = 'assembly';

    public const STATUS_IN_STOCK = 'in_stock';

    public const STATUS_SHIPPING = 'shipping';

    public const STATUS_DONE = 'done';

    /**
     * A esteira na ordem em que a operação trabalha. "Em Estoque" vem ANTES de
     * "Envio": o kit precisa existir no estoque para ser enviado — Envio é a
     * saída para as pessoas, não a chegada à WeGift.
     */
    public const PIPELINE_STATUSES = [
        self::STATUS_DESIGN, self::STATUS_PRODUCTION, self::STATUS_ASSEMBLY,
        self::STATUS_IN_STOCK, self::STATUS_SHIPPING, self::STATUS_DONE,
    ];

    /**
     * Onde o Trello manda: as TRÊS movimentações do quadro da produção.
     *
     * O que vem depois não é card arrastado: "Em Estoque" nasce do sinal de
     * estoque da Omie, "Envio" da expedição dos resgates e "Concluído" do fim
     * das entregas. Mapear uma lista do board para qualquer um dos três
     * deixaria duas fontes disputando o mesmo campo.
     */
    public const TRELLO_STATUSES = [
        self::STATUS_DESIGN, self::STATUS_PRODUCTION, self::STATUS_ASSEMBLY,
    ];

    /**
     * Etapas em que o kit AINDA NÃO existe no estoque — é daqui que o sinal de
     * estoque da Omie promove para "Em Estoque". De lá em diante (Envio,
     * Concluído) o saldo na Omie não move mais nada: seria regressão.
     */
    public const PRE_STOCK_STATUSES = [
        self::STATUS_DESIGN, self::STATUS_PRODUCTION, self::STATUS_ASSEMBLY,
    ];

    /**
     * Etapas em que o kit existe fisicamente e o resgate pode estar aberto:
     * "Em Estoque" (a trava que abre) e "Envio" (os kits já saindo — quem
     * ainda não resgatou continua podendo). "Concluído" fecha.
     */
    public const STOCKED_STATUSES = [
        self::STATUS_IN_STOCK, self::STATUS_SHIPPING,
    ];

    /** Rótulos das etapas para mensagens e e-mails (espelho de `pipelineLabels` no front). */
    public const STAGE_LABELS = [
        self::STATUS_DESIGN => 'Design',
        self::STATUS_PRODUCTION => 'Produção',
        self::STATUS_ASSEMBLY => 'Montagem',
        self::STATUS_IN_STOCK => 'Estoque: pronto para resgate/envio',
        self::STATUS_SHIPPING => 'Envio',
        self::STATUS_DONE => 'Concluído',
    ];

    /**
     * Os textos da tela de resgate que a campanha pode trocar, com o padrão
     * de cada um (exemplo no campo; {empresa} e {n} são preenchidos pela
     * tela) e o tamanho máximo: são títulos, e texto comprido quebra a tela
     * do celular.
     */
    public const PORTAL_TEXTS = [
        'saudacao' => [
            'label' => 'Saudação',
            'padrao' => 'A {empresa} preparou um presente para você.',
            'max' => 140,
        ],
        'escolha' => [
            'label' => 'Chamada da escolha',
            'padrao' => 'Escolha 1 destas {n} opções',
            'max' => 80,
        ],
        'entregas' => [
            'label' => 'Título da página de entregas',
            'padrao' => 'Entregas',
            'max' => 60,
        ],
    ];

    /** Tamanho máximo do nome e da descrição de um item no portal. */
    public const PORTAL_ITEM_NOME_MAX = 120;

    public const PORTAL_ITEM_DESCRICAO_MAX = 600;

    protected $fillable = [
        'company_id', 'name', 'omie_order_id', 'omie_order_number', 'client_order_number',
        'omie_account', 'template', 'logo_path', 'quantity', 'delivery_deadline', 'ordered_at', 'details_edited_at',
        'pipeline_status', 'trello_card_id', 'trello_card_url',
        'stock_available_at', 'released_at', 'released_by', 'notes',
        'redemptions_blocked_at', 'redemptions_blocked_by', 'redemptions_block_reason',
        'portal_texts', 'removed_items',
    ];

    protected function casts(): array
    {
        return [
            'delivery_deadline' => 'date',
            // Data de inclusão do pedido na Omie ("Data da compra" na tela).
            'ordered_at' => 'date',
            // Nome/prazo editados à mão — a sincronização Omie não sobrescreve.
            'details_edited_at' => 'datetime',
            'stock_available_at' => 'datetime',
            'released_at' => 'datetime',
            'redemptions_blocked_at' => 'datetime',
            'portal_texts' => 'array',
            'removed_items' => 'array',
        ];
    }

    /**
     * URL da logo da campanha, ou null.
     *
     * Montada na hora a partir do caminho: o disco `public` resolve pelo
     * APP_URL do ambiente, então a mesma linha do banco serve dev e produção.
     */
    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->logo_path
            ? Storage::disk('public')->url($this->logo_path)
            : null);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'project_products')
            ->withPivot('quantity', 'stock_qty', 'stock_loaded_at');
    }

    /**
     * Carrega o saldo do Projeto com o que o pedido pede.
     *
     * Acontece uma vez, quando o kit entra em estoque: a partir daí o número é
     * da WeLog e só muda por resgate ou por ajuste na plataforma. Recarregar a
     * cada reavaliação de saldo desfaria as baixas — o Projeto voltaria a ter
     * 100 unidades depois de 60 resgates.
     */
    public function carregarEstoqueDoProjeto(): void
    {
        foreach ($this->products as $produto) {
            if ($produto->pivot->stock_loaded_at !== null) {
                continue;
            }

            $this->products()->updateExistingPivot($produto->id, [
                'stock_qty' => $produto->pivot->quantity,
                'stock_loaded_at' => now(),
            ]);
        }
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(ProjectEntitlement::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(Redemption::class);
    }

    /**
     * Os textos da tela de resgate que esta campanha trocou (saudação,
     * chamada da escolha, título das entregas). Só os preenchidos: o que não
     * vier aqui, a tela escreve o padrão.
     *
     * @return array<string, string>
     */
    public function textosDoPortal(): array
    {
        $gravados = array_intersect_key((array) ($this->portal_texts ?? []), self::PORTAL_TEXTS);

        return array_filter(
            array_map(fn ($t) => trim((string) $t), $gravados),
            fn (string $t) => $t !== '',
        );
    }

    /**
     * O nome e a descrição de um item como o colaborador os lê nesta
     * campanha, pelo código do item principal (o pai, num tamanho de grade).
     * Vazio = o nome do catálogo e nenhuma descrição própria.
     *
     * @return array{nome?: string, descricao?: string}
     */
    public function textoDoItem(?string $codigoDoPrincipal): array
    {
        $item = (array) (($this->portal_texts['itens'] ?? [])[mb_strtolower((string) $codigoDoPrincipal)] ?? []);

        return array_filter(
            ['nome' => trim((string) ($item['nome'] ?? '')), 'descricao' => trim((string) ($item['descricao'] ?? ''))],
            fn (string $t) => $t !== '',
        );
    }

    /**
     * Admins de Projeto (papel project_admin) vinculados a este Projeto — são
     * eles que recebem o aviso de mudança de etapa. O filtro por papel protege
     * contra um admin_project_id que tenha sobrado em conta de outro papel.
     */
    public function projectAdmins(): HasMany
    {
        return $this->hasMany(User::class, 'admin_project_id')
            ->where('role', User::ROLE_PROJECT_ADMIN);
    }

    /** Rótulo da etapa atual, para textos de tela e e-mail. */
    public function stageLabel(): string
    {
        return self::STAGE_LABELS[$this->pipeline_status] ?? (string) $this->pipeline_status;
    }

    public function hasStockAvailable(): bool
    {
        return $this->stock_available_at !== null;
    }

    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }

    /**
     * Ver Company::entradaPeloPrimeiroAcesso. Projeto sem Cliente vinculado
     * responde que não: a ausência de cadastro não é escolha de ninguém, e a
     * WeLog avisa a pessoa.
     */
    public function entradaPeloPrimeiroAcesso(): bool
    {
        return $this->company?->entradaPeloPrimeiroAcesso() ?? false;
    }

    /**
     * O kit existe fisicamente: "Em Estoque" ou "Envio". É a condição para o
     * resgate abrir e continuar aberto — sair daqui (card volta para Montagem,
     * ou Projeto concluído) fecha o portal sozinho.
     */
    public function isInStock(): bool
    {
        return in_array($this->pipeline_status, self::STOCKED_STATUSES, true);
    }

    /** Ainda antes do estoque: é onde o sinal da Omie tem efeito. */
    public function isBeforeStock(): bool
    {
        return in_array($this->pipeline_status, self::PRE_STOCK_STATUSES, true);
    }

    /**
     * Dupla trava: o Projeto precisa ter chegado a "Em Estoque" (o kit existe
     * fisicamente) E alguém da WeGift precisa liberar. A segunda metade é
     * exigência do Anexo I — a liberação é ação crítica, registrada com autor
     * e hora; o estágio sozinho não diz quem autorizou.
     */
    public function canBeReleased(): bool
    {
        return $this->isInStock() && ! $this->isReleased() && ! $this->isRedemptionBlocked();
    }

    /**
     * Freio de mão: os resgates deste Projeto estão suspensos.
     *
     * Vale mesmo com o Projeto liberado e em estoque — é o caso de uso: o
     * cliente percebeu endereço errado em massa, ou o kit saiu com defeito, e
     * precisa parar a fila AGORA sem esperar a WeGift. Por isso qualquer admin
     * bloqueia, e só a WeGift solta (`ProjectRedemptionBlock`).
     */
    public function isRedemptionBlocked(): bool
    {
        return $this->redemptions_blocked_at !== null;
    }

    /**
     * Tipo da campanha (Evento, Com estoque, Amostra…), que define o template
     * do card no Trello. Null = projeto Omie sem template mapeado.
     *
     * O nome não é `template()` para não colidir com a coluna `template`, que
     * guarda o valor cru.
     */
    public function templateType(): ?ProjectTemplate
    {
        return ProjectTemplate::tryFrom((string) $this->template);
    }

    /** Quem puxou o freio — a tela mostra o nome junto do motivo. */
    public function blockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redemptions_blocked_by');
    }
}

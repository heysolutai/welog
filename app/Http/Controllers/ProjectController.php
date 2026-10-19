<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Integrations\Omie\OmieAccounts;
use App\Jobs\FluxoA\ImportEntitlementRows;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\EntitlementImport;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use App\Rules\ValorValido;
use App\Services\Auth\InviteUser;
use App\Services\FluxoA\BatchRegisterEndCustomers;
use App\Services\FluxoA\BlockProjectRedemptions;
use App\Services\FluxoA\CreateTrelloCardForProject;
use App\Services\FluxoA\HandleOmieStockSignal;
use App\Services\FluxoA\RegisterClientAdmin;
use App\Services\FluxoA\RegisterEndCustomer;
use App\Services\FluxoA\ReleaseProject;
use App\Services\FluxoA\RemoveProjectItems;
use App\Services\FluxoA\ValidateEntitlementSheet;
use App\Services\Projects\ProjectPurger;
use App\Services\Branding\StoreLogo;
use App\Support\Auditoria;
use App\Support\Values\Cnpj;
use App\Support\Variacoes;
use App\Support\Values\Cpf;
use App\Support\Values\Telefone;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Inertia\Support\Header as InertiaHeader;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProjectController extends Controller
{
    /** Rótulos dos estágios para mensagens — espelho de `pipelineLabels` no front. */
    private const STAGE_LABELS = [
        Project::STATUS_DESIGN => 'Design',
        Project::STATUS_PRODUCTION => 'Produção',
        Project::STATUS_ASSEMBLY => 'Montagem',
        Project::STATUS_IN_STOCK => 'Estoque Disponível',
        Project::STATUS_SHIPPING => 'Envio',
        Project::STATUS_DONE => 'Concluído',
    ];

    public function index(Request $request): Response
    {
        // `with('products')`: a lista passou a mostrar o kit (requisito 1) e
        // sem o eager load seria uma query por linha.
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'company' => ['nullable', 'integer', 'exists:companies,id'],
            'period' => ['nullable', 'in:this_month,next_30,overdue'],
            'stage' => ['nullable', 'in:'.implode(',', Project::PIPELINE_STATUSES)],
            'situation' => ['nullable', 'in:producing,ready,released'],
            'account' => ['nullable', 'string', 'max:40'],
        ]);

        $scope = Project::query()
            ->when($request->user()->isClientAdmin(), fn ($q) => $q->where('company_id', $request->user()->company_id))
            // Admin Projeto (UAT): a lista dele é o Projeto dele, e mais nada.
            ->when($request->user()->isProjectAdmin(), fn ($q) => $q->whereKey($request->user()->admin_project_id));
        $total = (clone $scope)->count();
        $stats = [
            'total' => $total,
            'production' => (clone $scope)->whereIn('pipeline_status', Project::PRE_STOCK_STATUSES)->count(),
            'ready' => (clone $scope)->where('pipeline_status', Project::STATUS_DONE)->count(),
            'critical' => (clone $scope)->whereDate('delivery_deadline', '<=', today())
                ->where('pipeline_status', '!=', Project::STATUS_DONE)->count(),
            'overdue' => (clone $scope)->whereDate('delivery_deadline', '<', today())
                ->where('pipeline_status', '!=', Project::STATUS_DONE)->count(),
            'due_today' => (clone $scope)->whereDate('delivery_deadline', today())
                ->where('pipeline_status', '!=', Project::STATUS_DONE)->count(),
        ];

        $accounts = app(OmieAccounts::class);
        $multiConta = $accounts->isMultiAccount();

        $projects = (clone $scope)->with('company', 'products.photos')
            ->when($filters['q'] ?? null, function ($query, $term) {
                // Case-insensitive nos dois bancos (UAT): o LIKE do Postgres
                // diferencia maiúsculas ("acme" não achava "ACME"), o do sqlite
                // dos testes não. LOWER dos dois lados nivela o comportamento;
                // colunas qualificadas porque `name` existe em três tabelas.
                $like = '%'.mb_strtolower($term).'%';
                $query->where(function ($query) use ($like) {
                    $query->whereRaw('LOWER(projects.name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(projects.omie_order_number) LIKE ?', [$like])
                        ->orWhereHas('company', fn ($q) => $q->whereRaw('LOWER(companies.name) LIKE ?', [$like]))
                        ->orWhereHas('products', fn ($q) => $q->whereRaw('LOWER(products.name) LIKE ?', [$like]));
                });
            })
            ->when($filters['company'] ?? null, fn ($q, $company) => $q->where('company_id', $company))
            // Filtro pelo CNPJ de origem (conta da Omie). Projeto antigo tem
            // conta nula: ele é da conta padrão, senão sumiria ao filtrar.
            ->when($filters['account'] ?? null, fn ($q, $conta) => $q->where(
                fn ($sub) => $sub->where('omie_account', $conta)
                    ->when(
                        $conta === app(OmieAccounts::class)->defaultName(),
                        fn ($p) => $p->orWhereNull('omie_account'),
                    )
            ))
            ->when($filters['stage'] ?? null, fn ($q, $stage) => $q->where('pipeline_status', $stage))
            ->when($filters['period'] ?? null, function ($query, $period) {
                match ($period) {
                    'this_month' => $query->whereBetween('delivery_deadline', [today()->startOfMonth(), today()->endOfMonth()]),
                    'next_30' => $query->whereBetween('delivery_deadline', [today(), today()->addDays(30)]),
                    'overdue' => $query->whereDate('delivery_deadline', '<', today()),
                };
            })
            ->when($filters['situation'] ?? null, function ($query, $situation) {
                match ($situation) {
                    'released' => $query->whereNotNull('released_at'),
                    'ready' => $query->whereNull('released_at')->whereIn('pipeline_status', Project::STOCKED_STATUSES),
                    'producing' => $query->whereNull('released_at')->whereNotIn('pipeline_status', Project::STOCKED_STATUSES),
                };
            })
            ->latest()->paginate(20)->withQueryString();

        // A foto de um tamanho de grade é a do pai: os tamanhos não têm foto.
        $principais = Product::principais($projects->getCollection()->flatMap->products);

        return Inertia::render('Projects/Index', [
            'projects' => [
                'data' => $projects->through(fn (Project $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'company' => $p->company->name,
                    'quantity' => $p->quantity,
                    'delivery_deadline' => $p->delivery_deadline,
                    'pipeline_status' => $p->pipeline_status,
                    'stock_available' => $p->hasStockAvailable(),
                    'released' => $p->isReleased(),
                    // De qual CNPJ da WeGift veio (só rotula quando há mais de um).
                    'origem' => $multiConta ? app(OmieAccounts::class)->label($p->omie_account) : null,
                    // Requisito 1: a identificação do Projeto precisa dizer
                    // QUAL kit, não só de quem é o pedido.
                    'kits' => $p->products->map(fn (Product $k) => [
                        'name' => $k->name,
                        'omie_code' => $k->omie_code,
                        'photo_url' => ($principais[$k->id] ?? $k)->photo_url,
                    ])->values(),
                ])->items(),
                'links' => $projects->linkCollection(),
                'total' => $projects->total(),
            ],
            'filters' => $filters,
            'stats' => $stats,
            'companies' => Company::query()
                // Admin do lado do cliente (geral ou de Projeto) só vê a própria empresa
                ->when($request->user()->isClientSideAdmin(), fn ($q) => $q->whereKey($request->user()->company_id))
                ->whereHas('projects')->orderBy('name')->get(['id', 'name']),
            'canCreate' => $request->user()?->canPerformCriticalActions() ?? false,
            // Vazio com um CNPJ só: a tela não mostra filtro que não separa nada.
            'omieAccounts' => $multiConta ? $accounts->options() : [],
        ]);
    }

    public function show(Request $request, Project $project): Response
    {
        // Admin do lado do cliente só acessa o que gerencia: o Admin Cliente, os
        // Projetos da empresa dele; o Admin Projeto, apenas o Projeto dele.
        if ($request->user()->isClientSideAdmin()) {
            abort_unless($request->user()->managesProject($project), 403);
        }

        /*
         * O progresso da importação, relido pela tela a cada três segundos.
         *
         * A recarga parcial só devolve `import`, mas o resto desta ação rodava
         * do mesmo jeito: todos os Colaboradores com conta e item, todos os
         * resgates, montados e jogados fora a cada volta. Num Projeto de nove
         * mil, isso disputava processador e banco com a própria importação —
         * mil linhas passaram a levar dois minutos. Aqui sai só o número.
         */
        if ($request->header(InertiaHeader::PARTIAL_COMPONENT) === 'Projects/Show'
            && $request->header(InertiaHeader::PARTIAL_ONLY) === 'import') {
            return Inertia::render('Projects/Show', ['import' => $this->importacaoEmCurso($project)]);
        }

        return Inertia::render('Projects/Show', [
            /*
             * Closures: cada prop só é montada quando a tela a pede. A busca e
             * a troca de página de uma lista recarregam só ela, e o Atualizar
             * do cabeçalho não remonta as listas.
             */
            'project' => fn () => $this->projetoParaTela($project),
            'colaboradores' => fn () => $this->paginaDeColaboradores($request, $project),
            'resgates' => fn () => $this->paginaDeResgates($request, $project),
            'em_transporte' => fn () => $this->idsEmTransporte($request, $project),
            'kits' => fn () => $project->products->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name]),
            /*
             * A importação em andamento, quando há uma.
             *
             * Planilha grande deixou de ser cadastrada na requisição do upload
             * e foi para a fila; sem isto, o operador subiria nove mil linhas,
             * receberia "recebemos" e ficaria sem saber se está andando.
             * A tela relê sozinha enquanto houver uma rodando.
             */
            'import' => $this->importacaoEmCurso($project),
            'permissions' => [
                'critical' => $request->user()?->canPerformCriticalActions() ?? false,
                'release' => $request->user() ? ReleaseProject::canRelease($request->user(), $project) : false,
                // Admin do lado do cliente (geral ou de Projeto): a tela troca a
                // esteira de produção por um bloco de situação.
                'client' => $request->user()?->isClientSideAdmin() ?? false,
                // Excluir Projeto é só do Admin WeGift (N1 não exclui).
                'admin' => $request->user()?->role === User::ROLE_ADMIN_WEGIFT,
                'manage_customers' => ($request->user()?->canPerformCriticalActions() ?? false)
                    || ($request->user()?->managesProject($project) ?? false),
                // Dar resgate a um Admin do Projeto: Admin Cliente ou WeGift
                // crítica. O Admin Projeto cadastra Admin, mas sem resgate.
                'grant_admin_redemption' => ($request->user()?->canPerformCriticalActions() ?? false)
                    || ($request->user()?->isClientAdmin() ?? false),
                // Freio de mão: bloquear é da WeGift crítica, desbloquear é só
                // do Admin WeGift. Solicitar kits segue `critical`.
                'block_redemptions' => $request->user()
                    ? BlockProjectRedemptions::canBlock($request->user(), $project)
                    : false,
                'unblock_redemptions' => $request->user()
                    ? BlockProjectRedemptions::canUnblock($request->user())
                    : false,
            ],
        ]);
    }

    /**
     * O Projeto como a tela o mostra: ficha, itens do pedido e os números das
     * listas. As listas em si (Colaboradores e Resgates) saem paginadas, em
     * props próprias: num Projeto de nove mil pessoas, mandá-las inteiras
     * eram megabytes por abertura, e cada ida e volta levava segundos.
     *
     * @return array<string, mixed>
     */
    private function projetoParaTela(Project $project): array
    {
        $project->load([
            // Três níveis de estrutura: kit de campanha → kit de item → peça,
            // o mesmo teto que o espelho (`MirrorOmieProduct`) percorre na
            // Omie. Carregar de uma vez evita uma query por ramo na hora de
            // montar a árvore da aba de itens.
            'company', 'blockedBy', 'products.components.components.components', 'products.photos',
            // Nem autorizações nem resgates entram aqui: num Projeto de nove
            // mil pessoas são dezenas de milhares de modelos. As duas listas
            // saem paginadas (paginaDeColaboradores, paginaDeResgates), e os
            // números delas, agregados no banco (resumoDasPessoas, resumoDosResgates).
            'projectAdmins',
        ]);

        // Escolhas de cor/tamanho por item, numa consulta agrupada (não uma
        // por resgate). Cancelado não conta: não vai ser produzido.
        // Resgates que travam a remoção do item (cancelado não conta).
        $resgatesAtivos = Redemption::query()
            ->where('project_id', $project->id)
            ->where('status', '!=', Redemption::STATUS_CANCELED)
            ->selectRaw('product_id, COUNT(*) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        $escolhas = DB::table('redemptions')
            ->where('project_id', $project->id)
            ->where('status', '!=', Redemption::STATUS_CANCELED)
            ->where(fn ($q) => $q->whereNotNull('variant_color')->orWhereNotNull('variant_size'))
            ->groupBy('product_id', 'variant_color', 'variant_size')
            ->selectRaw('product_id, variant_color, variant_size, SUM(COALESCE(quantity, 1)) as total')
            ->get()
            ->sortBy(fn ($l) => [mb_strtolower((string) $l->variant_color), ...Variacoes::ordemDoTamanho($l->variant_size)])
            ->groupBy('product_id')
            ->map(fn ($linhas) => $linhas->map(fn ($l) => [
                'label' => implode(' · ', array_filter([$l->variant_color, $l->variant_size])),
                'total' => (int) $l->total,
            ]));

        // O pai na grade da Omie de cada item que é tamanho (PRD11363_5).
        $paisDaGrade = Product::paisDaGrade($project->products);

        // O dono das fotos de cada item: o pai, num tamanho de grade. Os
        // tamanhos não têm galeria própria.
        $principais = Product::principais($project->products);

        // Admins deste Projeto que também resgatam (têm autorização aqui).
        $adminsQueResgatam = ProjectEntitlement::query()
            ->where('project_id', $project->id)
            ->whereIn('user_id', $project->projectAdmins->pluck('id'))
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [
            'id' => $project->id,
            'name' => $project->name,
            'company' => [
                'id' => $project->company->id,
                'name' => $project->company->name,
                'cnpj' => $project->company->cnpj,
                /*
                 * O endereço que o Cliente publica na comunicação interna
                 * dele. Aparece AQUI, e não só no cadastro do Cliente,
                 * porque a hora de entregá-lo é a hora em que as pessoas
                 * entram: quem acabou de subir a planilha de nove mil
                 * precisa do link na mesma tela, não a três cliques.
                 *
                 * Só para o Cliente que divulga a campanha ele mesmo (sem
                 * o aviso "Presente liberado" da WeLog): para os outros, o
                 * convite chega por e-mail e o link só confundiria.
                 */
                'first_access_url' => $project->company->entradaPeloPrimeiroAcesso()
                    ? $project->company->linkDePrimeiroAcesso()
                    : null,
                // Colunas que a planilha deste Cliente usa: a instrução de
                // preenchimento da tela tem de bater com o modelo baixado.
                'import_columns' => $project->company->colunasDaPlanilha(),
                // Os Admin Cliente também administram este Projeto (veem
                // todos os da empresa): a aba Admin lista os dois papéis,
                // que é onde a WeGift procura quem cuida de um Projeto.
                'admins' => $project->company->admins()->where('active', true)->orderBy('name')->get()
                    ->map(fn (User $u) => [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                        'access_pending' => ! $u->hasSetPassword(),
                    ])->values(),
                // Com os e-mails desligados ninguém é "notificado": a lista
                // de Colaboradores troca a nota por "Criado em".
                // É o aviso de liberação que "notifica": vale a etapa
                // "Presente liberado" do Estúdio, além da chave geral.
                'sends_emails' => ! $project->company->entradaPeloPrimeiroAcesso(),
            ],
            'omie_order_number' => $project->omie_order_number,
            // Nº do pedido do cliente na Omie: a UI oferece como nome do Projeto.
            'client_order_number' => $project->client_order_number,
            'quantity' => $project->quantity,
            'delivery_deadline' => $project->delivery_deadline,
            // Data de inclusão do pedido na Omie — a UI exibe "Data da compra".
            'ordered_at' => $project->ordered_at,
            'pipeline_status' => $project->pipeline_status,
            'trello_card_url' => $project->trello_card_url,
            // Nome da conta Omie (CNPJ) de onde o pedido veio: "Webox",
            // "Santa Gestão"... Aparece sempre que houver um nome de
            // verdade — com várias contas (OMIE_ACCOUNTS) ou com o rótulo
            // da conta única (OMIE_LABEL). Só o fallback genérico "Omie"
            // fica de fora: um card dizendo "Conta Omie: Omie" é ruído.
            'origem' => ($rotulo = app(OmieAccounts::class)->label($project->omie_account)) === 'Omie'
                ? null
                : $rotulo,
            'stock_available' => $project->hasStockAvailable(),
            // O estágio que abre o resgate. A tela precisa dele separado
            // de `can_be_released`, que já embute "e ainda não liberou".
            'in_stock' => $project->isInStock(),
            // Tipo da campanha (Evento, Com estoque, Amostra…): é o que o
            // quadro do Trello lê no prefixo do card.
            'template' => $project->templateType()?->label(),
            // Logo da campanha, ao lado do título. Quem decide se aparece
            // é a customização da Empresa: desligar a chave esconde a marca
            // sem APAGAR o arquivo, para religar não custar um novo upload.
            // Por isso o corte é aqui, e não no Vue — a tela não precisa
            // saber que existe uma chave.
            'logo_url' => $project->company->mostraLogoNo('projeto')
                ? $project->logo_url
                : null,
            'released' => $project->isReleased(),
            'released_at' => $project->released_at,
            'can_be_released' => $project->canBeReleased(),
            // Freio de mão: quem puxou e por quê ficam na tela, senão o
            // próximo operador só vê a fila parada sem saber de onde veio.
            'redemptions_blocked' => $project->isRedemptionBlocked(),
            'redemptions_blocked_at' => $project->redemptions_blocked_at,
            'redemptions_blocked_by' => $project->blockedBy?->name,
            'redemptions_block_reason' => $project->redemptions_block_reason,
            // Itens tirados à mão (só Admin WeGift), para restaurar.
            'removed_items' => collect($project->removed_items ?? [])
                // A chave pode virar número ("12345"): o código vai como texto.
                ->map(fn (array $i, $codigo) => [
                    'codigo' => (string) $codigo,
                    'omie_code' => $i['omie_code'] ?? (string) $codigo,
                    'name' => $i['name'] ?? (string) $codigo,
                    'quantity' => (int) ($i['quantity'] ?? 0),
                    'removed_by' => $i['removed_by'] ?? null,
                    'removed_at' => $i['removed_at'] ?? null,
                ])->values(),
            // Textos da tela de resgate desta campanha (Itens do pedido >
            // Tela de resgate): os gravados e o catálogo com o padrão de
            // cada um, para a tela não repetir o que vive no model.
            'portal_texts' => [
                'textos' => (object) $project->textosDoPortal(),
                'itens' => (object) ($project->portal_texts['itens'] ?? []),
                'catalogo' => Project::PORTAL_TEXTS,
                'item_nome_max' => Project::PORTAL_ITEM_NOME_MAX,
                'item_descricao_max' => Project::PORTAL_ITEM_DESCRICAO_MAX,
            ],
            'products' => $project->products->map(function (Product $p) use ($escolhas, $paisDaGrade, $principais, $resgatesAtivos) {
                // `pivot.quantity` é a quantidade do item NO PEDIDO Omie, já
                // absoluta. É exatamente o que o `HandleOmieStockSignal`
                // compara para liberar o Projeto — a tela tem de usar o mesmo
                // número, ou diria "falta estoque" num Projeto liberável.
                $necessario = (float) $p->pivot->quantity;
                $disponivel = (float) $p->stock_qty;

                return [
                    'id' => $p->id,
                    'omie_code' => $p->omie_code,
                    'name' => $p->name,
                    // Papel na tela: TODO registro desta lista é um item DO
                    // PEDIDO e aparece no topo — inclusive quando o mesmo
                    // código também existe no BOM de um kit (item avulso +
                    // componente são dois papéis do mesmo produto). O
                    // `is_kit` diz quais linhas ganham o recolher/expandir.
                    'is_kit' => (bool) $p->is_kit,
                    'quantity' => $p->pivot->quantity,
                    // A capa e a galeria do item principal; num tamanho de
                    // grade, as do pai (sem foto até o pai ganhar as dele).
                    'photo_url' => $principais[$p->id]->photo_url,
                    'galeria' => $principais[$p->id]->galeriaParaTela(),
                    // Com resgate, o item não sai do Projeto.
                    'resgates_ativos' => (int) ($resgatesAtivos[$p->id] ?? 0),
                    // KIT × BOM × Estoque (requisito 1.1): sem o número, o
                    // operador só sabia "tem estoque" sim/não e não
                    // conseguia dizer o quanto falta.
                    /*
                     * Três números, que respondem coisas diferentes:
                     *
                     *   required  quanto o pedido pede (Qtde Requerida)
                     *   project   quanto RESTA deste Projeto (Estoque Projeto)
                     *   available quanto existe do produto na Omie (Estoque Omie)
                     *
                     * O da Omie é do produto inteiro, somado entre todos os
                     * projetos que usam o mesmo kit — é referência, não
                     * saldo deste Projeto. O do meio é o que a operação
                     * acompanha, e é a WeLog que o move.
                     */
                    'stock' => [
                        'available' => $disponivel,
                        'required' => $necessario,
                        'project' => (float) $p->pivot->stock_qty,
                        // Carregado ≠ disponível: o saldo nasce quando o
                        // kit entra em estoque, mas só fica à disposição
                        // quando o resgate é liberado. Quem decide o que a
                        // tela mostra é a liberação; este carimbo fica
                        // para diagnóstico.
                        'loaded_at' => $p->pivot->stock_loaded_at,
                        'sufficient' => $disponivel >= $necessario,
                        'synced_at' => $p->stock_synced_at?->toIso8601String(),
                    ],
                    'components' => $this->componentTree($p),
                    // Variação (parent code): quantas pessoas escolheram cada
                    // cor/tamanho. É o número que a produção precisa — o
                    // pedido só diz "15 camisetas".
                    'variant_choices' => $escolhas->get($p->id, collect())->values(),
                    // Tamanho da grade da Omie (PRD11363_5 do pai PRD11363): o
                    // cadastro de Colaborador junta os irmãos num item só,
                    // "Colete", e a pessoa escolhe o tamanho no portal.
                    'grade' => isset($paisDaGrade[$p->id]) ? [
                        'pai_code' => $paisDaGrade[$p->id]->omie_code,
                        'pai_nome' => $paisDaGrade[$p->id]->name,
                        ...$paisDaGrade[$p->id]->rotuloNaGrade($p),
                    ] : null,
                ];
            }),
            // Os números das duas listas, para as abas, o card de situação, a
            // esteira e as confirmações. As linhas vêm paginadas à parte.
            'pessoas' => $this->resumoDasPessoas($project),
            'resgates_resumo' => $this->resumoDosResgates($project),
            // Só o número, para a confirmação de exclusão.
            'entitlements_count' => $project->entitlements()->count(),
            /*
             * Admins DESTE Projeto. Eram cadastrados e sumiam de vista: a
             * tela só listava colaborador, então não havia como conferir
             * quem administra nem reparar um convite que não foi aceito.
             */
            // Só quem tem acesso: Admin tirado do Projeto com histórico
            // fica desativado (RemoveProjectAdmin) e não é mais Admin dele.
            'admins' => $project->projectAdmins->where('active', true)->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'active' => (bool) $u->active,
                'access_pending' => ! $u->hasSetPassword(),
                'resgata' => in_array($u->id, $adminsQueResgatam, true),
            ])->values(),
        ];
    }

    /** Linhas por página nas listas da tela do Projeto. */
    private const POR_PAGINA = 25;

    /**
     * Uma página da aba Colaboradores, com a busca por nome ou e-mail.
     *
     * A ordem é a de entrada no Projeto (a primeira autorização de cada
     * pessoa), a mesma de quando a lista vinha inteira e a tela fatiava.
     *
     * @return array{data: array<int, array<string, mixed>>, total: int, pagina: int, por_pagina: int, busca: string}
     */
    private function paginaDeColaboradores(Request $request, Project $project): array
    {
        $busca = trim((string) $request->query('busca', ''));

        $pessoas = DB::table('project_entitlements as pe')
            ->join('users', 'users.id', '=', 'pe.user_id')
            ->where('pe.project_id', $project->id)
            ->when($busca !== '', fn ($q) => $this->filtrarPorNomeOuEmail($q, $busca));

        $total = (clone $pessoas)->distinct()->count('pe.user_id');
        $pagina = $this->paginaPedida($request, 'pagina', $total);

        $ids = (clone $pessoas)
            ->groupBy('pe.user_id')
            ->orderByRaw('MIN(pe.id)')
            ->forPage($pagina, self::POR_PAGINA)
            ->pluck('pe.user_id')
            ->all();

        return [
            'data' => $ids === [] ? [] : $this->colaboradoresDoProjeto($project, $ids),
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => self::POR_PAGINA,
            'busca' => $busca,
        ];
    }

    /** As letras acentuadas que a busca ignora, e a letra sem acento de cada uma. */
    private const ACENTOS = ['áàâãäéèêëíìîïóòôõöúùûüçñ', 'aaaaaeeeeiiiiooooouuuucn'];

    /**
     * Nome ou e-mail contendo o termo, sem diferenciar maiúscula nem acento:
     * quem procura "joao" tem de achar o João. No Postgres o `translate` tira
     * os acentos sem depender da extensão unaccent; o SQLite dos testes não
     * tem `translate` nem `lower` fora do ASCII, e faz o mesmo com `replace`.
     */
    private function filtrarPorNomeOuEmail($consulta, string $busca): void
    {
        $termo = '%'.addcslashes(mb_strtolower(Str::ascii($busca)), '%_\\').'%';
        [$com, $sem] = self::ACENTOS;

        $semAcento = $consulta->getConnection()->getDriverName() === 'pgsql'
            ? fn (string $coluna) => "translate(lower($coluna), '$com', '$sem')"
            : function (string $coluna) use ($com, $sem) {
                $expressao = $coluna;

                foreach (mb_str_split($com) as $i => $letra) {
                    $base = mb_substr($sem, $i, 1);
                    $expressao = "replace(replace($expressao, '$letra', '$base'), '".mb_strtoupper($letra)."', '$base')";
                }

                return "lower($expressao)";
            };

        $consulta->where(fn ($q) => $q
            ->whereRaw($semAcento('users.name')." LIKE ? ESCAPE '\\'", [$termo])
            ->orWhereRaw($semAcento('users.email')." LIKE ? ESCAPE '\\'", [$termo]));
    }

    /** A página pedida na querystring, dentro do que existe. */
    private function paginaPedida(Request $request, string $parametro, int $total): int
    {
        $ultima = max(1, (int) ceil($total / self::POR_PAGINA));

        return min(max(1, (int) $request->query($parametro, 1)), $ultima);
    }

    /**
     * Uma página da aba Resgates.
     *
     * @return array{data: Collection, total: int, pagina: int, por_pagina: int}
     */
    private function paginaDeResgates(Request $request, Project $project): array
    {
        $resgates = Redemption::query()->where('project_id', $project->id);

        $total = (clone $resgates)->count();
        $pagina = $this->paginaPedida($request, 'pagina_resgates', $total);

        return [
            'data' => $resgates
                ->with(['user', 'product'])
                ->orderBy('id')
                ->forPage($pagina, self::POR_PAGINA)
                ->get()
                ->map(fn (Redemption $r) => self::redemptionResource($r)),
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => self::POR_PAGINA,
        ];
    }

    /**
     * Os números das pessoas do Projeto, contados no banco: quantas são,
     * quantas ainda não criaram a senha, quantas já foram notificadas e
     * quantas já resgataram alguma coisa.
     *
     * @return array{total: int, acesso_pendente: int, notificadas: int, resgataram: int}
     */
    private function resumoDasPessoas(Project $project): array
    {
        $porPessoa = DB::table('project_entitlements as pe')
            ->join('users', 'users.id', '=', 'pe.user_id')
            ->where('pe.project_id', $project->id)
            ->groupBy('pe.user_id')
            ->selectRaw('pe.user_id')
            ->selectRaw('MAX(CASE WHEN users.password_set_at IS NULL THEN 1 ELSE 0 END) AS pendente')
            ->selectRaw('MAX(CASE WHEN pe.notified_at IS NOT NULL THEN 1 ELSE 0 END) AS notificada')
            // A mesma régua do withRedeemedFlag: resgate não cancelado do item.
            ->selectRaw(
                'MAX(CASE WHEN EXISTS (SELECT 1 FROM redemptions r WHERE r.project_id = pe.project_id'
                .' AND r.user_id = pe.user_id AND r.product_id = pe.product_id AND r.status <> ?)'
                .' THEN 1 ELSE 0 END) AS resgatou',
                [Redemption::STATUS_CANCELED],
            );

        $soma = DB::query()->fromSub($porPessoa, 'por_pessoa')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COALESCE(SUM(pendente), 0) AS pendentes')
            ->selectRaw('COALESCE(SUM(notificada), 0) AS notificadas')
            ->selectRaw('COALESCE(SUM(resgatou), 0) AS resgataram')
            ->first();

        return [
            'total' => (int) $soma->total,
            'acesso_pendente' => (int) $soma->pendentes,
            'notificadas' => (int) $soma->notificadas,
            'resgataram' => (int) $soma->resgataram,
        ];
    }

    /**
     * Os números dos resgates do Projeto. A esteira conta UNIDADES, não linhas:
     * uma solicitação de admin leva trinta kits numa linha só. Cancelado não
     * entra nas unidades (não é entrega pendente nem feita).
     *
     * @return array{total: int, unidades_enviadas: int, unidades_entregues: int, em_transporte: int}
     */
    private function resumoDosResgates(Project $project): array
    {
        $porStatus = Redemption::query()
            ->where('project_id', $project->id)
            ->selectRaw('status, COUNT(*) AS linhas, SUM(COALESCE(quantity, 1)) AS unidades')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $unidades = fn (string $status) => (int) ($porStatus[$status]->unidades ?? 0);

        return [
            'total' => (int) $porStatus->sum('linhas'),
            'unidades_enviadas' => $unidades(Redemption::STATUS_SHIPPED) + $unidades(Redemption::STATUS_DELIVERED),
            'unidades_entregues' => $unidades(Redemption::STATUS_DELIVERED),
            'em_transporte' => (int) ($porStatus[Redemption::STATUS_SHIPPED]->linhas ?? 0),
        ];
    }

    /**
     * Os resgates em transporte, para o "Selecionar todos" da entrega em
     * massa: a seleção atravessa as páginas, então a tela precisa dos ids de
     * todos, não só dos que estão à vista. Só números, e só para quem marca.
     *
     * @return array<int, int>
     */
    private function idsEmTransporte(Request $request, Project $project): array
    {
        if (! $request->user()?->canPerformCriticalActions()) {
            return [];
        }

        return Redemption::query()
            ->where('project_id', $project->id)
            ->where('status', Redemption::STATUS_SHIPPED)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Recurso de resgate reutilizado nas telas de projeto e de resgates. */
    public static function redemptionResource(Redemption $r): array
    {
        return [
            'id' => $r->id,
            'user' => $r->user->name,
            'product' => $r->product->name,
            'status' => $r->status,
            // Inclusões UAT: quantidade (solicitações de admin pedem N),
            // CPF sempre mascarado na exibição e observações do resgate.
            'quantity' => $r->quantity,
            'cpf' => $r->cpf_masked,
            'notes' => $r->notes,
            // Solicitação de admin (requested_by preenchido) × resgate
            // individual: a tela rotula diferente.
            'is_request' => $r->isAdminRequest(),
            'carrier' => $r->carrier,
            'tracking_code' => $r->tracking_code,
            'tracking_url' => $r->tracking_url,
            'delivered_at' => $r->delivered_at,
            'address' => $r->short_address,
        ];
    }

    /** Fallback de onboarding manual quando a comunicação com a Omie falha. */
    public function create(): Response
    {
        // Grade da Omie: só o pai é item do Projeto. O tamanho é escolhido
        // por cada pessoa no portal — marcar os tamanhos aqui multiplicava a
        // quantidade, que vale para CADA item marcado.
        $filhos = Product::filhosDeGrade();
        $pais = array_flip(array_unique($filhos));

        return Inertia::render('Projects/Create', [
            'products' => Product::where('active', true)
                ->whereNotIn('id', array_keys($filhos))
                ->orderBy('name')
                ->get(['id', 'omie_code', 'name'])
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'omie_code' => $p->omie_code,
                    'name' => $p->name,
                    'grade' => isset($pais[mb_strtolower((string) $p->omie_code)]),
                ]),
        ]);
    }

    /**
     * Tamanho de grade que chegue marcado vira o pai: a quantidade do Projeto
     * manual vale para cada item, e sete tamanhos com 9.000 viravam 63.000
     * coletes. O pai leva os 9.000, e cada pessoa escolhe o tamanho no portal.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    private function paisNoLugarDosTamanhos(array $ids): array
    {
        $ids = array_map('intval', $ids);
        $tamanhos = array_intersect_key(Product::filhosDeGrade(), array_flip($ids));

        if ($tamanhos === []) {
            return $ids;
        }

        $pais = Product::query()
            ->whereIn(DB::raw('LOWER(omie_code)'), array_values(array_unique($tamanhos)))
            ->get(['id', 'omie_code'])
            ->mapWithKeys(fn (Product $p) => [mb_strtolower((string) $p->omie_code) => $p->id]);

        return collect($ids)
            ->map(fn (int $id) => isset($tamanhos[$id]) ? ($pais[$tamanhos[$id]] ?? $id) : $id)
            ->unique()
            ->values()
            ->all();
    }

    public function store(
        Request $request,
        CreateTrelloCardForProject $createCard,
        RegisterClientAdmin $registerAdmin,
    ): RedirectResponse {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            // A base de empresas mistura CNPJ e CPF (pessoa física vinda da
            // Omie), então os dois valem — mas precisam ser válidos: uma
            // empresa nova nasce aqui, e um documento errado ficaria na base.
            'company_cnpj' => [
                'required', 'string',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (Cnpj::tentar($value) === null && Cpf::tentar($value) === null) {
                        $fail('Informe um CNPJ (ou CPF) válido.');
                    }
                },
            ],
            'name' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1'],
            'delivery_deadline' => ['nullable', 'date'],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['exists:products,id'],

            /*
             * A ficha da empresa vem junto. O endereço não é enfeite: é para
             * onde a nota e a cobrança vão, e empresa criada aqui só tinha
             * nome e documento — ficava pela metade, e ninguém voltava para
             * completar.
             */
            'company.zip' => ['required', 'string', 'max:9'],
            'company.street' => ['required', 'string', 'max:255'],
            'company.number' => ['required', 'string', 'max:20'],
            'company.complement' => ['nullable', 'string', 'max:255'],
            'company.district' => ['required', 'string', 'max:120'],
            'company.city' => ['required', 'string', 'max:120'],
            'company.state' => ['required', 'string', 'size:2'],

            /*
             * Admin Cliente obrigatório para a empresa que ainda não tem um.
             *
             * É quem opera o cliente: sem ele a empresa nasce sem ninguém do
             * outro lado, e o Projeto fica esperando alguém da WeGift perceber
             * e cadastrar depois. Empresa que já tem responsável não pede de
             * novo — o front omite o bloco, e a regra abaixo aceita ausente.
             */
            'admin.name' => ['nullable', 'required_with:admin.email', 'string', 'max:255'],
            'admin.email' => ['nullable', 'required_with:admin.name', 'email', 'unique:users,email'],
        ], [], [
            'company.zip' => 'CEP',
            'company.street' => 'logradouro',
            'company.number' => 'número',
            'company.district' => 'bairro',
            'company.city' => 'cidade',
            'company.state' => 'UF',
            'admin.name' => 'nome do Admin Cliente',
            'admin.email' => 'e-mail do Admin Cliente',
        ]);

        // Empresa nova é cadastrada junto com o Projeto; se o documento já
        // existe, o Projeto é vinculado à empresa existente.
        $company = Company::firstOrCreate(
            ['cnpj' => preg_replace('/\D/', '', $data['company_cnpj'])],
            ['name' => trim($data['company_name'])],
        );
        $companyCreated = $company->wasRecentlyCreated;

        $endereco = collect($data['company'])
            ->map(fn (?string $valor) => $valor === null ? null : trim($valor))
            ->all();
        $endereco['zip'] = preg_replace('/\D/', '', (string) $endereco['zip']);
        $endereco['state'] = mb_strtoupper((string) $endereco['state']);

        /*
         * O endereço da tela prevalece: ele acabou de ser conferido por quem
         * está cadastrando. A razão social só é sobrescrita quando a atual é
         * provisória (o CNPJ no lugar do nome, deixado pelo sync) — nome real
         * cadastrado continua intocável, porque a ficha dela é editada em
         * Clientes.
         */
        $company->fill($endereco);

        if ($company->temNomeProvisorio()) {
            $company->name = trim($data['company_name']);
        }

        $company->save();

        if (($data['admin']['email'] ?? null) !== null) {
            if ($company->admins()->exists()) {
                return back()->withErrors([
                    'admin.email' => 'Esta empresa já tem um Admin Cliente. Os demais admins são cadastrados na ficha do Cliente.',
                ]);
            }

            try {
                $registerAdmin->handle($company, $data['admin'], $request->user());
            } catch (DomainException $e) {
                return back()->withErrors(['admin.email' => $e->getMessage()]);
            }
        } elseif (! $company->admins()->exists()) {
            return back()->withErrors([
                'admin.name' => 'Informe o Admin Cliente: a empresa não pode ficar sem responsável.',
            ]);
        }

        $project = Project::create([
            'company_id' => $company->id,
            'name' => $data['name'],
            'quantity' => $data['quantity'],
            'delivery_deadline' => $data['delivery_deadline'] ?? null,
        ]);

        // A quantidade do pivô é ABSOLUTA (é o que o `HandleOmieStockSignal`
        // exige em saldo para liberar). O fixo `1` de antes liberava um Projeto
        // de 500 kits com uma unidade em estoque; o formulário manual pede uma
        // quantidade só, então ela vale para cada kit escolhido.
        $project->products()->sync(array_fill_keys(
            $this->paisNoLugarDosTamanhos($data['product_ids']),
            ['quantity' => $data['quantity']],
        ));

        $createCard->handle($project);

        return redirect()->route('projects.show', $project)
            ->with('status', $companyCreated
                ? 'Projeto criado manualmente e empresa '.$company->name.' cadastrada.'
                : 'Projeto criado manualmente (fallback Omie).');
    }

    /**
     * Estrutura do kit em árvore, não em lista.
     *
     * Um componente pode ser kit também (kit de campanha que carrega um kit de
     * item), e a tela precisa mostrar esse nível a mais indentado sob o pai.
     * Enquanto isto devolvia só um nível, o sub-kit aparecia como peça solta e
     * o que tinha dentro dele não aparecia em lugar nenhum.
     *
     * `quantity` é sempre a quantidade POR UNIDADE do pai — é assim que o BOM
     * da Omie descreve, e é o que a tela rotula como "por kit".
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function componentTree(Product $product): Collection
    {
        return $product->components->map(fn (Product $c) => [
            // O código identifica o subitem: é por ele que a tela sabe que um
            // item do pedido já aparece aninhado dentro de outro e não deve
            // repetir no topo da lista.
            'omie_code' => $c->omie_code,
            'name' => $c->name,
            'quantity' => $c->pivot->quantity,
            'stock_qty' => (float) $c->stock_qty,
            'is_kit' => (bool) $c->is_kit,
            // `relationLoaded` respeita o teto do eager load: no último nível
            // carregado a chave vem vazia em vez de disparar uma query por
            // folha da árvore.
            'components' => $c->relationLoaded('components')
                ? $this->componentTree($c)
                : collect(),
        ]);
    }

    /**
     * Exclusão definitiva do Projeto (só Admin WeGift — a rota vive no grupo
     * role:admin). Apaga o rastro inteiro em transação via ProjectPurger:
     * rastreios, resgates, autorizações e o Projeto. Colaboradores e empresa ficam.
     */
    public function destroy(Project $project, Request $request, ProjectPurger $purger): RedirectResponse
    {
        $name = $project->name;
        $deleted = $purger->apagarProjeto($project);

        Log::info('Projeto excluído pelo Admin WeGift.', [
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
            'deleted' => $deleted,
        ]);

        Auditoria::registrar(AuditLog::PROJECT_DELETED, "Excluiu o projeto \"{$name}\".", dados: [
            'projeto_id' => $project->id,
        ] + $deleted);

        return redirect()->route('projects.index')
            ->with('status', 'Projeto "'.$name.'" excluído: '
                .$deleted['redemptions'].' resgates, '
                .$deleted['tracking_events'].' rastreios e '
                .$deleted['project_entitlements'].' autorizações apagados.');
    }

    /**
     * Move o Projeto de estágio à mão.
     *
     * O caminho normal é o Trello (webhook) em Design/Produção/Montagem/Envio
     * e o sinal de estoque da Omie para "Em Estoque". Quando a integração não está disponível, a
     * operação precisa conseguir posicionar o Projeto mesmo assim — inclusive
     * em "Em Estoque", que é o que destrava a liberação. Fica registrado no
     * log quem moveu e de onde para onde.
     */
    public function updateStage(Project $project, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pipeline_status' => ['required', 'in:'.implode(',', Project::PIPELINE_STATUSES)],
        ], [], ['pipeline_status' => 'etapa']);

        $from = $project->pipeline_status;
        $to = $data['pipeline_status'];

        if ($from === $to) {
            return back()->withErrors(['pipeline_status' => 'O Projeto já está nesta etapa.']);
        }

        $project->update(['pipeline_status' => $to]);

        // "Em Estoque" à mão destrava a liberação, e a liberação decide pelo
        // saldo espelhado (products.stock_qty) — que pode estar velho ou nunca
        // ter sido sincronizado. Reavalia na hora com o relatório em lote da
        // Omie (uma chamada só, nunca uma por kit: a Omie pune a conta por
        // consumo) para a trava do ReleaseProject trabalhar com número real.
        // Falha da Omie não prende a operação: o estágio muda mesmo assim e o
        // espelho velho fica registrado no log — a liberação continua barrando
        // sozinha enquanto o saldo não aparecer.
        if ($to === Project::STATUS_IN_STOCK) {
            try {
                app(HandleOmieStockSignal::class)->refreshProject($project);
            } catch (\Throwable $e) {
                Log::warning('Reavaliação de estoque falhou na mudança manual para Em Estoque.', [
                    'project_id' => $project->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Estágio do Projeto alterado manualmente.', [
            'project_id' => $project->id,
            'from' => $from,
            'to' => $to,
            'user_id' => $request->user()->id,
        ]);

        return back()->with('status', 'Etapa alterada para "'.(self::STAGE_LABELS[$to] ?? $to).'".');
    }

    /**
     * Edita nome e prazo de entrega do Projeto (inclusão UAT).
     *
     * O nome que a Omie monta (número do pedido + razão social) nem sempre é
     * como o cliente chama a campanha — a tela oferece, por exemplo, o "Nº do
     * pedido do cliente" (`client_order_number`) como alternativa. O carimbo
     * `details_edited_at` avisa a sincronização (`VendaProduto.Alterada`) que
     * nome e prazo passaram a ser decisão do operador e não podem mais ser
     * sobrescritos pelo retorno da Omie.
     */
    public function updateDetails(Project $project, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'delivery_deadline' => ['nullable', 'date'],
        ], [], ['name' => 'nome do Projeto', 'delivery_deadline' => 'prazo de entrega']);

        // O log de "quem alterou o quê" precisa do valor ANTES do update.
        $de = [
            'name' => $project->name,
            'delivery_deadline' => $project->delivery_deadline?->toDateString(),
        ];

        $project->update([
            'name' => trim($data['name']),
            'delivery_deadline' => $data['delivery_deadline'] ?? null,
            'details_edited_at' => now(),
        ]);

        Log::info('Nome/prazo do Projeto alterados manualmente.', [
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
            'from' => $de,
            'to' => [
                'name' => $project->name,
                'delivery_deadline' => $project->delivery_deadline?->toDateString(),
            ],
        ]);

        return back()->with('status', 'Dados do Projeto atualizados.');
    }

    public function release(Project $project, Request $request, ReleaseProject $release): RedirectResponse
    {
        // Admin Cliente de OUTRA empresa: 403, não mensagem de domínio.
        abort_unless(ReleaseProject::canRelease($request->user(), $project), 403);

        try {
            $release->handle($project, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['release' => $e->getMessage()]);
        }

        // Liberar é o gesto que dispara e-mail para todos os colaboradores do Projeto.
        Auditoria::registrar(AuditLog::PROJECT_RELEASED, "Liberou os resgates do projeto \"{$project->name}\".", $project);

        return back()->with('status', 'Resgates liberados. Colaboradores notificados por e-mail.');
    }

    /**
     * Freio de mão: qualquer admin (WeGift, Admin Cliente, Admin Projeto)
     * suspende os resgates do Projeto — ver `BlockProjectRedemptions`.
     */
    public function blockRedemptions(Project $project, Request $request, BlockProjectRedemptions $block): RedirectResponse
    {
        abort_unless(BlockProjectRedemptions::canBlock($request->user(), $project), 403);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:300'],
        ], [], ['reason' => 'motivo']);

        try {
            $block->block($project, $request->user(), $data['reason'] ?? null);
        } catch (DomainException $e) {
            return back()->withErrors(['block' => $e->getMessage()]);
        }

        Auditoria::registrar(AuditLog::PROJECT_REDEMPTIONS_BLOCKED, "Bloqueou os resgates do projeto \"{$project->name}\".", $project, [
            'motivo' => $data['reason'] ?? null,
        ]);

        return back()->with('status', 'Resgates bloqueados. Só o Admin WeGift desbloqueia.');
    }

    /** Soltar o freio é só do Admin WeGift, mesmo que outro perfil tenha travado. */
    public function unblockRedemptions(Project $project, Request $request, BlockProjectRedemptions $block): RedirectResponse
    {
        abort_unless(BlockProjectRedemptions::canUnblock($request->user()), 403);

        try {
            $block->unblock($project, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['block' => $e->getMessage()]);
        }

        Auditoria::registrar(AuditLog::PROJECT_REDEMPTIONS_UNBLOCKED, "Desbloqueou os resgates do projeto \"{$project->name}\".", $project);

        return back()->with('status', 'Resgates desbloqueados.');
    }

    /** Reenvia o convite de primeiro acesso a um Colaborador ainda pendente. */
    public function resendInvite(
        Project $project,
        ProjectEntitlement $entitlement,
        Request $request,
        InviteUser $inviteUser,
    ): RedirectResponse {
        abort_unless($entitlement->project_id === $project->id, 404);

        $actor = $request->user();
        $allowed = $actor->canPerformCriticalActions() || $actor->managesProject($project);
        abort_unless($allowed, 403);

        if ($entitlement->user->hasSetPassword()) {
            return back()->withErrors(['invite' => 'Este cliente já definiu a senha de acesso.']);
        }

        $inviteUser->handle($entitlement->user);

        Auditoria::registrar(AuditLog::USER_INVITE_SENT, "Reenviou o convite de acesso para {$entitlement->user->email}.", $entitlement->user, [
            'projeto_id' => $project->id,
        ]);

        return back()->with('status', 'Convite reenviado para '.$entitlement->user->email.'.');
    }

    /**
     * Tira a pessoa do Projeto inteira, numa chamada só: todas as autorizações
     * dela aqui (uma por item, ou por tamanho), os resgates deste Projeto e, se
     * ela não estiver em outro, a conta.
     *
     * A tela chamava `destroyEntitlement` uma vez por autorização, em fila:
     * vinte e tantas recargas da página e o mesmo aviso repetido.
     */
    public function destroyCollaborator(Project $project, User $user, Request $request, ProjectPurger $purger): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor->canPerformCriticalActions() || $actor->managesProject($project), 403);

        abort_unless(ProjectEntitlement::where('project_id', $project->id)->where('user_id', $user->id)->exists(), 404);

        // Admin com resgate: sair da lista de Colaboradores é perder o
        // resgate, não a conta. Ele continua administrando o Projeto.
        if (! $user->isEndCustomer()) {
            $apagados = $purger->tirarDoProjeto($project, $user);

            Auditoria::registrar(
                AuditLog::ENTITLEMENT_DELETED,
                "Tirou o resgate do Admin {$user->name} ({$user->email}) no projeto \"{$project->name}\".",
                $project,
                ['colaborador_id' => $user->id, 'conta_excluida' => false] + $apagados,
            );

            return back()->with('status', "{$user->name} não resgata mais neste Projeto. Continua como Admin.");
        }

        $emOutrosProjetos = ProjectEntitlement::where('user_id', $user->id)
            ->where('project_id', '!=', $project->id)
            ->exists();

        $apagados = $emOutrosProjetos ? $purger->tirarDoProjeto($project, $user) : $purger->apagarUsuario($user);

        Log::info($emOutrosProjetos ? 'Colaborador removido de um Projeto.' : 'Colaborador excluído a partir do Projeto.', [
            'project_id' => $project->id,
            'usuario' => $user->email,
            'por' => $actor->id,
        ] + $apagados);

        Auditoria::registrar(
            AuditLog::ENTITLEMENT_DELETED,
            ($emOutrosProjetos ? 'Removeu' : 'Excluiu o colaborador')." {$user->name} ({$user->email}) "
                .($emOutrosProjetos ? 'do' : 'a partir do')." projeto \"{$project->name}\".",
            $project,
            ['colaborador_id' => $user->id, 'conta_excluida' => ! $emOutrosProjetos] + $apagados,
        );

        return back()->with('status', $emOutrosProjetos
            ? "{$user->name} saiu deste Projeto. A conta continua ativa em outros Projetos."
            : "{$user->name} saiu deste Projeto.");
    }

    /**
     * Remove um Colaborador do Projeto — e a conta dele, quando não sobra vínculo.
     *
     * É a exclusão que o RH precisa: pessoa desligada da empresa não pode
     * continuar com presente para resgatar. O Admin Cliente age só dentro da
     * própria empresa; a equipe WeGift, em qualquer Projeto.
     *
     * A conta só é apagada quando a pessoa não tem autorização em NENHUM outro
     * Projeto: o mesmo colaborador pode participar de várias campanhas, e sair
     * de uma não é sair da empresa.
     */
    public function destroyEntitlement(
        Project $project,
        ProjectEntitlement $entitlement,
        Request $request,
        ProjectPurger $purger,
    ): RedirectResponse {
        abort_unless($entitlement->project_id === $project->id, 404);

        $actor = $request->user();
        $allowed = $actor->canPerformCriticalActions() || $actor->managesProject($project);
        abort_unless($allowed, 403);

        $user = $entitlement->user;
        $outrosProjetos = ProjectEntitlement::where('user_id', $user->id)
            ->where('id', '!=', $entitlement->id)
            ->exists();

        if ($outrosProjetos) {
            // Sai deste Projeto, continua nos outros. O resgate feito AQUI vai
            // junto: sem autorização, ele ficaria órfão na lista da empresa.
            $apagados = $purger->apagarAutorizacao($entitlement);

            Log::info('Colaborador removido de um Projeto.', [
                'project_id' => $project->id,
                'usuario' => $user->email,
                'por' => $actor->id,
            ] + $apagados);

            Auditoria::registrar(AuditLog::ENTITLEMENT_DELETED, "Removeu {$user->name} ({$user->email}) do projeto \"{$project->name}\".", $project, [
                'colaborador_id' => $user->id,
                'conta_excluida' => false,
            ] + $apagados);

            return back()->with('status', $user->name.' saiu deste Projeto. A conta continua ativa em outros Projetos.');
        }

        $apagados = $purger->apagarUsuario($user);

        Log::info('Colaborador excluído a partir do Projeto.', [
            'project_id' => $project->id,
            'usuario' => $user->email,
            'por' => $actor->id,
        ] + $apagados);

        Auditoria::registrar(AuditLog::ENTITLEMENT_DELETED, "Excluiu o colaborador {$user->name} ({$user->email}) a partir do projeto \"{$project->name}\".", $project, [
            'colaborador_id' => $user->id,
            'conta_excluida' => true,
        ] + $apagados);

        return back()->with('status', $user->name.' foi excluído, com os resgates dele.');
    }

    /**
     * Os textos da tela de resgate desta campanha: saudação, chamada da
     * escolha, título das entregas e o nome e a descrição de cada item, como o
     * colaborador os lê. Vazio = o padrão.
     *
     * Os itens vêm pelo código do item principal (o pai, numa grade), e só os
     * deste Projeto entram: código de fora seria texto órfão no banco.
     */
    /**
     * Tira itens do Projeto: um item, ou todos os tamanhos de uma grade. Só
     * Admin WeGift (rota em role:admin), e só item sem resgate.
     */
    public function destroyItems(Project $project, Request $request, RemoveProjectItems $remover): RedirectResponse
    {
        $dados = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $nomes = $project->products()->whereIn('products.id', $dados['ids'])->pluck('name')->implode(', ');

        try {
            $feito = $remover->remover($project, $dados['ids'], $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['remove' => $e->getMessage()]);
        }

        Auditoria::registrar(AuditLog::PROJECT_UPDATED, "Removeu do projeto \"{$project->name}\": {$nomes}.", $project, $feito);

        return back()->with('status', $feito['itens'] === 1
            ? 'Item removido do Projeto.'
            : "{$feito['itens']} itens removidos do Projeto.");
    }

    /** Devolve ao Projeto um item removido à mão, como ele estava. */
    public function restoreItem(Project $project, string $codigo, RemoveProjectItems $remover): RedirectResponse
    {
        try {
            $nome = $remover->restaurar($project, $codigo);
        } catch (DomainException $e) {
            return back()->withErrors(['remove' => $e->getMessage()]);
        }

        Auditoria::registrar(AuditLog::PROJECT_UPDATED, "Devolveu ao projeto \"{$project->name}\": {$nome}.", $project);

        return back()->with('status', "{$nome} voltou ao Projeto.");
    }

    public function updatePortalTexts(Project $project, Request $request): RedirectResponse
    {
        $regras = [
            'itens' => ['nullable', 'array'],
            'itens.*.nome' => ['nullable', 'string', 'max:'.Project::PORTAL_ITEM_NOME_MAX],
            'itens.*.descricao' => ['nullable', 'string', 'max:'.Project::PORTAL_ITEM_DESCRICAO_MAX],
        ];
        $nomes = ['itens.*.nome' => 'nome do item', 'itens.*.descricao' => 'descrição do item'];

        foreach (Project::PORTAL_TEXTS as $chave => $texto) {
            $regras[$chave] = ['nullable', 'string', 'max:'.$texto['max']];
            $nomes[$chave] = mb_strtolower($texto['label']);
        }

        $dados = $request->validate($regras, [], $nomes);

        $doProjeto = collect(Product::principais($project->products))
            ->map(fn (Product $p) => mb_strtolower((string) $p->omie_code))
            ->flip();

        $itens = [];

        foreach ((array) ($dados['itens'] ?? []) as $codigo => $item) {
            $codigo = mb_strtolower((string) $codigo);
            $item = array_filter(
                ['nome' => trim((string) ($item['nome'] ?? '')), 'descricao' => trim((string) ($item['descricao'] ?? ''))],
                fn (string $t) => $t !== '',
            );

            if ($item !== [] && $doProjeto->has($codigo)) {
                $itens[$codigo] = $item;
            }
        }

        $textos = array_filter(
            array_map(fn ($t) => trim((string) $t), array_intersect_key($dados, Project::PORTAL_TEXTS)),
            fn (string $t) => $t !== '',
        );

        $project->update(['portal_texts' => [...$textos, ...($itens !== [] ? ['itens' => $itens] : [])] ?: null]);

        Auditoria::registrar(AuditLog::PROJECT_UPDATED, "Atualizou a tela de resgate de \"{$project->name}\".", $project);

        return back()->with('status', 'Tela de resgate de '.$project->name.' atualizada.');
    }

    /** Logo da campanha, enviada em Customizações (tela do Cliente). */
    public function storeLogo(
        Project $project,
        Request $request,
        StoreLogo $storeLogo,
    ): RedirectResponse {
        $request->validate(...StoreLogo::regras());

        $storeLogo->handle($project, $request->file('logo'));

        return back()->with('status', "Logo de {$project->name} atualizada.");
    }

    public function destroyLogo(Project $project, StoreLogo $storeLogo): RedirectResponse
    {
        $storeLogo->remove($project);

        return back()->with('status', "Logo de {$project->name} removida.");
    }

    public function storeEntitlement(
        Project $project,
        Request $request,
        RegisterEndCustomer $register,
    ): RedirectResponse {
        // Projeto de kit único não pede o kit no formulário — não há escolha a
        // fazer. O campo continua obrigatório na regra: o que muda é de onde
        // ele vem quando a tela não perguntou.
        $kits = $project->products()->pluck('products.id');

        if ($kits->count() === 1 && ! $request->filled('product_id') && ! $request->filled('product_ids')) {
            $request->merge(['product_id' => $kits->first()]);
        }

        // `product_ids` + `mode` (UAT): vários itens numa tacada, independentes
        // (`all`, a pessoa recebe todos) ou como opções (`choose`, escolhe um).
        // `product_id` singular segue aceito — o serviço converte.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'cpf' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', new ValorValido(Telefone::class)],
            'product_id' => ['required_without:product_ids', 'nullable', 'integer'],
            'product_ids' => ['required_without:product_id', 'nullable', 'array', 'min:1'],
            'product_ids.*' => ['integer', 'distinct'],
            'mode' => ['nullable', 'in:all,choose'],
        ], [], ['product_ids' => 'itens', 'mode' => 'modo de autorização']);

        try {
            $register->handle($project, $data, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['entitlement' => $e->getMessage()]);
        }

        Auditoria::registrar(AuditLog::ENTITLEMENT_CREATED, "Cadastrou o colaborador {$data['name']} ({$data['email']}) no projeto \"{$project->name}\".", $project);

        return back()->with('status', 'Colaborador cadastrado.');
    }

    /**
     * A última importação deste Projeto, enquanto ela ainda interessa.
     *
     * Some sozinha um tempo depois de terminar: a barra em 100% parada para
     * sempre no topo da aba vira ruído, mas some rápido demais e o operador
     * não chega a ver que acabou — nem quais linhas foram recusadas.
     *
     * @return array<string, mixed>|null
     */
    /**
     * A aba Colaboradores: uma linha por pessoa, já resumida.
     *
     * A tela recebia cada autorização com a conta e o item, e agrupava por
     * pessoa no navegador. Com nove mil pessoas e o Colete em sete tamanhos,
     * eram umas oitenta mil autorizações montadas como modelos a cada abertura,
     * e a página estourou os 512 MB de memória. Aqui elas passam uma a uma
     * pelo banco (cursor, sem modelos) e saem agrupadas: o que a lista mostra,
     * do jeito que ela mostra.
     *
     * Por pessoa: os itens garantidos contam um cada; cada grupo de escolha
     * conta um, resgatado quando qualquer opção dele foi resgatada.
     *
     * Só as pessoas da página pedida (paginaDeColaboradores).
     *
     * @param  array<int, int>  $userIds
     * @return array<int, array<string, mixed>>
     */
    private function colaboradoresDoProjeto(Project $project, array $userIds): array
    {
        $linhas = ProjectEntitlement::query()
            ->where('project_entitlements.project_id', $project->id)
            ->whereIn('project_entitlements.user_id', $userIds)
            ->join('users', 'users.id', '=', 'project_entitlements.user_id')
            ->join('products', 'products.id', '=', 'project_entitlements.product_id')
            ->select([
                'project_entitlements.id',
                'project_entitlements.user_id',
                'project_entitlements.choice_group',
                'project_entitlements.created_at',
                'project_entitlements.notified_at',
                'users.name as user_name',
                'users.email as user_email',
                'users.password_set_at as user_password_set_at',
                'users.role as user_role',
                'products.name as product_name',
            ])
            ->withRedeemedFlag()
            ->orderBy('project_entitlements.id')
            ->toBase()
            ->cursor();

        $pessoas = [];

        foreach ($linhas as $linha) {
            $pessoa = &$pessoas[$linha->user_id];

            // A primeira autorização da pessoa abre a linha (e é a que as
            // ações da linha usam, como o reenvio do convite).
            $pessoa ??= [
                'user' => ['id' => (int) $linha->user_id, 'name' => $linha->user_name, 'email' => $linha->user_email],
                'primary_id' => (int) $linha->id,
                'access_pending' => $linha->user_password_set_at === null,
                // Admin do Projeto com resgate: na lista, só a etiqueta.
                'admin' => $linha->user_role !== User::ROLE_END_CUSTOMER,
                'garantidos' => [],
                'grupos' => [],
                'nomes' => [],
                'notified_at' => null,
                'created_at' => null,
            ];

            $resgatou = (bool) $linha->is_redeemed;

            if ($linha->choice_group === null) {
                $pessoa['garantidos'][] = ['nome' => $linha->product_name, 'resgatou' => $resgatou];
            } else {
                $grupo = $pessoa['grupos'][$linha->choice_group] ?? ['opcoes' => 0, 'resgatou' => false];
                $pessoa['grupos'][$linha->choice_group] = [
                    'opcoes' => $grupo['opcoes'] + 1,
                    'resgatou' => $grupo['resgatou'] || $resgatou,
                ];
            }

            $pessoa['nomes'][] = $linha->product_name;
            // A primeira notificação, na ordem das autorizações.
            $pessoa['notified_at'] ??= $linha->notified_at;

            // O cadastro da pessoa no Projeto é a autorização mais antiga.
            if ($linha->created_at !== null && ($pessoa['created_at'] === null || $linha->created_at < $pessoa['created_at'])) {
                $pessoa['created_at'] = $linha->created_at;
            }
        }

        unset($pessoa);

        // Datas no mesmo formato que a serialização de um modelo devolvia.
        $data = fn (?string $valor) => $valor === null ? null : Carbon::parse($valor)->toJSON();

        return array_values(array_map(function (array $pessoa) use ($data) {
            $unidades = count($pessoa['garantidos']) + count($pessoa['grupos']);
            $resgatadas = count(array_filter($pessoa['garantidos'], fn (array $g) => $g['resgatou']))
                + count(array_filter($pessoa['grupos'], fn (array $g) => $g['resgatou']));

            return [
                'key' => 'pessoa-'.$pessoa['user']['id'],
                'user' => $pessoa['user'],
                'itens' => count($pessoa['nomes']),
                'itemsLabel' => $this->resumoDeItens($pessoa['garantidos'], $pessoa['grupos']),
                // A lista inteira fica no balão: na linha, só o resumo.
                'itemsTitle' => implode(', ', $pessoa['nomes']),
                'primary' => ['id' => $pessoa['primary_id']],
                'unidades' => $unidades,
                'resgatadas' => $resgatadas,
                'redeemed' => $resgatadas > 0 && $resgatadas === $unidades,
                'access_pending' => $pessoa['access_pending'],
                'admin' => $pessoa['admin'],
                'notified_at' => $data($pessoa['notified_at']),
                'created_at' => $data($pessoa['created_at']),
            ];
        }, $pessoas));
    }

    /**
     * "Kit Comemorativo" (um item só, pelo nome), "3 itens", "1 de 3",
     * "2 itens + 1 de 3". Sem a lista de nomes: ela mora no balão da célula.
     *
     * @param  array<int, array{nome: string, resgatou: bool}>  $garantidos
     * @param  array<string, array{opcoes: int, resgatou: bool}>  $grupos
     */
    private function resumoDeItens(array $garantidos, array $grupos): string
    {
        if (count($garantidos) === 1 && $grupos === []) {
            return (string) $garantidos[0]['nome'];
        }

        return implode(' + ', array_filter([
            $garantidos === [] ? null : count($garantidos).' '.(count($garantidos) === 1 ? 'item' : 'itens'),
            ...array_map(fn (array $g) => '1 de '.$g['opcoes'], array_values($grupos)),
        ]));
    }

    private function importacaoEmCurso(Project $project): ?array
    {
        // Só a da fila: a planilha pequena responde na hora, no próprio
        // painel da importação, e repetir o resultado aqui seria ruído.
        return EntitlementImport::query()
            ->with('project')
            ->where('project_id', $project->id)
            ->queInteressam()
            ->latest('id')
            ->first()
            ?->resumo();
    }

    /**
     * Para uma importação no meio: os lotes que ainda estão na fila são
     * descartados quando chegam ao worker (ImportEntitlementRows confere).
     *
     * Quem já entrou fica no Projeto. Desfazer seria apagar contas que podem
     * ter recebido convite, e "cancelar" não pode ser isso.
     */
    public function cancelImport(Project $project, EntitlementImport $import, Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor->canPerformCriticalActions() || $actor->managesProject($project), 403);
        abort_unless((int) $import->project_id === (int) $project->id, 404);

        if (! $import->isRunning()) {
            return back()->with('status', 'Esta importação já tinha terminado.');
        }

        $import->update(['cancelled_at' => now(), 'finished_at' => now()]);

        $entraram = $import->created + $import->admins;

        return back()->with('status', "Importação de {$import->filename} cancelada. "
            .($entraram === 1 ? '1 pessoa já tinha entrado e continua' : "{$entraram} pessoas já tinham entrado e continuam")
            .' no Projeto.');
    }

    /**
     * As importações da pessoa logada que o aviso flutuante acompanha. JSON e
     * não página: ele consulta a cada poucos segundos, em qualquer tela, e
     * recarregar a página inteira para mudar um número seria desperdício.
     */
    public function trackImports(Request $request): JsonResponse
    {
        return response()->json([
            'importacoes' => EntitlementImport::acompanhadasPor($request->user())
                ->with('project')
                ->limit(5)
                ->get()
                ->map(fn (EntitlementImport $i) => $i->resumo())
                ->values(),
        ]);
    }

    /**
     * As linhas recusadas de uma importação, numa planilha no formato do
     * modelo: corrigir e subir de novo só elas, em vez de caçar nove mil.
     *
     * As colunas são as do modelo deste Projeto, na mesma ordem, e o motivo
     * vai por último. Na volta, a validação avisa que a coluna do motivo é
     * ignorada, e ela não atrapalha nada.
     */
    public function importFailures(Project $project, EntitlementImport $import, Request $request): BinaryFileResponse
    {
        $actor = $request->user();
        abort_unless($actor->canPerformCriticalActions() || $actor->managesProject($project), 403);
        abort_unless((int) $import->project_id === (int) $project->id, 404);

        $colunas = BatchRegisterEndCustomers::templateColumns($project);
        $path = tempnam(sys_get_temp_dir(), 'welog_recusadas_');

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues([...$colunas, 'motivo_da_recusa']));

        $import->failures()->orderBy('linha')->chunk(500, function ($falhas) use ($writer, $colunas) {
            foreach ($falhas as $falha) {
                $writer->addRow(Row::fromValues([
                    ...array_map(fn (string $c) => (string) ($falha->dados[$c] ?? ''), $colunas),
                    "Linha {$falha->linha}: {$falha->mensagem}",
                ]));
            }
        });

        $writer->close();

        $nome = 'recusadas-'.pathinfo($import->filename, PATHINFO_FILENAME).'.xlsx';

        return response()->download($path, $nome, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Quebra a planilha em lotes e os enfileira, com um registro para a tela
     * acompanhar.
     *
     * Os jobs saem todos de uma vez, com as linhas dentro: a leitura já foi
     * feita, e mandar cada job reabrir o arquivo seria reler nove mil linhas
     * quarenta e cinco vezes.
     *
     * @param  array<int, array<string, string>>  $linhas
     * @param  array{garantidos: array<int, int>, opcoes: array<int, int>}  $itens
     */
    private function enfileirarImportacao(Project $project, array $linhas, Request $request, array $itens): EntitlementImport
    {
        $import = $this->novaImportacao($project, $linhas, $request);

        // 1 = cabeçalho: a primeira linha de dados é a 2, e é esse número que
        // o operador procura quando lê "linha 4.017" num erro.
        $numeroDaLinha = 2;

        foreach (array_chunk($linhas, ImportEntitlementRows::LOTE) as $lote) {
            ImportEntitlementRows::dispatch($import->id, $lote, $numeroDaLinha, $itens);
            $numeroDaLinha += count($lote);
        }

        return $import;
    }

    /** @param  array<int, array<string, mixed>>  $linhas */
    private function novaImportacao(Project $project, array $linhas, Request $request): EntitlementImport
    {
        return EntitlementImport::create([
            'project_id' => $project->id,
            'uploaded_by' => $request->user()->id,
            'filename' => $request->file('file')->getClientOriginalName(),
            'total' => count($linhas),
        ]);
    }

    /**
     * Confere a planilha sem cadastrar ninguém.
     *
     * JSON e não redirect: o arquivo continua selecionado na tela, e o
     * operador importa o MESMO arquivo que acabou de ver aprovado, sem ter de
     * escolhê-lo de novo e correr o risco de pegar outro.
     */
    public function validateBatchEntitlements(
        Project $project,
        Request $request,
        BatchRegisterEndCustomers $batch,
        ValidateEntitlementSheet $valida,
    ): JsonResponse {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt'],
        ]);

        try {
            $linhas = $batch->lerPlanilha($request->file('file')->getRealPath());
        } catch (DomainException $e) {
            return response()->json([
                'total' => 0, 'prontas' => 0, 'com_erro' => 0, 'com_aviso' => 0,
                'cabecalho' => [['tipo' => 'erro', 'mensagem' => $e->getMessage()]],
                'linhas' => [], 'linhas_omitidas' => 0,
                'colunas' => [], 'previa_colunas' => [], 'previa' => [], 'previa_omitidas' => 0,
                'pode_importar' => false,
            ]);
        }

        return response()->json($valida->handle($project, $linhas));
    }

    public function batchEntitlements(
        Project $project,
        Request $request,
        BatchRegisterEndCustomers $batch,
    ): RedirectResponse {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt'],
            // O que cada pessoa recebe, decidido na tela de importação.
            'garantidos' => ['nullable', 'array'],
            'garantidos.*' => ['integer'],
            'opcoes' => ['nullable', 'array'],
            'opcoes.*' => ['integer'],
        ]);

        try {
            $itens = BatchRegisterEndCustomers::itensEscolhidos(
                $project,
                (array) $request->input('garantidos', []),
                (array) $request->input('opcoes', []),
            );
            // E-mail repetido na planilha: a primeira linha entra, as outras
            // são recusadas. Marcado aqui, no arquivo inteiro, porque a fila
            // o corta em lotes e a repetição pode cair em outro.
            $linhas = BatchRegisterEndCustomers::marcarRepetidos(
                $batch->lerPlanilha($request->file('file')->getRealPath()),
            );
        } catch (DomainException $e) {
            return back()->withErrors(['batch' => $e->getMessage()]);
        }

        // Registrado na entrada, antes de saber se vai para a fila: quem subiu
        // a planilha de dados pessoais é o fato; o resultado aparece na tela.
        Auditoria::registrar(AuditLog::ENTITLEMENT_IMPORTED, sprintf(
            'Importou a planilha "%s" (%d linhas) no projeto "%s".',
            $request->file('file')->getClientOriginalName(),
            count($linhas),
            $project->name,
        ), $project, ['linhas' => count($linhas)]);

        /*
         * Planilha grande vai para a fila.
         *
         * Cada linha cadastra usuário, autorização, gera um token de convite
         * (bcrypt, ~60ms) e enfileira o e-mail. Nove mil linhas são uns doze
         * minutos numa requisição que o nginx corta aos cinco: metade entrava,
         * o operador via 504 e não tinha como saber onde parou.
         *
         * O corte é onde o laço ainda cabe com folga na requisição. Abaixo
         * dele, responder na hora é melhor — a pessoa vê o resultado em vez de
         * uma barra de progresso para um trabalho de dois segundos.
         */
        if (count($linhas) > EntitlementImport::LIMITE_SINCRONO) {
            $import = $this->enfileirarImportacao($project, $linhas, $request, $itens);

            return back()->with(
                'status',
                $import->total.' linhas recebidas. O cadastro está sendo feito em segundo plano: '
                    .'acompanhe o progresso nesta tela.',
            );
        }

        try {
            $result = $batch->processarLinhas($project, $linhas, $request->user(), 2, $itens);
        } catch (DomainException $e) {
            return back()->withErrors(['batch' => $e->getMessage()]);
        }

        // A planilha pequena também fica registrada: é o que deixa baixar as
        // recusadas dela, como as da fila.
        $import = $this->novaImportacao($project, $linhas, $request);
        ImportEntitlementRows::registrar($import, $result, count($linhas));

        if ($result['errors'] !== []) {
            $request->session()->flash('import_failures_url', route('projects.imports.failures', [$project, $import]));
        }

        // A planilha cadastra os dois papéis, então o retorno conta os dois —
        // e cala sobre o que veio zerado: "0 Admins" em toda importação sem
        // admin é ruído em cima de um número que ninguém pediu.
        $partes = array_filter([
            $result['created'] > 0 ? $result['created'].' '.($result['created'] === 1 ? 'Colaborador' : 'Colaboradores') : null,
            $result['admins'] > 0 ? $result['admins'].' '.($result['admins'] === 1 ? 'Admin do Projeto' : 'Admins do Projeto') : null,
        ]);

        $total = $result['created'] + $result['admins'];

        $message = $partes === []
            ? 'Nenhuma linha da planilha foi cadastrada.'
            : implode(' e ', $partes).($total === 1 ? ' cadastrado.' : ' cadastrados.');

        if ($result['ignored'] > 0) {
            $message .= ' '.$result['ignored'].($result['ignored'] === 1
                ? ' já estava no Projeto e não foi cadastrado de novo.'
                : ' já estavam no Projeto e não foram cadastrados de novo.');
        }

        if ($result['errors'] !== []) {
            $details = collect($result['errors'])
                ->map(fn (array $e) => "Linha {$e['row']}: {$e['message']}")
                ->implode(' | ');

            return back()->with('status', $message)->withErrors(['batch' => $details]);
        }

        return back()->with('status', $message);
    }

    /** Baixa um modelo .xlsx do cadastro em lote (cabeçalho + linha de exemplo). */
    public function entitlementsTemplate(Project $project, Request $request): BinaryFileResponse
    {
        $actor = $request->user();
        $allowed = $actor->canPerformCriticalActions() || $actor->managesProject($project);
        abort_unless($allowed, 403);

        // As colunas e a linha de exemplo saem do mesmo lugar, para não se
        // desencontrarem.
        $path = tempnam(sys_get_temp_dir(), 'welog_modelo_');

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(BatchRegisterEndCustomers::templateColumns($project)));
        $writer->addRow(Row::fromValues(BatchRegisterEndCustomers::templateExample($project)));
        $writer->close();

        return response()->download($path, 'modelo-clientes-finais.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}

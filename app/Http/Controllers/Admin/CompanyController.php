<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Integrations\Omie\OmieAccounts;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\Project;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Admin\BatchImportCompanies;
use App\Services\Auth\InviteUser;
use App\Services\Branding\StoreLogo;
use App\Services\FluxoA\RegisterClientAdmin;
use App\Services\FluxoA\RemoveClientAdmin;
use App\Services\Projects\ProjectPurger;
use App\Support\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Cadastro de empresas cliente e de seus administradores (módulo 10).
 * A hierarquia é Empresa Cliente → Admin Cliente → Usuários.
 */
class CompanyController extends Controller
{
    public function index(): Response
    {
        // `with('admins')` em vez de consultar dentro do map: antes saía uma
        // query por empresa só para listar os administradores.
        $companies = Company::query()
            ->with('admins:id,company_id,name,email,password_set_at')
            ->withCount([
                'users as end_customers_count' => fn ($q) => $q->where('role', User::ROLE_END_CUSTOMER),
                'projects as projects_count',
            ])
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Companies/Index', [
            'companies' => $companies->map(fn (Company $c) => $this->resource($c) + [
                'admins' => $c->admins->map(fn (User $u) => $this->pessoa($u)),
                'end_customers_count' => $c->end_customers_count,
                'projects_count' => $c->projects_count,
            ]),
        ]);
    }

    /**
     * Onde está esta pessoa: busca por e-mail ou nome, em qualquer papel.
     *
     * A busca da tela olhava só razão social e CNPJ, então procurar alguém
     * pelo e-mail devolvia empresa nenhuma — ou, pior, a empresa errada, por
     * um dígito solto do termo casando com o CNPJ.
     *
     * Devolve o CAMINHO de cada pessoa (empresa, e o Projeto quando houver),
     * que é o que a árvore precisa para abrir já no lugar certo.
     */
    public function findPeople(Request $request): JsonResponse
    {
        $termo = trim((string) $request->query('q'));

        // Dois caracteres casam com quase tudo e trariam a base inteira.
        if (mb_strlen($termo) < 3) {
            return response()->json(['people' => [], 'company_ids' => [], 'project_ids' => []]);
        }

        $like = '%'.mb_strtolower($termo).'%';

        $users = User::query()
            ->whereIn('role', [User::ROLE_ADMIN_CLIENT, User::ROLE_PROJECT_ADMIN, User::ROLE_END_CUSTOMER])
            ->where(fn ($q) => $q
                ->whereRaw('LOWER(email) LIKE ?', [$like])
                ->orWhereRaw('LOWER(name) LIKE ?', [$like]))
            ->with(['company:id,name', 'entitlements.project:id,name,company_id'])
            ->orderBy('name')
            ->limit(50)
            ->get();

        $pessoas = [];
        $empresas = [];
        $projetos = [];

        foreach ($users as $user) {
            // O Colaborador pode estar em mais de um Projeto: cada um é um
            // caminho próprio, porque é por ele que a árvore abre.
            $projetosDoUsuario = $user->role === User::ROLE_END_CUSTOMER
                ? $user->entitlements->pluck('project')->filter()->unique('id')->values()
                : collect();

            if ($user->role === User::ROLE_PROJECT_ADMIN && $user->admin_project_id) {
                $projetosDoUsuario = Project::whereKey($user->admin_project_id)->get(['id', 'name', 'company_id']);
            }

            $papel = match ($user->role) {
                User::ROLE_ADMIN_CLIENT => 'Admin Cliente',
                User::ROLE_PROJECT_ADMIN => 'Admin Projeto',
                default => 'Colaborador',
            };

            if ($user->company_id) {
                $empresas[] = $user->company_id;
            }

            foreach ($projetosDoUsuario as $projeto) {
                $projetos[] = $projeto->id;
                $empresas[] = $projeto->company_id;
            }

            $pessoas[] = $this->pessoa($user) + [
                'role' => $papel,
                'company' => $user->company?->name,
                'company_id' => $user->company_id,
                'projects' => $projetosDoUsuario->map(fn (Project $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'url' => route('projects.show', $p->id),
                ])->values(),
            ];
        }

        return response()->json([
            'people' => $pessoas,
            // A árvore usa para filtrar E para abrir: o caminho até a pessoa
            // já vem aberto, em vez de exigir três cliques para reencontrá-la.
            'company_ids' => array_values(array_unique($empresas)),
            'project_ids' => array_values(array_unique($projetos)),
        ]);
    }

    /**
     * Projetos de um Cliente, para a consulta em árvore.
     *
     * Vem por requisição separada, ao abrir o nível, e não junto da lista: uma
     * base com dezenas de clientes carregaria todos os Projetos de todos eles
     * para mostrar os de um.
     */
    public function projects(Company $company): JsonResponse
    {
        $projects = $company->projects()
            ->withCount('entitlements')
            ->orderByDesc('id')
            ->get(['id', 'name', 'company_id', 'pipeline_status', 'omie_order_number', 'released_at']);

        return response()->json($projects->map(fn (Project $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'order_number' => $p->omie_order_number,
            'stage' => $p->stageLabel(),
            'released' => $p->released_at !== null,
            'collaborators_count' => $p->entitlements_count,
            'url' => route('projects.show', $p->id),
        ]));
    }

    /**
     * As pessoas de um Projeto: quem administra e quem resgata.
     *
     * O nível mais fundo e o mais pesado — um Projeto pode ter centenas de
     * colaboradores —, então só é buscado quando alguém abre AQUELE Projeto.
     */
    public function people(Project $project): JsonResponse
    {
        $project->load([
            'projectAdmins:id,admin_project_id,name,email,password_set_at,active',
            'entitlements.user:id,name,email,password_set_at,active',
        ]);

        return response()->json([
            'admins' => $project->projectAdmins->map(fn (User $u) => $this->pessoa($u))->values(),
            'collaborators' => $project->entitlements
                ->map(fn ($e) => $e->user ? $this->pessoa($e->user) : null)
                ->filter()
                ->unique('id')
                ->values(),
        ]);
    }

    /**
     * Uma pessoa como a árvore mostra: quem é e se já consegue entrar.
     *
     * `access_pending` é o dado que mais se procura aqui — "cadastrei e a
     * pessoa diz que não consegue entrar" quase sempre é convite não aceito.
     *
     * @return array<string, mixed>
     */
    private function pessoa(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'access_pending' => $user->password_set_at === null,
            'active' => (bool) $user->active,
        ];
    }

    /**
     * Campos do cadastro — os mesmos na lista e na página da Empresa, porque o
     * formulário de edição é o mesmo nas duas.
     *
     * @return array<string, mixed>
     */
    private function resource(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            // Nome de exibição (Company Name) definido pela WeGift: é o que os
            // e-mails ao Usuário mostram. `name` é a razão social espelhada da
            // Omie e volta a cada sincronização; este campo é só nosso.
            'display_name' => $company->display_name,
            // display_name quando definido, senão a razão social — pronto para
            // listas e prévias ("como o Usuário vai ler").
            'label' => $company->label,
            'trade_name' => $company->trade_name,
            'cnpj' => $company->cnpj,
            'omie_customer_code' => $company->omie_customer_code,
            // De qual CNPJ da WeGift veio o cliente (null com um CNPJ só).
            'origem' => app(OmieAccounts::class)->isMultiAccount()
                ? app(OmieAccounts::class)->label($company->omie_account)
                : null,
            'contact_name' => $company->contact_name,
            'contact_email' => $company->contact_email,
            'contact_phone' => $company->contact_phone,
            'zip' => $company->zip,
            'street' => $company->street,
            'number' => $company->number,
            'complement' => $company->complement,
            'district' => $company->district,
            'city' => $company->city,
            'state' => $company->state,
            'notes' => $company->notes,
            'full_address' => $company->full_address,
            // Empresa vinda do webhook da Omie nasce incompleta; a tela mostra
            // o que falta em vez de deixar o operador descobrir na hora de
            // expedir.
            'missing_fields' => $company->missingFields(),
        ];
    }

    /**
     * Página da Empresa Cliente: tudo que está pendurado nela em um lugar só —
     * Admin Cliente, Usuários, Projetos e Resgates.
     *
     * Existe como página, e não como painel lateral, porque é destino de
     * navegação: tem URL própria, volta no histórico e comporta quatro listas
     * que num painel de 480px viriam espremidas uma sob a outra.
     */
    public function show(Company $company): Response
    {
        $company->load('admins:id,company_id,name,email,password_set_at,active');

        $projects = $company->projects()
            ->with('products:id,name')
            ->withCount(['entitlements', 'redemptions'])
            ->latest()
            ->get();

        // Usuário é cadastrado dentro de um Projeto, mas pertence à
        // Empresa: quem abre esta página quer o quadro da empresa inteira.
        $endCustomers = User::where('company_id', $company->id)
            ->where('role', User::ROLE_END_CUSTOMER)
            ->withCount(['entitlements', 'redemptions'])
            ->orderBy('name')
            ->get();

        $redemptions = Redemption::whereIn('project_id', $projects->pluck('id'))
            ->with('user:id,name', 'product:id,name', 'project:id,name')
            ->latest()
            ->get();

        return Inertia::render('Admin/Companies/Show', [
            'company' => $this->resource($company) + [
                // Estado de cada customização + o catálogo delas. O catálogo vem
                // do servidor para o modal não repetir rótulo e texto que já
                // vivem no model — feature nova aparece sem tocar no Vue.
                'customizations' => $company->customizacoes(),

                // Campos marcados para resgate. A seleção GRAVADA, não a
                // efetiva: com a chave desligada a efetiva é "todos", e
                // religar tem de mostrar o que estava escolhido antes.
                'import_columns' => $company->customizations['import_columns'] ?? array_keys(Company::IMPORT_COLUMNS),

                'logo_url' => $company->logo_url,
                'logo_levels' => $company->logoNiveis(),
                'support_email' => $company->customizations['support_email'] ?? null,
                // Página de primeiro acesso: o endereço de agora e o aviso
                // gravado (a efetiva depende da chave, mas a tela edita o texto).
                'first_access' => [
                    'base_url' => url('/primeiro-acesso').'/',
                    'slug' => $company->slugDePrimeiroAcesso(),
                    'dica' => $company->customizations['first_access_dica'] ?? null,
                ],
                // "3 de 4 etapas enviadas", no atalho para o Estúdio de mensagens.
                'etapas_total' => count(EmailTemplate::JORNADA),
                'etapas_ligadas' => count(array_diff(array_keys(EmailTemplate::JORNADA), $company->etapasDesligadas())),

                // Só quem tem acesso: Admin tirado com histórico fica
                // desativado (RemoveAdminAccount) e não administra mais nada.
                'admins' => $company->admins->where('active', true)->values()->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'access_pending' => $u->password_set_at === null,
                ]),
            ],

            'customization_catalog' => collect(Company::CUSTOMIZATIONS)
                ->map(fn (array $def, string $chave) => ['key' => $chave] + $def)
                ->values(),

            // As colunas que o Cliente pode ligar e desligar na planilha. Vem do
            // model pelo mesmo motivo do catálogo acima: coluna nova aparece
            // sem tocar no Vue.
            'import_columns_catalog' => collect(Company::IMPORT_COLUMNS)
                ->map(fn (array $def, string $chave) => ['key' => $chave] + $def)
                ->values(),

            'logo_levels_catalog' => collect(Company::LOGO_NIVEIS)
                ->map(fn (array $def, string $chave) => ['key' => $chave] + $def)
                ->values(),

            'projects' => $projects->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'quantity' => $p->quantity,
                'delivery_deadline' => $p->delivery_deadline,
                'pipeline_status' => $p->pipeline_status,
                'released' => $p->isReleased(),
                'kits' => $p->products->pluck('name')->values(),
                // A logo alimenta o card de Customizações no fim desta tela.
                'logo_url' => $p->logo_url,
                'end_customers_count' => $p->entitlements_count,
                'redemptions_count' => $p->redemptions_count,
            ]),

            'end_customers' => $endCustomers->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'access_pending' => ! $u->hasSetPassword(),
                'projects_count' => $u->entitlements_count,
                'redemptions_count' => $u->redemptions_count,
            ]),

            'redemptions' => $redemptions->map(fn (Redemption $r) => [
                'id' => $r->id,
                'user' => $r->user->name,
                'product' => $r->product->name,
                'project' => ['id' => $r->project_id, 'name' => $r->project->name],
                'status' => $r->status,
                'carrier' => $r->carrier,
                'tracking_code' => $r->tracking_code,
                'tracking_url' => $r->tracking_url,
                'delivered_at' => $r->delivered_at,
                'address' => $r->short_address,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        if (Company::where('cnpj', $data['cnpj'])->exists()) {
            return back()->withErrors(['cnpj' => 'Já existe uma empresa com este CNPJ.']);
        }

        Company::create($data);

        return back()->with('status', 'Empresa cadastrada.');
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $data = $this->validated($request);

        $duplicada = Company::where('cnpj', $data['cnpj'])
            ->whereKeyNot($company->id)
            ->exists();

        if ($duplicada) {
            return back()->withErrors(['cnpj' => 'Já existe outra empresa com este CNPJ.']);
        }

        $company->update($data);

        Auditoria::registrar(AuditLog::COMPANY_UPDATED, "Alterou o cadastro do Cliente {$company->name}.", $company, [
            'campos' => array_keys($company->getChanges()),
        ]);

        return back()->with('status', 'Empresa atualizada.');
    }

    /**
     * Regras compartilhadas por criação e edição. CNPJ e CEP são normalizados
     * para só dígitos — sem isso a mesma empresa entraria duas vezes por
     * diferença de pontuação.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Company Name mostrado nos e-mails ao Usuário (UAT). Opcional:
            // vazio = os e-mails usam a razão social.
            'display_name' => ['nullable', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'cnpj' => ['required', 'string', 'max:20'],

            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30'],

            'zip' => ['nullable', 'string', 'max:10'],
            'street' => ['nullable', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'size:2'],

            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'name' => 'razão social',
            'display_name' => 'nome de exibição',
            'trade_name' => 'nome fantasia',
            'contact_email' => 'e-mail de contato',
            'contact_phone' => 'telefone',
            'state' => 'UF',
        ]);

        // Só normaliza quando o campo VEIO no envio: formulário antigo (sem o
        // input da onda 3) não pode apagar um nome de exibição já definido.
        if (array_key_exists('display_name', $data)) {
            $data['display_name'] = trim((string) $data['display_name']) ?: null;
        }

        $data['cnpj'] = preg_replace('/\D/', '', $data['cnpj']);
        $data['zip'] = preg_replace('/\D/', '', (string) ($data['zip'] ?? '')) ?: null;
        $data['contact_phone'] = preg_replace('/\D/', '', (string) ($data['contact_phone'] ?? '')) ?: null;
        $data['state'] = ($data['state'] ?? null) ? strtoupper($data['state']) : null;
        $data['contact_email'] = ($data['contact_email'] ?? null) ? strtolower($data['contact_email']) : null;

        return $data;
    }

    /** Baixa o modelo .xlsx do cadastro em lote (cabeçalho + uma linha de exemplo). */
    public function template(): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'welog_modelo_');

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(BatchImportCompanies::TEMPLATE_COLUMNS));
        $writer->addRow(Row::fromValues([
            'Suvinil Tintas S/A', 'Suvinil', '12.345.678/0001-90', '',
            'Marina Souza', 'marina@suvinil.com.br', '(11) 3000-1000',
            '01310-100', 'Avenida Paulista', '1000', 'Andar 12', 'Bela Vista', 'São Paulo', 'SP',
        ]));
        $writer->close();

        return response()->download($path, 'modelo-empresas.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Importa a planilha de empresas. O resultado volta em dois canais, como
     * no lote de Usuários: o total em `status` e as linhas recusadas no
     * bag `batch`, para a tela mostrar as duas metades juntas.
     */
    public function batch(Request $request, BatchImportCompanies $batch): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt'],
        ]);

        try {
            $result = $batch->handle($request->file('file')->getRealPath());
        } catch (DomainException $e) {
            return back()->withErrors(['batch' => $e->getMessage()]);
        }

        $message = "{$result['created']} empresas cadastradas e {$result['updated']} atualizadas.";

        if ($result['errors'] !== []) {
            $details = collect($result['errors'])
                ->map(fn (array $e) => "Linha {$e['row']}: {$e['message']}")
                ->implode(' | ');

            return back()->with('status', $message)->withErrors(['batch' => $details]);
        }

        return back()->with('status', $message);
    }

    /**
     * Cria o Admin Cliente vinculado à empresa e envia o convite.
     *
     * O nascimento em si mora no serviço, compartilhado com o cadastro manual
     * de Projeto — é lá que a empresa nova aparece, e ela não se conclui sem
     * responsável.
     */
    public function storeAdmin(Request $request, Company $company, RegisterClientAdmin $registerAdmin): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Quem confere se o e-mail está livre é o RegisterClientAdmin: ele
            // também reconhece o admin desativado desta empresa, que volta em
            // vez de ser recusado.
            'email' => ['required', 'email', 'max:255'],
        ]);

        try {
            $user = $registerAdmin->handle($company, $data, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        Auditoria::registrar(AuditLog::COMPANY_ADMIN_CREATED, "Criou o Admin Cliente {$user->name} ({$user->email}) para {$company->name}.", $user, [
            'empresa_id' => $company->id,
        ]);

        return back()->with('status', 'Admin Cliente criado. Convite enviado para '.$user->email.'.');
    }

    /**
     * Tirar um Admin Cliente da empresa. Chamado da ficha do Cliente e da aba
     * Admin de qualquer Projeto dela, que é onde a WeGift procura quem cuida
     * de um Projeto.
     */
    public function destroyAdmin(Company $company, User $user, RemoveClientAdmin $removeAdmin): RedirectResponse
    {
        try {
            $apagado = $removeAdmin->handle($company, $user);
        } catch (DomainException $e) {
            return back()->withErrors(['remove' => $e->getMessage()]);
        }

        Auditoria::registrar(
            $apagado ? AuditLog::USER_DELETED : AuditLog::USER_DEACTIVATED,
            ($apagado ? 'Apagou' : 'Desativou')." o Admin Cliente {$user->name} ({$user->email}) de {$company->name}.",
            $apagado ? null : $user,
            ['empresa_id' => $company->id],
        );

        return back()->with('status', "{$user->name} não é mais Admin Cliente de {$company->name}.");
    }

    /**
     * Reenvia o convite de um Admin Cliente que ainda não definiu a senha.
     * O e-mail pode se perder (spam, caixa cheia, link expirado): o WeGift
     * precisa conseguir disparar de novo sem recriar o usuário.
     */
    /**
     * Liga/desliga UMA customização da Empresa.
     *
     * Uma chave por chamada, e não o objeto inteiro: o modal alterna uma
     * feature de cada vez, e mandar o conjunto todo faria dois operadores em
     * telas abertas ao mesmo tempo sobrescreverem o ajuste um do outro.
     *
     * `Rule::in` sobre as chaves conhecidas fecha a porta para gravar lixo no
     * JSON — sem isso, qualquer nome viraria uma chave órfã no banco.
     */
    /**
     * Grava quais colunas a planilha de importação deste Cliente usa.
     *
     * Recebe só as opcionais marcadas. As travadas (nome, e-mail) nem entram
     * na validação: o model as devolve sempre, então mandá-las ou não dá no
     * mesmo — e aceitar "email desmarcado" aqui seria abrir uma porta que a
     * tela fecha só no visual.
     */
    public function updateImportColumns(Company $company, Request $request): RedirectResponse
    {
        $opcionais = array_keys(array_filter(Company::IMPORT_COLUMNS, fn (array $c) => ! $c['locked']));

        $dados = $request->validate([
            'columns' => ['present', 'array'],
            'columns.*' => ['string', Rule::in(array_keys(Company::IMPORT_COLUMNS))],
        ]);

        $marcadas = array_values(array_intersect($opcionais, $dados['columns']));

        $company->update([
            'customizations' => [
                ...($company->customizations ?? []),
                // Escolher campos é ligar a chave: a seleção só vale com ela.
                'import_fields' => true,
                // Guarda a lista completa, travadas incluídas: o JSON lido por
                // outra ferramenta diz a verdade sem precisar da regra do model.
                'import_columns' => [
                    ...array_keys(array_filter(Company::IMPORT_COLUMNS, fn (array $c) => $c['locked'])),
                    ...$marcadas,
                ],
            ],
        ]);

        return back()->with('status', "Campos para resgate de {$company->name} atualizados.");
    }

    /** Onde a logo aparece: no topo das telas do Cliente, no Projeto, ou nos dois. */
    public function updateLogoLevels(Company $company, Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'levels' => ['present', 'array'],
            'levels.*' => ['string', Rule::in(array_keys(Company::LOGO_NIVEIS))],
        ]);

        $company->update([
            'customizations' => [
                ...($company->customizations ?? []),
                'logo_niveis' => array_values(array_intersect(array_keys(Company::LOGO_NIVEIS), $dados['levels'])),
            ],
        ]);

        return back()->with('status', "Exibição da logo de {$company->name} atualizada.");
    }

    /** O e-mail de atendimento que o portal do Colaborador mostra. */
    public function updateSupportEmail(Company $company, Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
        ], [], ['email' => 'e-mail de atendimento']);

        $company->update([
            'customizations' => [
                ...($company->customizations ?? []),
                'support_email' => mb_strtolower(trim((string) ($dados['email'] ?? ''))) ?: null,
            ],
        ]);

        return back()->with('status', "E-mail de atendimento de {$company->name} atualizado.");
    }

    /**
     * Endereço e aviso da página de primeiro acesso deste Cliente.
     *
     * O endereço antigo fica guardado: ele está publicado na intranet do
     * Cliente, e quem clicar nele é levado ao novo em vez de cair num erro.
     */
    public function updateFirstAccess(Company $company, Request $request): RedirectResponse
    {
        // "Sotreq Brindes" vira "sotreq-brindes": quem digita não precisa
        // saber as regras de um endereço.
        $request->merge(['slug' => Str::slug((string) $request->input('slug'))]);

        $dados = $request->validate([
            'slug' => [
                'required', 'string', 'min:2', 'max:60',
                Rule::unique('companies', 'first_access_slug')->ignore($company->id),
            ],
            'dica' => ['nullable', 'string', 'max:300'],
        ], [], ['slug' => 'endereço', 'dica' => 'aviso de "Não achou?"']);

        $dono = Company::porSlugAntigoDePrimeiroAcesso($dados['slug']);

        if ($dono !== null && ! $dono->is($company)) {
            return back()->withErrors(['slug' => 'Esse endereço já foi de outro Cliente e ainda leva até ele.']);
        }

        $atual = $company->slugDePrimeiroAcesso();
        $antigos = collect($company->slugsAntigosDePrimeiroAcesso())
            ->when($atual !== $dados['slug'], fn ($c) => $c->push($atual))
            ->reject(fn (string $s) => $s === $dados['slug'])
            ->unique()
            ->values()
            ->all();

        $company->forceFill([
            'first_access_slug' => $dados['slug'],
            'customizations' => [
                ...($company->customizations ?? []),
                'first_access_dica' => trim((string) ($dados['dica'] ?? '')) ?: null,
                'first_access_slugs_antigos' => $antigos,
            ],
        ])->save();

        Auditoria::registrar(
            AuditLog::COMPANY_UPDATED,
            "Atualizou a página de primeiro acesso de {$company->name} (/primeiro-acesso/{$dados['slug']}).",
            $company,
            ['endereco_anterior' => $atual, 'endereco' => $dados['slug']],
        );

        return back()->with('status', "Página de primeiro acesso de {$company->name} atualizada.");
    }

    /** Logo do Cliente: a marca da empresa no topo das telas do Admin Cliente. */
    public function storeLogo(Company $company, Request $request, StoreLogo $storeLogo): RedirectResponse
    {
        $request->validate(...StoreLogo::regras());

        $storeLogo->handle($company, $request->file('logo'));

        return back()->with('status', "Logo de {$company->name} atualizada.");
    }

    public function destroyLogo(Company $company, StoreLogo $storeLogo): RedirectResponse
    {
        $storeLogo->remove($company);

        return back()->with('status', "Logo de {$company->name} removida.");
    }

    public function updateCustomization(Company $company, Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'key' => ['required', 'string', Rule::in(array_keys(Company::CUSTOMIZATIONS))],
            'enabled' => ['required', 'boolean'],
        ]);

        $company->update([
            'customizations' => [...($company->customizations ?? []), $dados['key'] => $dados['enabled']],
        ]);

        $rotulo = Company::CUSTOMIZATIONS[$dados['key']]['label'];

        Auditoria::registrar(
            AuditLog::COMPANY_UPDATED,
            ($dados['enabled'] ? 'Ativou' : 'Desativou')." \"{$rotulo}\" para {$company->name}.",
            $company,
            ['customizacao' => $dados['key'], 'ligada' => $dados['enabled']],
        );

        return back()->with('status', $dados['enabled']
            ? "{$rotulo} ativada para {$company->name}."
            : "{$rotulo} desativada para {$company->name}.");
    }

    public function resendAdminInvite(Company $company, User $user, InviteUser $inviteUser): RedirectResponse
    {
        abort_unless(
            $user->role === User::ROLE_ADMIN_CLIENT && $user->company_id === $company->id,
            404,
        );

        if ($user->hasSetPassword()) {
            return back()->withErrors(['invite' => 'Este Admin Cliente já definiu a senha.']);
        }

        $inviteUser->handle($user);

        Auditoria::registrar(AuditLog::USER_INVITE_SENT, "Reenviou o convite de acesso para {$user->email}.", $user);

        return back()->with('status', 'Convite reenviado para '.$user->email.'.');
    }

    /**
     * Exclusão definitiva da empresa: leva Projetos (com resgates e rastreio)
     * e todas as contas vinculadas (Admin Cliente e Usuários do portal).
     * Rota no grupo role:admin: só o Admin WeGift chega aqui.
     */
    public function destroy(Company $company, ProjectPurger $purger): RedirectResponse
    {
        $nome = $company->name;
        $apagados = $purger->apagarEmpresa($company);

        Log::info('Empresa excluída pelo Admin WeGift.', [
            'company' => $nome,
            'user_id' => request()->user()?->id,
        ] + $apagados);

        Auditoria::registrar(AuditLog::COMPANY_DELETED, "Excluiu o Cliente {$nome} e tudo vinculado a ele.", dados: [
            'empresa_id' => $company->id,
            'cnpj' => $company->cnpj,
        ] + $apagados);

        return redirect()->route('admin.companies.index')->with(
            'status',
            "Empresa {$nome} excluída: {$apagados['projects']} projeto(s), {$apagados['redemptions']} resgate(s) e {$apagados['users']} conta(s) removidos.",
        );
    }
}

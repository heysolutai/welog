<?php

namespace App\Http\Middleware;

use App\Models\AppnozImportRow;
use App\Models\Carrier;
use App\Models\EntitlementImport;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use App\Support\SupportContact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Props compartilhadas com todas as páginas: usuário autenticado (com o
     * papel, para o menu e as permissões no frontend) e mensagens flash.
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'is_operator' => $user->isOperator(),
                    'can_critical' => $user->canPerformCriticalActions(),
                    'is_admin' => $user->role === User::ROLE_ADMIN_WEGIFT,
                    'is_client_admin' => $user->isClientAdmin(),
                    // Admin Projeto (UAT): mesmas telas do Admin Cliente,
                    // escopadas ao Projeto dele. O front usa para o badge de
                    // papel e para esconder o cadastro de admins.
                    'is_project_admin' => $user->isProjectAdmin(),
                    'is_end_customer' => $user->isEndCustomer(),
                    // A marca da empresa no topo das telas do lado do Cliente,
                    // quando a WeGift ligou a logo no nível Cliente.
                    'company_logo_url' => $user->isClientSideAdmin() ? $user->company?->logoDoClienteUrl() : null,
                    // O menu Mensagens (Estúdio de mensagens) só existe para o
                    // Cliente cujos e-mails de resgate a WeLog envia: com eles
                    // desligados, não há mensagem a personalizar.
                    'can_edit_messages' => $user->isClientSideAdmin()
                        && ($user->company?->customizacao('welog_sends_emails') ?? false),
                    // Admin que também resgata (liberado pelo Admin Cliente ou
                    // pela WeGift no cadastro): o atalho "Meu presente" no
                    // cabeçalho. Só aparece com o Projeto já liberado.
                    'meu_presente' => $user->isClientSideAdmin() && ProjectEntitlement::query()
                        ->where('user_id', $user->id)
                        ->whereHas('project', fn ($q) => $q->whereNotNull('released_at'))
                        ->exists(),
                    // A logo do Projeto no topo do portal de quem resgata.
                    'portal_logo_url' => $user->isEndCustomer() ? $this->logoDoPortal($user) : null,
                    // Os textos da tela com a voz da campanha (saudação,
                    // chamada da escolha, título das entregas), do Projeto da
                    // pessoa. Só os trocados: o resto a tela escreve o padrão.
                    'portal_texts' => $user->isEndCustomer() ? (object) $this->textosDoPortal($user) : null,
                ] : null,
            ],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                // Planilha pequena com linhas recusadas: o link para baixá-las.
                'import_failures_url' => fn () => $request->session()->get('import_failures_url'),
            ],
            // As planilhas em fila que a pessoa subiu e que ainda interessam
            // (correndo ou recém-terminadas). É o ponto de partida do aviso
            // flutuante, que acompanha a importação em qualquer tela e avisa
            // quando ela termina.
            'importacoes' => fn () => $user && ! $user->isEndCustomer()
                ? EntitlementImport::acompanhadasPor($user)->with('project')->limit(5)->get()
                    ->map(fn (EntitlementImport $i) => $i->resumo())->values()
                : [],
            // Contato de suporte da WeGift (UAT): por onde o Usuário pede
            // correção de dados. O portal mostra no rodapé; campos nulos quando
            // não configurado, e aí o front esconde o bloco. Vem da config —
            // custo zero, não precisa ser lazy.
            'support' => $this->suporte($user),
            // Transportadoras cadastradas. Compartilhada porque as quatro
            // telas que mostram rastreio precisam da mesma tabela — o nome
            // para exibir, o molde para transformar código em link e o campo
            // que a expedição deve pedir. Inclui as inativas: resgate antigo
            // continua tendo de dizer por quem foi.
            'carriers' => fn () => $this->carriers(),
            // O sino do cabeçalho. Não existe sistema de notificação, e não
            // vale inventar um para pintar um número: o que ele mostra é a
            // contagem REAL de coisas paradas esperando um operador.
            //
            // Closure = prop lazy do Inertia: só é avaliada quando a página
            // realmente a pede, então navegação parcial não paga as queries.
            'pendingCount' => fn () => $this->pendingCount($user),
            // O que está por trás do número: o painel do sino lista os
            // Projetos parados aguardando liberação, com link direto (UAT:
            // "o sino tem de abrir as notificações, não a aba Projetos").
            'pendingProjects' => fn () => $this->pendingProjects($user),
        ]);
    }

    /**
     * Os canais de suporte que o portal mostra.
     *
     * O Cliente com "Atendimento no portal" ligado tem o próprio e-mail: ele
     * substitui o padrão da WeGift em todo o portal — no "Precisa corrigir?" e
     * na faixa do topo (`banner`) —, para o Colaborador não ver dois
     * endereços e não saber qual usar.
     *
     * @return array{email: ?string, whatsapp: ?string, whatsapp_link: ?string, banner: bool}
     */
    private function suporte(?User $user): array
    {
        $canais = SupportContact::channels();
        $doCliente = $user?->isEndCustomer() ? $user->company?->emailDeAtendimento() : null;

        return $doCliente
            ? ['email' => $doCliente, 'banner' => true] + $canais
            : $canais + ['banner' => false];
    }

    /**
     * A logo da campanha no topo do portal: a do Projeto mais recente da
     * pessoa que tenha logo, quando o Cliente ligou a logo no nível
     * Colaborador. Um Colaborador costuma estar num Projeto só; com dois, vale
     * a campanha mais nova.
     */
    private function logoDoPortal(User $user): ?string
    {
        if (! $user->company?->mostraLogoNo('colaborador')) {
            return null;
        }

        return Project::query()
            ->whereIn('id', $user->entitlements()->select('project_id'))
            ->whereNotNull('logo_path')
            ->latest()
            ->first()
            ?->logo_url;
    }

    /**
     * Os textos da tela do Projeto liberado mais recente da pessoa. Um
     * Colaborador costuma estar numa campanha só; com duas, vale a mais nova,
     * como na logo.
     *
     * @return array<string, string>
     */
    private function textosDoPortal(User $user): array
    {
        return Project::query()
            ->whereIn('id', $user->entitlements()->select('project_id'))
            ->whereNotNull('released_at')
            ->latest('released_at')
            ->first()
            ?->textosDoPortal() ?? [];
    }

    /**
     * A tabela de transportadoras, cacheada por 5 minutos.
     *
     * É cadastro que muda quando se contrata alguém — não vale uma consulta
     * por requisição. O cache cai junto com a gravação, no CarrierController,
     * para o operador não expedir com a lista de antes.
     *
     * @return array<int, array<string, mixed>>
     */
    private function carriers(): array
    {
        return Cache::remember(Carrier::SHARED_CACHE_KEY, 300, fn () => Carrier::orderBy('name')
            ->get()
            ->map(fn (Carrier $c) => [
                'slug' => $c->slug,
                'name' => $c->name,
                'tracking_field' => $c->tracking_field,
                'tracking_url_template' => $c->tracking_url_template,
                'active' => $c->active,
                // Só os Correios rastreiam sozinhos: as demais dependem de
                // alguém marcar a entrega na mão.
                'integrated' => $c->isCorreios(),
            ])
            ->all());
    }

    /**
     * Itens que dependem de ação humana AGORA: projeto com estoque disponível
     * esperando liberação, e linha de importação bloqueada.
     *
     * Cacheado por 60s — são duas contagens em toda requisição de quem opera,
     * e o número não precisa ser exato ao segundo para dizer "olhe aqui".
     */
    private function pendingCount(?User $user): int
    {
        if (! $user?->isOperator()) {
            return 0;
        }

        return Cache::remember('dashboard:pending-count', 60, fn () => Project::where('pipeline_status', Project::STATUS_IN_STOCK)->whereNull('released_at')->count()
            + AppnozImportRow::whereIn('status', AppnozImportRow::BLOCKED_STATUSES)->count());
    }

    /**
     * Os Projetos aguardando liberação que o painel do sino lista — a mesma
     * régua do pendingCount (em estoque, não liberado) e o mesmo cache de 60s.
     * Seis no máximo: painel suspenso é atalho, não lista; o "Ver todos" do
     * rodapé leva à lista filtrada completa.
     *
     * @return array<int, array{id: int, name: string, omie_order_number: ?string}>
     */
    private function pendingProjects(?User $user): array
    {
        if (! $user?->isOperator()) {
            return [];
        }

        return Cache::remember('dashboard:pending-projects', 60, fn () => Project::query()
            ->where('pipeline_status', Project::STATUS_IN_STOCK)
            ->whereNull('released_at')
            ->latest()
            ->limit(6)
            ->get(['id', 'name', 'omie_order_number'])
            ->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'omie_order_number' => $p->omie_order_number,
            ])
            ->all());
    }
}

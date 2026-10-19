<?php

namespace App\Services\FluxoA;

use App\Exceptions\DomainException;
use App\Jobs\FluxoA\SendReleaseNotice;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use App\Services\Auth\InviteUser;
use App\Support\Values\Telefone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Módulo 7 — cadastro individual de Colaborador vinculado a um Projeto.
 * Exclusivo da WeLog (sem interação com a Omie). Pode ser executado pelo
 * operador WeGift ou pelo admin da empresa cliente (do mesmo workspace).
 *
 * Aceita um ou vários produtos por pessoa (UAT):
 *  - `mode = all`: uma autorização independente por produto (a pessoa recebe
 *    todos);
 *  - `mode = choose`: as autorizações nascem no mesmo `choice_group` e viram
 *    OPÇÕES — a pessoa resgata só uma; as demais ficam indisponíveis.
 *
 * `product_id` singular continua aceito (vira `product_ids` de um item, modo
 * `all`) para não quebrar o lote CSV e chamadas antigas.
 */
class RegisterEndCustomer
{
    public const MODE_ALL = 'all';

    public const MODE_CHOOSE = 'choose';

    public function __construct(private readonly InviteUser $inviteUser) {}

    /**
     * Senha provisória da conta nova: o hash de um segredo aleatório que
     * ninguém conhece e que é descartado na hora. A conta não entra com ela;
     * a pessoa cria a própria senha pelo convite ou pelo primeiro acesso.
     *
     * Calculada uma vez por instância (um lote da planilha, uma requisição) e
     * não uma por pessoa: o bcrypt é lento de propósito, de 0,2 a 0,4s na
     * VPS, e era ele que limitava a importação a umas 200 linhas por minuto.
     * Repetir o hash entre contas não abre nada, porque o segredo por trás é
     * aleatório e não fica guardado em lugar nenhum.
     */
    private ?string $senhaProvisoria = null;

    private function senhaProvisoria(): string
    {
        return $this->senhaProvisoria ??= Hash::make(Str::password(32));
    }

    /**
     * Consultas que dão o mesmo resultado para todas as linhas de um lote da
     * planilha (os itens do pedido e os pais de grade dos itens escolhidos),
     * guardadas na instância: cinquenta idas iguais ao banco por lote viram
     * uma. A instância vive um lote (ou uma requisição), e o pedido não muda
     * no meio dele.
     *
     * @var array<int, Collection<int, int>> id do Projeto => ids dos itens
     */
    private array $itensDoPedido = [];

    /** @var array<string, array<int, Product>> ids dos itens => pais da grade */
    private array $paisDaGrade = [];

    /**
     * `choice_product_ids` soma, às de `product_ids`, um grupo de escolha: a
     * planilha dá à mesma pessoa itens garantidos (X) e "escolhe 1" entre
     * outros (O). Os dois modos numa pessoa só, o que `mode` sozinho não diz.
     *
     * `admin_com_resgate`: o Admin DESTE Projeto também resgata (o Admin
     * Cliente ou a WeGift ligou no cadastro dele). Só quem chama sabe que a
     * permissão foi dada; sem a chave, conta de admin continua recusada.
     *
     * @param  array{name: string, email: string, cpf?: ?string, phone?: ?string,
     *               product_id?: int, product_ids?: array<int, int>, mode?: ?string,
     *               choice_product_ids?: array<int, int>, admin_com_resgate?: bool}  $data
     * @return ProjectEntitlement a primeira autorização criada (compatível com
     *                            quem sempre recebeu uma só)
     */
    public function handle(Project $project, array $data, User $actor): ProjectEntitlement
    {
        $this->authorize($project, $actor);

        [$productIds, $choiceIds] = $this->normalizeProductIds($data);
        $mode = $data['mode'] ?? self::MODE_ALL;

        if (! in_array($mode, [self::MODE_ALL, self::MODE_CHOOSE], true)) {
            throw new DomainException('Modo de autorização inválido. Use "all" ou "choose".');
        }

        $this->ensureProductsBelongToOrder($project, [...$productIds, ...$choiceIds]);

        $adminComResgate = false;

        [$entitlements, $newUser] = DB::transaction(function () use ($project, $data, $productIds, $choiceIds, $mode, &$adminComResgate) {
            $user = User::firstOrCreate(
                ['email' => strtolower(trim($data['email']))],
                [
                    'name' => $data['name'],
                    // Senha provisória, já em hash (ver senhaProvisoria): o
                    // cliente define a sua via convite.
                    'password' => $this->senhaProvisoria(),
                    'password_set_at' => null,
                    'role' => User::ROLE_END_CUSTOMER,
                    'company_id' => $project->company_id,
                    'cpf' => isset($data['cpf']) ? preg_replace('/\D/', '', $data['cpf']) : null,
                    // Sempre +55DDDNÚMERO. O que não dá para entender como
                    // telefone não entra: a validação da planilha já avisou.
                    'phone' => Telefone::tentar($data['phone'] ?? null)?->e164,
                ],
            );

            /*
             * Admin desta empresa que foi tirado e ficou desativado (tinha
             * histórico): a conta não prende o e-mail. Volta como Colaborador,
             * do mesmo jeito que pode voltar como admin (ReuseInactiveAdmin).
             */
            if (! $user->wasRecentlyCreated
                && ! $user->active
                && in_array($user->role, [User::ROLE_ADMIN_CLIENT, User::ROLE_PROJECT_ADMIN], true)
                && (int) $user->company_id === (int) $project->company_id) {
                $user->forceFill([
                    'role' => User::ROLE_END_CUSTOMER,
                    'admin_project_id' => null,
                    'active' => true,
                    'name' => $data['name'],
                ])->save();
            }

            // O Admin deste Projeto com o resgate liberado: a mesma conta
            // administra e resgata, e entra na lista como Colaborador.
            $adminComResgate = ($data['admin_com_resgate'] ?? false)
                && $user->isProjectAdmin()
                && (int) $user->admin_project_id === (int) $project->id;

            if ($user->role !== User::ROLE_END_CUSTOMER && ! $adminComResgate) {
                // O e-mail já é uma conta de acesso ao sistema (Admin Cliente,
                // Admin Projeto ou equipe WeGift). Uma conta tem um papel só:
                // quem administra não resgata com o mesmo login — precisa de
                // outro e-mail.
                $who = match (true) {
                    $user->isClientAdmin() => 'ao Admin Cliente '.$user->name,
                    $user->isProjectAdmin() => 'ao Admin Projeto '.$user->name,
                    default => 'a uma conta da equipe WeGift ('.$user->name.')',
                };

                throw new DomainException(
                    'Este e-mail já pertence '.$who.'. Para cadastrar essa pessoa como Colaborador, use outro e-mail.',
                );
            }

            if ((int) $user->company_id !== (int) $project->company_id) {
                // Fora de qualquer Projeto da outra empresa: a conta vem para
                // esta, em vez de o e-mail ficar bloqueado para sempre.
                if (! $user->colaboradorSemProjeto()) {
                    throw new DomainException(
                        'Este e-mail já é Colaborador de '.($user->company?->name ?? 'outra Empresa')
                        .', num Projeto em andamento. Uma pessoa resgata por uma empresa de cada vez.',
                    );
                }

                $user->forceFill(['company_id' => $project->company_id, 'name' => $data['name']])->save();
            }

            // Colaborador desativado que volta a ser cadastrado volta a entrar.
            if (! $user->active) {
                $user->forceFill(['active' => true])->save();
            }

            // Grupo de escolha só faz sentido com 2+ opções: escolher entre um
            // item só é o mesmo que recebê-lo.
            $choiceGroup = ($mode === self::MODE_CHOOSE && count($productIds) > 1)
                ? (string) Str::uuid()
                : null;

            // As opções da planilha (O) formam o seu próprio grupo.
            $grupoDasOpcoes = count($choiceIds) > 1 ? (string) Str::uuid() : null;

            /*
             * Tamanhos da mesma grade da Omie são SEMPRE alternativas.
             *
             * O pedido pode vir com os tamanhos como itens (Colete P, M, G…),
             * e marcar o Colete no cadastro marca todos eles. "Recebe todos"
             * daria à pessoa um colete de cada tamanho: os irmãos viram um
             * grupo de escolha próprio, e ela fica com um, no tamanho que
             * escolher no portal. Com "escolhe um" o grupo já é um só.
             */
            $grupoDaGrade = [];

            if ($choiceGroup === null) {
                $chave = collect($productIds)->sort()->implode(',');
                $paiDe = $this->paisDaGrade[$chave] ??= Product::paisDaGrade(Product::whereIn('id', $productIds)->get());

                // Pelo código do pai, não pelo id: o pai pode ser só de memória
                // (a Omie tem os tamanhos e não o pai), e aí não há id.
                foreach (collect($productIds)->filter(fn (int $id) => isset($paiDe[$id]))->groupBy(fn (int $id) => mb_strtolower((string) $paiDe[$id]->omie_code)) as $irmaos) {
                    if ($irmaos->count() > 1) {
                        $grupo = (string) Str::uuid();
                        $irmaos->each(function (int $id) use (&$grupoDaGrade, $grupo) {
                            $grupoDaGrade[$id] = $grupo;
                        });
                    }
                }
            }

            // Item => grupo de escolha, na ordem do cadastro (garantidos, depois
            // as opções): a primeira autorização é a que o chamador recebe.
            $grupos = [];

            foreach ($productIds as $id) {
                $grupos[$id] = $choiceGroup ?? $grupoDaGrade[$id] ?? null;
            }

            // Item nas duas listas fica como garantido (o grupo dele pode ser
            // null, por isso `array_key_exists` e não `??=`).
            foreach ($choiceIds as $id) {
                if (! array_key_exists($id, $grupos)) {
                    $grupos[$id] = $grupoDasOpcoes;
                }
            }

            /*
             * Todas as autorizações da pessoa de uma vez: três idas ao banco,
             * seja um item ou nove. Uma a uma (`firstOrCreate`), eram duas ou
             * três por item — com o Colete em sete tamanhos e mais os outros
             * itens, umas trinta por linha da planilha, e a importação caía
             * para 240 linhas por minuto.
             *
             * Continua idempotente por (projeto, usuário, produto): recadastrar
             * a mesma pessoa não duplica autorização nem rearranja um grupo de
             * escolha existente. `insertOrIgnore` cobre a corrida de dois
             * cadastros simultâneos da mesma pessoa (a chave é única).
             */
            $doUsuario = fn () => ProjectEntitlement::query()
                ->where('project_id', $project->id)
                ->where('user_id', $user->id)
                ->whereIn('product_id', array_keys($grupos));

            $existentes = $doUsuario()->pluck('product_id')->flip();
            $agora = now();

            $novas = collect($grupos)
                ->reject(fn (?string $grupo, int $id) => $existentes->has($id))
                ->map(fn (?string $grupo, int $id) => [
                    'project_id' => $project->id,
                    'user_id' => $user->id,
                    'product_id' => $id,
                    'choice_group' => $grupo,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ])
                ->values()
                ->all();

            if ($novas !== []) {
                ProjectEntitlement::insertOrIgnore($novas);
            }

            $ordem = array_flip(array_keys($grupos));
            $entitlements = $doUsuario()->get()->sortBy(fn (ProjectEntitlement $e) => $ordem[$e->product_id])->values();

            return [$entitlements, $user->wasRecentlyCreated];
        });

        /** @var ProjectEntitlement $first */
        $first = $entitlements->first();

        /*
         * Projeto JÁ liberado: quem entra depois recebe o aviso na hora.
         *
         * A liberação notificava só quem estava cadastrado naquele instante, e
         * cadastrar alguém depois — o que acontece o tempo todo: contratação
         * nova, alguém que faltou na planilha — deixava a pessoa sem saber que
         * tem presente esperando. Ninguém percebia, porque na tela ela aparece
         * igual às outras.
         *
         * O Admin com resgate não recebe o aviso aqui: ele acabou de ganhar o
         * convite de acesso, e o aviso leva outro link de criar senha, que
         * invalidaria o do convite. Ele encontra o presente em "Meu presente".
         */
        if ($project->isReleased() && ! $adminComResgate) {
            $this->notificarLiberacao($entitlements, $project);

            return $first;
        }

        /*
         * Convite de primeiro acesso apenas para clientes recém-criados que
         * ainda não definiram senha (não reenvia a quem já tem acesso).
         *
         * E só quando a WeLog é quem fala com o colaborador. Com o
         * interruptor desligado, ESTE convite é o único e-mail que ainda
         * partia daqui sem ninguém ter pedido — nove mil mensagens de um
         * remetente que o pessoal do Cliente não reconhece, exatamente o que
         * desligar o interruptor deveria impedir.
         *
         * O colaborador continua tendo como entrar: ele pede o link na tela de
         * primeiro acesso, e aí sim recebe o e-mail. A diferença é quem começa
         * a conversa.
         */
        if ($newUser && ! $first->user->hasSetPassword() && $this->plataformaEscreve($project)) {
            // Token na fila de e-mail, não aqui: ver InviteUser::handleLater.
            $this->inviteUser->handleLater($first->user);
        }

        return $first;
    }

    /**
     * A WeLog faz o PRIMEIRO contato com o colaborador deste Projeto?
     *
     * É a etapa "Presente liberado" do Estúdio de mensagens que decide, e não
     * só a chave geral: o convite de acesso é o outro primeiro envio. Cliente
     * que quer os e-mails a partir do resgate, mas divulga a campanha ele
     * mesmo (a Sotreq), desliga essa etapa — e aí o caminho de entrada é o
     * link de primeiro acesso, que passa a existir.
     *
     * Projeto sem Cliente vinculado responde que sim: a ausência de cadastro
     * não é escolha de ninguém, e calar por omissão deixaria a pessoa sem
     * saber que tem brinde esperando.
     */
    private function plataformaEscreve(Project $project): bool
    {
        return ! $project->entradaPeloPrimeiroAcesso();
    }

    /**
     * O mesmo e-mail da liberação, para quem chegou depois dela.
     *
     * Substitui o convite de primeiro acesso em vez de somar: esta mensagem já
     * leva o link de criar senha quando falta, e duas mensagens na mesma hora
     * dizendo coisas parecidas é o que faz a segunda parecer golpe.
     *
     * @param  Collection<int, ProjectEntitlement>  $entitlements
     */
    private function notificarLiberacao($entitlements, Project $project): void
    {
        // Sem `where(...)` por notified_at: a autorização acabou de nascer, e
        // uma já notificada não passaria por aqui. Uma gravação para todas as
        // autorizações da pessoa, não uma por item.
        ProjectEntitlement::whereIn('id', $entitlements->pluck('id'))->update(['notified_at' => now()]);

        // Entrada pelo link de primeiro acesso: nada de token nem e-mail.
        // Com a WeLog avisando, o e-mail e o link de criar senha (um bcrypt)
        // saem na fila de e-mail, e a planilha não espera por eles.
        if ($this->plataformaEscreve($project)) {
            foreach ($entitlements as $entitlement) {
                SendReleaseNotice::dispatch($entitlement->id);
            }
        }
    }

    /**
     * `product_ids` (novo) e `product_id` (legado) convergem para uma lista
     * única, sem repetição — repetir o produto criaria "opções" idênticas.
     * `choice_product_ids` vem à parte; item que está nas duas fica só como
     * garantido, porque é o que a pessoa recebe de qualquer jeito.
     *
     * @return array{0: array<int, int>, 1: array<int, int>} [garantidos, opções]
     */
    private function normalizeProductIds(array $data): array
    {
        $ids = array_values(array_unique(array_map(
            'intval',
            (array) ($data['product_ids'] ?? ($data['product_id'] ?? [])),
        )));

        $opcoes = array_values(array_diff(
            array_unique(array_map('intval', (array) ($data['choice_product_ids'] ?? []))),
            $ids,
        ));

        if ($ids === [] && $opcoes === []) {
            throw new DomainException('Selecione ao menos um item do pedido para autorizar.');
        }

        return [$ids, $opcoes];
    }

    /**
     * Falha do UAT: a autorização aceitava componente que só existe DENTRO do
     * BOM de um kit — a pessoa "resgatava" a caneca avulsa de um pedido que só
     * vendeu o kit completo. Autorizável é somente o que está nas LINHAS do
     * pedido (project_products). O mesmo código pode legitimamente ser item do
     * pedido E componente de kit; nesse caso ele passa, porque está no pedido.
     */
    private function ensureProductsBelongToOrder(Project $project, array $productIds): void
    {
        $orderItemIds = $this->itensDoPedido[$project->id] ??= $project->products()->pluck('products.id');
        $foraDoPedido = collect($productIds)->reject(fn (int $id) => $orderItemIds->contains($id));

        if ($foraDoPedido->isEmpty()) {
            return;
        }

        // Diagnóstico específico: se o produto aparece no BOM de um kit do
        // Projeto, a mensagem explica o porquê em vez de um genérico.
        $componentesDosKits = Product::query()
            ->whereIn('id', $orderItemIds)
            ->with('components:id')
            ->get()
            ->flatMap(fn (Product $kit) => $kit->components->pluck('id'))
            ->unique();

        $nomes = Product::whereIn('id', $foraDoPedido)->pluck('name', 'id');

        foreach ($foraDoPedido as $id) {
            if ($componentesDosKits->contains($id)) {
                throw new DomainException(
                    'O item "'.($nomes[$id] ?? $id).'" é componente de um kit deste Projeto, não um item do pedido. '
                        .'Autorize o kit completo ou outro item que conste do pedido.',
                );
            }
        }

        throw new DomainException(
            'O item "'.($nomes[$foraDoPedido->first()] ?? $foraDoPedido->first()).'" não faz parte deste Projeto. '
                .'Só itens do pedido podem ser autorizados.',
        );
    }

    private function authorize(Project $project, User $actor): void
    {
        // WeGift crítica em qualquer Projeto; Admin Cliente nos da empresa dele;
        // Admin Projeto apenas no Projeto dele (managesProject decide).
        $allowed = $actor->canPerformCriticalActions() || $actor->managesProject($project);

        if (! $allowed) {
            throw new DomainException('Sem permissão para cadastrar Colaboradores neste Projeto.');
        }
    }
}

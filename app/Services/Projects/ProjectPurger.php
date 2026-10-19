<?php

namespace App\Services\Projects;

use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Apaga Projetos e o rastro que eles deixaram — usado por
 * `welog:projects:purge` (mantém uma lista) e `welog:projects:purge-non-venda`
 * (mantém os do projeto Omie configurado).
 *
 * A API é "quem FICA", não "quem sai": o operador confere uma lista curta do
 * que sobrevive em vez de uma lista longa do que some, e um id errado vira um
 * Projeto a mais preservado, não um a mais apagado.
 *
 * ── Por que um serviço e não um DELETE ──────────────────────────────────────
 *
 * A exclusão atravessa cinco tabelas em ordem obrigatória, e duas delas o banco
 * NÃO resolve sozinho:
 *
 *   · `redemptions.project_id` é `constrained()` sem cascade — Projeto com
 *     resgate RECUSA a exclusão. Sem tratar, a limpeza sai pela metade e o
 *     erro aparece no meio do caminho, com parte já apagada.
 *   · `tracking_events` é polimórfico e não tem chave estrangeira nenhuma —
 *     apagar resgates deixa os eventos de rastreio órfãos, invisíveis, para
 *     sempre.
 *
 * `apagar()` roda em TRANSAÇÃO: ou vai tudo, ou não vai nada.
 */
class ProjectPurger
{
    /**
     * Contagens ANTES de apagar. É o que a simulação mostra e o que dá ao
     * operador a chance de perceber um número fora do esperado.
     *
     * @param  array<int, int>  $manter  ids dos Projetos que FICAM
     * @return array{projetos: int, resgates: int, rastreios: int, entitlements: int, usuarios: int, empresas: int, empresas_com_usuario: int}
     */
    public function inventariar(array $manter): array
    {
        $projetos = Project::whereNotIn('id', $manter)->pluck('id');
        $resgates = Redemption::whereIn('project_id', $projetos)->pluck('id');

        return [
            'projetos' => $projetos->count(),
            'resgates' => $resgates->count(),
            'rastreios' => $this->trackingQuery($resgates)->count(),
            'entitlements' => ProjectEntitlement::whereIn('project_id', $projetos)->count(),
            'usuarios' => $this->orfaosQuery($projetos, $resgates)->count(),
            'empresas' => $this->empresasVaziasQuery($manter)->count(),
            'empresas_com_usuario' => $this->semProjetoQuery($manter)->has('users')->count(),
        ];
    }

    /**
     * Apaga tudo que NÃO está em `$manter`, em transação.
     *
     * A ordem aqui não é estilo, é a única que o banco aceita: rastreio antes
     * de resgate (senão fica órfão), resgate antes de Projeto (a FK recusa),
     * resgate antes de usuário (`redemptions.user_id` também é restrito).
     *
     * @param  array<int, int>  $manter
     * @return array<string, int> apagados por tabela
     */
    public function apagar(array $manter): array
    {
        return DB::transaction(function () use ($manter) {
            $projetos = Project::whereNotIn('id', $manter)->pluck('id');
            $resgates = Redemption::whereIn('project_id', $projetos)->pluck('id');

            $orfaos = $this->orfaosQuery($projetos, $resgates)->pluck('id');

            $contagem = [
                'tracking_events' => $this->trackingQuery($resgates)->delete(),
                'redemptions' => Redemption::whereIn('id', $resgates)->delete(),
                // Cascata daria conta, mas contar aqui é o que permite conferir o
                // número depois — cascata silenciosa não aparece em relatório.
                'project_entitlements' => ProjectEntitlement::whereIn('project_id', $projetos)->delete(),
                'projects' => Project::whereIn('id', $projetos)->delete(),
                'users' => User::whereIn('id', $orfaos)->delete(),
            ];

            // Empresas por último: só dá para saber quais ficaram vazias depois
            // que os Projetos saíram.
            $contagem['companies'] = $this->empresasVaziasQuery($manter)->delete();

            return $contagem;
        });
    }

    /**
     * Exclusão de UM Projeto pelo Admin WeGift (botão "Excluir projeto").
     *
     * Mesma ordem obrigatória de `apagar()` — rastreio antes de resgate,
     * resgate antes de Projeto — mas o escopo é outro: some só o rastro DESTE
     * Projeto. Usuários e empresa ficam intactos, de propósito: a pessoa pode
     * ter autorização em outro Projeto, e a empresa tem ficha própria.
     *
     * @return array<string, int> apagados por tabela
     */
    public function apagarProjeto(Project $project): array
    {
        return DB::transaction(function () use ($project) {
            $resgates = Redemption::where('project_id', $project->id)->pluck('id');

            return [
                'tracking_events' => $this->trackingQuery($resgates)->delete(),
                'redemptions' => Redemption::whereIn('id', $resgates)->delete(),
                'project_entitlements' => ProjectEntitlement::where('project_id', $project->id)->delete(),
                'projects' => Project::whereKey($project->id)->delete(),
            ];
        });
    }

    /**
     * Tira a pessoa de UM Projeto, mantendo a conta dela.
     *
     * Usado quando o mesmo colaborador participa de outras campanhas: sair de
     * uma não é sair da empresa. O resgate feito NESTE Projeto vai junto, senão
     * ficaria sem autorização que o sustente.
     *
     * @return array<string, int> apagados por tabela
     */
    public function apagarAutorizacao(ProjectEntitlement $entitlement): array
    {
        return DB::transaction(function () use ($entitlement) {
            $resgates = Redemption::where('project_id', $entitlement->project_id)
                ->where('user_id', $entitlement->user_id)
                ->pluck('id');

            return [
                'tracking_events' => $this->trackingQuery($resgates)->delete(),
                'redemptions' => Redemption::whereIn('id', $resgates)->delete(),
                'project_entitlements' => ProjectEntitlement::whereKey($entitlement->id)->delete(),
            ];
        });
    }

    /**
     * Tira UMA pessoa de UM Projeto, com tudo dela ali: as autorizações (uma
     * por item que ela podia receber) e os resgates feitos neste Projeto, com
     * o rastreio. A conta e os outros Projetos dela ficam.
     *
     * Existe porque a tela apagava autorização por autorização: com os
     * tamanhos de uma grade, eram vinte e tantas chamadas em fila, cada uma
     * recarregando a página e mostrando o seu próprio aviso.
     *
     * @return array<string, int> apagados por tabela
     */
    public function tirarDoProjeto(Project $project, User $user): array
    {
        return DB::transaction(function () use ($project, $user) {
            $resgates = Redemption::where('project_id', $project->id)
                ->where('user_id', $user->id)
                ->pluck('id');

            return [
                'tracking_events' => $this->trackingQuery($resgates)->delete(),
                'redemptions' => Redemption::whereIn('id', $resgates)->delete(),
                'project_entitlements' => ProjectEntitlement::where('project_id', $project->id)
                    ->where('user_id', $user->id)
                    ->delete(),
            ];
        });
    }

    /**
     * Exclusão de UMA pessoa e do rastro dela.
     *
     * Some a conta, as autorizações de resgate e os resgates já feitos (com o
     * rastreio). Projeto e empresa ficam: apagar uma pessoa não pode levar o
     * Projeto inteiro junto.
     *
     * A ordem é a mesma de sempre — rastreio antes de resgate, resgate antes
     * do usuário, porque `redemptions.user_id` é restrito.
     *
     * @return array<string, int> apagados por tabela
     */
    public function apagarUsuario(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $resgates = Redemption::where('user_id', $user->id)->pluck('id');

            return [
                'tracking_events' => $this->trackingQuery($resgates)->delete(),
                'redemptions' => Redemption::whereIn('id', $resgates)->delete(),
                'project_entitlements' => ProjectEntitlement::where('user_id', $user->id)->delete(),
                'users' => User::whereKey($user->id)->delete(),
            ];
        });
    }

    /**
     * Exclusão de UMA empresa pelo Admin WeGift (botão "Excluir empresa").
     *
     * Leva a empresa e tudo que é dela: Projetos com o rastro completo
     * (rastreio, resgates, autorizações) e as contas vinculadas (Admin
     * Empresas e Colaboradores). Ordem obrigatória do banco, em
     * transação: ou some tudo, ou nada.
     *
     * @return array<string, int> apagados por tabela
     */
    public function apagarEmpresa(Company $company): array
    {
        return DB::transaction(function () use ($company) {
            $projetos = Project::where('company_id', $company->id)->pluck('id');
            $resgates = Redemption::whereIn('project_id', $projetos)->pluck('id');

            return [
                'tracking_events' => $this->trackingQuery($resgates)->delete(),
                'redemptions' => Redemption::whereIn('id', $resgates)->delete(),
                'project_entitlements' => ProjectEntitlement::whereIn('project_id', $projetos)->delete(),
                'projects' => Project::whereIn('id', $projetos)->delete(),
                // Toda conta da empresa: o Usuário do portal só tem vínculo
                // aqui (autorização exige a mesma empresa), e o Admin Cliente
                // sem empresa cairia num 403 sem saída.
                'users' => User::where('company_id', $company->id)->delete(),
                'companies' => Company::whereKey($company->id)->delete(),
            ];
        });
    }

    /**
     * Inventário da limpeza POR EMPRESA: tudo que não pertence às empresas
     * mantidas some — Projetos, resgates, usuários (inclusive Admin Cliente) e
     * as próprias empresas.
     *
     * @param  array<int, int>  $manterEmpresas  ids das empresas que FICAM
     * @return array<string, int>
     */
    public function inventariarPorEmpresa(array $manterEmpresas): array
    {
        $manter = Project::whereIn('company_id', $manterEmpresas)->pluck('id')->all();
        $inv = $this->inventariar($manter);

        // Aqui a regra é diferente da limpeza por Projeto: quem não é da
        // empresa mantida sai, Admin Cliente incluído — é o que "só essa
        // empresa" significa. Equipe WeGift (sem company_id) nunca entra.
        $inv['usuarios'] = $this->usuariosDeOutrasEmpresasQuery($manterEmpresas)->count()
            + $this->orfaosSemEmpresaQuery($manter, $manterEmpresas)->count();
        $inv['empresas'] = Company::whereNotIn('id', $manterEmpresas)->count();
        $inv['empresas_com_usuario'] = 0;

        return $inv;
    }

    /**
     * Limpeza POR EMPRESA, em transação. Ordem: rastro dos Projetos (mesma
     * ordem de `apagar`), depois usuários das outras empresas, depois as
     * empresas — que a essa altura estão vazias e o banco deixa apagar.
     *
     * @param  array<int, int>  $manterEmpresas
     * @return array<string, int>
     */
    public function apagarPorEmpresa(array $manterEmpresas): array
    {
        return DB::transaction(function () use ($manterEmpresas) {
            $manter = Project::whereIn('company_id', $manterEmpresas)->pluck('id')->all();

            $projetos = Project::whereNotIn('id', $manter)->pluck('id');
            $resgates = Redemption::whereIn('project_id', $projetos)->pluck('id');

            $usuarios = $this->usuariosDeOutrasEmpresasQuery($manterEmpresas)->pluck('id')
                ->merge($this->orfaosSemEmpresaQuery($manter, $manterEmpresas)->pluck('id'))
                ->unique();

            $contagem = [
                'tracking_events' => $this->trackingQuery($resgates)->delete(),
                'redemptions' => Redemption::whereIn('id', $resgates)->delete(),
                'project_entitlements' => ProjectEntitlement::whereIn('project_id', $projetos)->delete(),
                'projects' => Project::whereIn('id', $projetos)->delete(),
            ];

            // Resgates de usuários que vão sair já foram embora acima (todo
            // resgate pertence a um Projeto, e os Projetos deles saíram).
            // O que sobra é o Usuário do portal ainda ligado a um Projeto
            // mantido — não pode existir, a autorização exige a mesma empresa.
            $contagem['users'] = User::whereIn('id', $usuarios)->delete();
            $contagem['companies'] = Company::whereNotIn('id', $manterEmpresas)->delete();

            return $contagem;
        });
    }

    /** Toda conta com company_id fora das mantidas: Admin Cliente e Usuário do portal. */
    private function usuariosDeOutrasEmpresasQuery(array $manterEmpresas)
    {
        return User::whereNotNull('company_id')->whereNotIn('company_id', $manterEmpresas);
    }

    /**
     * Cliente final SEM empresa (dado antigo) que fica sem autorização e sem
     * resgate. Quem é da empresa mantida nunca entra aqui, mesmo sem nada.
     */
    private function orfaosSemEmpresaQuery(array $manterProjetos, array $manterEmpresas)
    {
        $projetos = Project::whereNotIn('id', $manterProjetos)->pluck('id');
        $resgates = Redemption::whereIn('project_id', $projetos)->pluck('id');

        return $this->orfaosQuery($projetos, $resgates)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhereNotIn('company_id', $manterEmpresas));
    }

    private function trackingQuery($resgatesIds)
    {
        return DB::table('tracking_events')
            ->where('trackable_type', Redemption::class)
            ->whereIn('trackable_id', $resgatesIds);
    }

    /**
     * Cliente final que fica sem NENHUMA autorização e sem NENHUM resgate
     * depois da limpeza. Operadores e admins nunca entram aqui — o filtro de
     * papel é o que impede a varredura de apagar quem opera o sistema.
     */
    private function orfaosQuery($projetosIds, $resgatesIds)
    {
        return User::where('role', User::ROLE_END_CUSTOMER)
            ->whereDoesntHave('entitlements', fn ($q) => $q->whereNotIn('project_id', $projetosIds))
            ->whereDoesntHave('redemptions', fn ($q) => $q->whereNotIn('id', $resgatesIds));
    }

    /**
     * Empresa sem Projeto E sem usuário.
     *
     * O "sem usuário" não é zelo excessivo: `users.company_id` é
     * `nullOnDelete`, então apagar a empresa de um admin cliente deixaria a
     * conta apontando para lugar nenhum — e o middleware manda essa pessoa
     * para o 403 sem saída, sem nada na tela explicando por quê.
     */
    private function empresasVaziasQuery(array $manter)
    {
        return $this->semProjetoQuery($manter)->doesntHave('users');
    }

    /**
     * Empresas que ficarão sem Projeto DEPOIS da limpeza.
     *
     * O predicado é "não tem Projeto entre os mantidos" — e não "não tem
     * Projeto nenhum". A diferença importa no inventário, que roda ANTES da
     * exclusão: com o segundo, toda empresa cujos Projetos estão prestes a
     * sumir ainda contaria como ocupada, e a simulação prometeria apagar zero
     * empresa para depois apagar centenas.
     */
    private function semProjetoQuery(array $manter)
    {
        return Company::whereDoesntHave('projects', fn ($q) => $q->whereIn('id', $manter));
    }
}

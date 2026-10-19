<?php

namespace App\Services\Reports;

use App\Models\Redemption;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Onde cada Colaborador está na jornada do presente, para o Acompanhamento.
 *
 * A conta é por pessoa em cada Projeto, não por resgate: o que o RH precisa
 * saber é quantos foram cadastrados e quem ainda não resgatou. Uma pessoa
 * com mais de um item resgatado fica na etapa MAIS ATRASADA deles: só é
 * "entregue" quando tudo dela chegou.
 *
 * É a situação de hoje, sem período: "faltam resgatar" não acontece numa data.
 */
class RedemptionFunnel
{
    public const FALTA = 'falta';

    public const SEPARACAO = 'separacao';

    public const CAMINHO = 'caminho';

    public const ENTREGUE = 'entregue';

    public const SITUACOES = [
        self::FALTA => 'Faltam resgatar',
        self::SEPARACAO => 'Em separação',
        self::CAMINHO => 'A caminho',
        self::ENTREGUE => 'Entregues',
    ];

    /** @return array{total: int, por_situacao: array<string, int>} */
    public function resumo(array $escopo): array
    {
        $contagem = DB::query()
            ->fromSub($this->pares($escopo), 'pares')
            ->selectRaw('situacao, count(*) as total')
            ->groupBy('situacao')
            ->pluck('total', 'situacao');

        return [
            'total' => (int) $contagem->sum(),
            'por_situacao' => collect(array_keys(self::SITUACOES))
                ->mapWithKeys(fn (string $s) => [$s => (int) ($contagem[$s] ?? 0)])
                ->all(),
        ];
    }

    /**
     * As pessoas de uma situação (ou de todas), com nome e e-mail, para a
     * lista que abre ao clicar no cartão e para o arquivo exportado.
     */
    public function pessoas(array $escopo, ?string $situacao, ?string $busca): Builder
    {
        return DB::query()
            ->fromSub($this->pares($escopo), 'pares')
            ->join('users', 'users.id', '=', 'pares.user_id')
            ->join('projects', 'projects.id', '=', 'pares.project_id')
            ->leftJoin('companies', 'companies.id', '=', 'projects.company_id')
            ->when($situacao, fn ($q, $s) => $q->where('pares.situacao', $s))
            ->when(filled($busca), function ($q) use ($busca) {
                $termo = '%'.mb_strtolower(trim($busca)).'%';

                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(users.name) LIKE ?', [$termo])
                    ->orWhereRaw('LOWER(users.email) LIKE ?', [$termo]));
            })
            ->select([
                'pares.project_id', 'pares.user_id', 'pares.situacao', 'pares.resgatado_em',
                'users.name', 'users.email', 'users.password_set_at', 'users.cpf', 'users.phone',
                'projects.name as projeto', 'projects.omie_order_number',
                'companies.name as empresa',
            ])
            ->orderBy('users.name')
            ->orderBy('pares.project_id');
    }

    /**
     * O que cada pessoa da página resgatou: itens, rastreio e entrega. Uma
     * consulta para a página inteira, não uma por linha.
     *
     * @param  Collection<int, object>  $linhas
     * @return Collection<string, Collection<int, Redemption>> chave "projeto:pessoa"
     */
    public function resgatesDe(Collection $linhas): Collection
    {
        if ($linhas->isEmpty()) {
            return collect();
        }

        return Redemption::query()
            ->with('product:id,name', 'carrierRecord')
            ->where('status', '!=', Redemption::STATUS_CANCELED)
            ->whereIn('project_id', $linhas->pluck('project_id')->unique())
            ->whereIn('user_id', $linhas->pluck('user_id')->unique())
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Redemption $r) => $r->project_id.':'.$r->user_id);
    }

    /**
     * Uma linha por pessoa em cada Projeto do recorte, com a situação dela.
     *
     * Os resgates cancelados não contam: a autorização reabre e a pessoa
     * volta a "faltam resgatar", como no portal.
     */
    private function pares(array $escopo): Builder
    {
        $resgates = DB::table('redemptions')
            ->selectRaw('project_id, user_id')
            ->selectRaw("SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS em_separacao", [Redemption::STATUS_CONFIRMED])
            ->selectRaw("SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS a_caminho", [Redemption::STATUS_SHIPPED])
            ->selectRaw('COUNT(*) AS ativos, MAX(created_at) AS resgatado_em')
            ->where('status', '!=', Redemption::STATUS_CANCELED)
            ->groupBy('project_id', 'user_id');

        return DB::table('project_entitlements')
            ->join('projects', 'projects.id', '=', 'project_entitlements.project_id')
            ->leftJoinSub($resgates, 'r', fn ($j) => $j
                ->on('r.project_id', '=', 'project_entitlements.project_id')
                ->on('r.user_id', '=', 'project_entitlements.user_id'))
            ->when($escopo['project_id'] ?? null, fn ($q, $id) => $q->where('project_entitlements.project_id', $id))
            ->when($escopo['company_id'] ?? null, fn ($q, $id) => $q->where('projects.company_id', $id))
            ->groupBy('project_entitlements.project_id', 'project_entitlements.user_id')
            ->selectRaw('project_entitlements.project_id, project_entitlements.user_id')
            ->selectRaw('MAX(r.resgatado_em) AS resgatado_em')
            // As situações entram como texto fixo, não como parâmetro: o
            // Postgres não deduz o tipo de um parâmetro solto num CASE.
            ->selectRaw(sprintf(
                "CASE WHEN COALESCE(MAX(r.ativos), 0) = 0 THEN '%s' "
                ."WHEN MAX(r.em_separacao) > 0 THEN '%s' "
                ."WHEN MAX(r.a_caminho) > 0 THEN '%s' "
                ."ELSE '%s' END AS situacao",
                self::FALTA, self::SEPARACAO, self::CAMINHO, self::ENTREGUE,
            ));
    }
}

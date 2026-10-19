<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Project;
use App\Models\Redemption;
use App\Services\Reports\RedemptionFunnel;
use App\Support\Auditoria;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Relatórios (inclusão UAT): resumo em tela e exportação CSV de resgates e
 * Projetos por período.
 *
 * Escopo por papel: WeGift enxerga tudo (N2 incluso — relatório é consulta);
 * Admin Cliente sai preso à própria empresa; Admin Projeto, ao Projeto
 * dele. A querystring não muda o recorte de quem é escopado.
 */
class ReportController extends Controller
{
    /** Rótulos dos status de resgate — espelho de `redemptionStatusLabels` no front. */
    private const REDEMPTION_STATUS_LABELS = [
        Redemption::STATUS_CONFIRMED => 'Confirmado',
        Redemption::STATUS_SHIPPED => 'Em transporte',
        Redemption::STATUS_DELIVERED => 'Entregue',
        Redemption::STATUS_CANCELED => 'Cancelado',
    ];

    /** Excel pt-BR espera ponto e vírgula; com vírgula tudo cai numa coluna só. */
    private const CSV_SEPARATOR = ';';

    /** Resumo do período com os botões de exportação. */
    public function index(Request $request, RedemptionFunnel $funil): Response
    {
        $filtros = $this->filtros($request);
        [$de, $ate] = $this->periodo($filtros);
        $escopo = $this->escopo($request, $filtros);
        $user = $request->user();

        $porStatus = $this->resgates($escopo, $de, $ate)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $porEtapa = $this->projetos($escopo, $de, $ate)
            ->selectRaw('pipeline_status, count(*) as total')
            ->groupBy('pipeline_status')
            ->pluck('total', 'pipeline_status');

        return Inertia::render('Reports/Index', [
            'filtros' => [
                'from' => $de->format('Y-m-d'),
                'to' => $ate->format('Y-m-d'),
                'periodo' => $filtros['periodo'] ?? (isset($filtros['from']) || isset($filtros['to']) ? 'personalizado' : '30'),
                'company' => $escopo['company_id'],
                'project' => $escopo['project_id'],
            ],
            // A tela se atualiza sozinha: o horário diz de quando é o retrato.
            'atualizado_em' => now()->toIso8601String(),
            // O andamento de cada Projeto do recorte, pela etapa da esteira.
            'andamento' => $this->projetos($escopo, $de, $ate)
                ->with('company:id,name')
                ->withCount([
                    'entitlements',
                    'redemptions as resgates_count' => fn ($q) => $q->where('status', '!=', Redemption::STATUS_CANCELED),
                    'redemptions as entregues_count' => fn ($q) => $q->where('status', Redemption::STATUS_DELIVERED),
                ])
                ->latest('updated_at')
                ->limit(8)
                ->get()
                ->map(fn (Project $p) => [
                    'id' => $p->id,
                    'nome' => $p->name,
                    'empresa' => $p->company?->name,
                    'etapa' => $p->pipeline_status,
                    'liberado' => $p->isReleased(),
                    'pessoas' => $p->entitlements_count,
                    'resgates' => $p->resgates_count,
                    'entregues' => $p->entregues_count,
                ])
                ->values(),
            // Quem escolhe empresa é a WeGift; o cliente vê a dele fixa no texto.
            'empresas' => $user->isOperator()
                ? Company::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Company $c) => ['value' => $c->id, 'label' => $c->name])
                    ->values()
                : null,
            // Admin Projeto não escolhe Projeto: o dele já está cravado.
            'projetos' => $user->isProjectAdmin() ? null : $this->opcoesDeProjetos($escopo),
            'escopoFixo' => [
                'empresa' => $user->isClientSideAdmin() ? $user->company?->name : null,
                'projeto' => $user->isProjectAdmin() ? $user->adminProject?->name : null,
            ],
            // A jornada das pessoas hoje, sem período: quantas foram
            // cadastradas e onde cada uma está. É o que o RH acompanha.
            'funil' => $funil->resumo($escopo),
            // O Projeto do recorte, para o "Voltar" levar de volta a ele.
            'projetoAtual' => $this->projetoDoVoltar($escopo),
            'resumo' => [
                'resgates' => [
                    'total' => (int) $porStatus->sum(),
                    'por_status' => collect(array_keys(self::REDEMPTION_STATUS_LABELS))
                        ->mapWithKeys(fn (string $s) => [$s => (int) ($porStatus[$s] ?? 0)])
                        ->all(),
                ],
                'projetos' => [
                    'total' => (int) $porEtapa->sum(),
                    'por_etapa' => collect(Project::PIPELINE_STATUSES)
                        ->mapWithKeys(fn (string $s) => [$s => (int) ($porEtapa[$s] ?? 0)])
                        ->all(),
                ],
            ],
        ]);
    }

    /**
     * Para onde o "Voltar para o Projeto" leva: o Projeto escolhido no filtro
     * ou, sem escolha, o único da empresa. Quem chega pelo menu com um
     * Projeto só também precisa do caminho de volta.
     *
     * @return array{id: int, name: string}|null
     */
    private function projetoDoVoltar(array $escopo): ?array
    {
        if ($escopo['project_id']) {
            return Project::query()->find($escopo['project_id'], ['id', 'name'])?->only('id', 'name');
        }

        if (! $escopo['company_id']) {
            return null;
        }

        $daEmpresa = Project::query()->where('company_id', $escopo['company_id'])->limit(2)->get(['id', 'name']);

        return $daEmpresa->count() === 1 ? $daEmpresa->first()->only('id', 'name') : null;
    }

    /** Quantas pessoas cada página da lista do cartão traz. */
    private const PESSOAS_POR_PAGINA = 20;

    /**
     * Quem está em cada cartão do Acompanhamento: a lista que abre ao clicar
     * (JSON, de 20 em 20) e, com `formato`, o arquivo com todas.
     */
    public function people(Request $request, RedemptionFunnel $funil): JsonResponse|StreamedResponse|BinaryFileResponse
    {
        $filtros = $this->filtros($request);
        $escopo = $this->escopo($request, $filtros);
        $dados = $request->validate([
            'situacao' => ['nullable', 'in:'.implode(',', array_keys(RedemptionFunnel::SITUACOES))],
            'busca' => ['nullable', 'string', 'max:120'],
            'pagina' => ['nullable', 'integer', 'min:1'],
        ]);

        $consulta = $funil->pessoas($escopo, $dados['situacao'] ?? null, $dados['busca'] ?? null);

        if (isset($filtros['formato'])) {
            return $this->exportarPessoas($funil, $consulta, $filtros, $dados);
        }

        $pagina = (int) ($dados['pagina'] ?? 1);
        $total = (clone $consulta)->getCountForPagination();
        $linhas = $consulta->forPage($pagina, self::PESSOAS_POR_PAGINA)->get();
        $resgates = $funil->resgatesDe($linhas);

        return response()->json([
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => self::PESSOAS_POR_PAGINA,
            'paginas' => max(1, (int) ceil($total / self::PESSOAS_POR_PAGINA)),
            'pessoas' => $linhas->map(fn ($l) => $this->pessoa($l, $resgates))->values(),
        ]);
    }

    /** Uma pessoa da lista: quem é, em que pé está e o que já resgatou. */
    private function pessoa(object $linha, $resgates): array
    {
        $dela = $resgates->get($linha->project_id.':'.$linha->user_id, collect());

        return [
            'chave' => $linha->project_id.':'.$linha->user_id,
            'nome' => $linha->name,
            'email' => $linha->email,
            'projeto' => $linha->projeto,
            'situacao' => $linha->situacao,
            // Quem nunca criou a senha nem entrou: é o primeiro a cobrar.
            'acessou' => $linha->password_set_at !== null,
            'itens' => $dela->map(fn (Redemption $r) => $r->nomeDoPresente())->values(),
            'resgatado_em' => $linha->resgatado_em,
            'rastreios' => $dela->filter(fn (Redemption $r) => filled($r->tracking_code) || filled($r->trackingLink()))
                ->map(fn (Redemption $r) => [
                    'codigo' => $r->tracking_code,
                    'link' => $r->trackingLink(),
                    'transportadora' => $r->carrierRecord?->name,
                ])->values(),
            'entregue_em' => $dela->max('delivered_at')?->toIso8601String(),
        ];
    }

    private function exportarPessoas(RedemptionFunnel $funil, $consulta, array $filtros, array $dados): StreamedResponse|BinaryFileResponse
    {
        Auditoria::registrar(AuditLog::EXPORT_DOWNLOADED, 'Baixou a lista de Colaboradores do Acompanhamento.', dados: [
            'relatorio' => 'colaboradores',
            'filtros' => $filtros + $dados,
        ]);

        /*
         * Uma linha por pessoa cadastrada, tenha resgatado ou não: é a lista
         * que o RH leva para o Excel e controla em paralelo. Quem ainda não
         * resgatou vem com o resgate e o endereço em branco.
         */
        $cabecalho = [
            'Projeto', 'Pedido Omie', 'Empresa', 'Pessoa', 'E-mail', 'CPF', 'Telefone',
            'Situação', 'Já acessou', 'Itens resgatados', 'Cor/Tamanho', 'Resgatado em',
            'Destinatário', 'CEP', 'Endereço', 'Complemento', 'Bairro', 'Cidade', 'UF',
            'Transportadora', 'Rastreio', 'Entregue em',
        ];

        return $this->exportar('colaboradores', $cabecalho, $filtros['formato'], function (Closure $escrever) use ($funil, $consulta) {
            $consulta->chunk(500, function ($linhas) use ($funil, $escrever) {
                $resgates = $funil->resgatesDe($linhas);

                foreach ($linhas as $linha) {
                    $p = $this->pessoa($linha, $resgates);
                    $dela = $resgates->get($linha->project_id.':'.$linha->user_id, collect());

                    // O endereço é o do resgate mais recente: quem resgatou
                    // dois itens costuma mandar os dois para o mesmo lugar.
                    $ultimo = $dela->sortByDesc('id')->first();

                    $escrever([
                        $p['projeto'],
                        $linha->omie_order_number,
                        $linha->empresa,
                        $p['nome'],
                        $p['email'],
                        // O CPF informado NA ENTREGA manda; o do cadastro é o
                        // de quem ainda não resgatou.
                        $this->cpfMascarado($ultimo?->cpf ?? $linha->cpf),
                        $this->telefoneLegivel($linha->phone),
                        RedemptionFunnel::SITUACOES[$p['situacao']] ?? $p['situacao'],
                        $p['acessou'] ? 'Sim' : 'Não',
                        $p['itens']->implode(' + '),
                        $dela->map(fn (Redemption $r) => $r->rotuloDaVariacao())->filter()->implode(' + '),
                        $p['resgatado_em'] ? CarbonImmutable::parse($p['resgatado_em'])->format('d/m/Y') : null,
                        $ultimo?->recipient_name,
                        $this->cepLegivel($ultimo?->zip),
                        $ultimo ? trim($ultimo->street.', '.$ultimo->number, ', ') : null,
                        $ultimo?->complement,
                        $ultimo?->district,
                        $ultimo?->city,
                        $ultimo?->state,
                        collect($p['rastreios'])->pluck('transportadora')->filter()->unique()->implode(', '),
                        collect($p['rastreios'])->map(fn ($r) => $r['codigo'] ?: $r['link'])->implode(', '),
                        $p['entregue_em'] ? CarbonImmutable::parse($p['entregue_em'])->format('d/m/Y') : null,
                    ]);
                }
            });
        });
    }

    /** "+5541999998888" vira "(41) 99999-8888"; o que não for celular ou fixo do Brasil vai como veio. */
    private function telefoneLegivel(?string $e164): ?string
    {
        if ($e164 === null || ! preg_match('/^\+55(\d{2})(\d{4,5})(\d{4})$/', $e164, $m)) {
            return $e164;
        }

        return "({$m[1]}) {$m[2]}-{$m[3]}";
    }

    private function cepLegivel(?string $cep): ?string
    {
        return $cep === null ? null : preg_replace('/^(\d{5})(\d{3})$/', '$1-$2', $cep);
    }

    /** Resgates do período, uma linha por resgate, com o rastreio (CSV ou Excel). */
    public function redemptions(Request $request): StreamedResponse|BinaryFileResponse
    {
        $filtros = $this->filtros($request);
        [$de, $ate] = $this->periodo($filtros);
        $escopo = $this->escopo($request, $filtros);

        $consulta = $this->resgates($escopo, $de, $ate)
            ->with(['project.company', 'user', 'product']);

        // Planilha com nome, e-mail e CPF de colaboradores saindo da plataforma.
        Auditoria::registrar(AuditLog::EXPORT_DOWNLOADED, 'Baixou o relatório de resgates (CSV com dados pessoais).', dados: [
            'relatorio' => 'resgates',
            'filtros' => $filtros,
        ]);

        $cabecalho = [
            'Projeto', 'Pedido Omie', 'Empresa', 'Pessoa', 'E-mail', 'CPF', 'Item', 'Cor/Tamanho', 'Qtde',
            'Status', 'Data do resgate', 'Código de rastreio', 'Entregue em',
        ];

        return $this->exportar('resgates', $cabecalho, $filtros['formato'] ?? 'csv', function (Closure $escrever) use ($consulta) {
            $consulta->chunkById(500, function ($resgates) use ($escrever) {
                foreach ($resgates as $r) {
                    $escrever([
                        $r->project->name,
                        $r->project->omie_order_number,
                        $r->project->company->name,
                        $r->user->name,
                        $r->user->email,
                        // O CPF informado NA ENTREGA manda (exigência do UAT);
                        // o do cadastro é só o fallback de resgates antigos.
                        $this->cpfMascarado($r->cpf ?? $r->user->cpf),
                        $r->product->name,
                        // Escolhida pelo colaborador (parent code); vazio sem variação.
                        $r->rotuloDaVariacao(),
                        $r->quantity ?? 1,
                        self::REDEMPTION_STATUS_LABELS[$r->status] ?? $r->status,
                        $r->created_at?->format('d/m/Y'),
                        $r->tracking_code,
                        $r->delivered_at?->format('d/m/Y'),
                    ]);
                }
            });
        });
    }

    /** Projetos do período, com adesão: Usuários, resgates e entregas (CSV ou Excel). */
    public function projects(Request $request): StreamedResponse|BinaryFileResponse
    {
        $filtros = $this->filtros($request);
        [$de, $ate] = $this->periodo($filtros);
        $escopo = $this->escopo($request, $filtros);

        $consulta = $this->projetos($escopo, $de, $ate)
            ->with('company')
            ->withCount([
                'entitlements',
                // "Resgates" segue a régua do domínio: cancelado não conta como resgatado.
                'redemptions as resgates_count' => fn ($q) => $q->where('status', '!=', Redemption::STATUS_CANCELED),
                'redemptions as entregues_count' => fn ($q) => $q->where('status', Redemption::STATUS_DELIVERED),
            ]);

        $cabecalho = [
            'Projeto', 'Pedido Omie', 'Nº pedido do cliente', 'Empresa', 'Etapa',
            'Data da compra', 'Prazo de entrega', 'Liberado em',
            'Usuários cadastrados', 'Resgates', 'Entregues',
        ];

        return $this->exportar('projetos', $cabecalho, $filtros['formato'] ?? 'csv', function (Closure $escrever) use ($consulta) {
            $consulta->chunkById(500, function ($projetos) use ($escrever) {
                foreach ($projetos as $p) {
                    $escrever([
                        $p->name,
                        $p->omie_order_number,
                        $p->client_order_number,
                        $p->company->name,
                        $p->stageLabel(),
                        $p->ordered_at?->format('d/m/Y'),
                        $p->delivery_deadline?->format('d/m/Y'),
                        $p->released_at?->format('d/m/Y'),
                        $p->entitlements_count,
                        $p->resgates_count,
                        $p->entregues_count,
                    ]);
                }
            });
        });
    }

    /** Filtros comuns às três rotas, validados uma única vez. */
    private function filtros(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            // Atalhos do período; sem ele, valem `from`/`to` (personalizado).
            'periodo' => ['nullable', 'in:7,30,90,365,tudo,personalizado'],
            'company' => ['nullable', 'integer', 'exists:companies,id'],
            'project' => ['nullable', 'integer', 'exists:projects,id'],
            'formato' => ['nullable', 'in:csv,xlsx'],
        ]);
    }

    /**
     * Período do relatório: últimos 30 dias por padrão. Os atalhos (7, 30, 90
     * e 365 dias, ou "tudo") contam até hoje; "personalizado" usa as datas.
     * Pontas invertidas são trocadas em vez de virar erro: link de relatório
     * não deve estourar 422 por causa de duas datas fora de ordem.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function periodo(array $filtros): array
    {
        $atalho = $filtros['periodo'] ?? null;

        if ($atalho === 'tudo') {
            return [CarbonImmutable::create(2000)->startOfDay(), CarbonImmutable::today()->endOfDay()];
        }

        if (is_numeric($atalho)) {
            return [CarbonImmutable::today()->subDays((int) $atalho)->startOfDay(), CarbonImmutable::today()->endOfDay()];
        }

        $ate = isset($filtros['to'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $filtros['to'])
            : CarbonImmutable::today();

        $de = isset($filtros['from'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $filtros['from'])
            : $ate->subDays(30);

        if ($de->gt($ate)) {
            [$de, $ate] = [$ate, $de];
        }

        return [$de->startOfDay(), $ate->endOfDay()];
    }

    /**
     * Recorte de dados por papel. WeGift filtra livre; Admin Cliente sai preso
     * à própria empresa e só filtra Projetos dela; Admin Projeto sai
     * cravado no Projeto dele — a querystring não muda isso.
     *
     * @return array{company_id: ?int, project_id: ?int}
     */
    private function escopo(Request $request, array $filtros): array
    {
        $user = $request->user();

        if ($user->isOperator()) {
            return [
                'company_id' => $filtros['company'] ?? null,
                'project_id' => $filtros['project'] ?? null,
            ];
        }

        if ($user->isProjectAdmin()) {
            abort_if($user->admin_project_id === null, 403);

            return ['company_id' => $user->company_id, 'project_id' => $user->admin_project_id];
        }

        // Admin Cliente. Sem empresa vinculada não há o que mostrar.
        abort_if($user->company_id === null, 403);

        $projectId = $filtros['project'] ?? null;

        if ($projectId !== null && ! $user->managesProject(Project::findOrFail($projectId))) {
            abort(403);
        }

        return ['company_id' => $user->company_id, 'project_id' => $projectId];
    }

    /** Resgates do recorte, filtrados pela data em que foram feitos. */
    private function resgates(array $escopo, CarbonImmutable $de, CarbonImmutable $ate): Builder
    {
        return Redemption::query()
            ->whereBetween('redemptions.created_at', [$de, $ate])
            ->when($escopo['project_id'], fn ($q, $id) => $q->where('project_id', $id))
            ->when($escopo['company_id'], fn ($q, $id) => $q->whereHas(
                'project', fn ($p) => $p->where('company_id', $id),
            ));
    }

    /** Projetos do recorte, filtrados pela data de entrada na plataforma. */
    private function projetos(array $escopo, CarbonImmutable $de, CarbonImmutable $ate): Builder
    {
        return Project::query()
            ->whereBetween('created_at', [$de, $ate])
            ->when($escopo['project_id'], fn ($q, $id) => $q->whereKey($id))
            ->when($escopo['company_id'], fn ($q, $id) => $q->where('company_id', $id));
    }

    /**
     * Opções do select de Projeto — de propósito SEM o filtro de período: o
     * select serve para escolher o recorte, não para refleti-lo. A empresa de
     * cada Projeto vai junto para o front filtrar a lista ao trocar de empresa
     * sem nova ida ao servidor.
     *
     * @return array<int, array{value: int, label: string, company: int, companyName: string}>
     */
    private function opcoesDeProjetos(array $escopo): array
    {
        return Project::query()
            ->with('company:id,name')
            ->when($escopo['company_id'], fn ($q, $id) => $q->where('company_id', $id))
            ->orderBy('name')
            ->get(['id', 'name', 'company_id'])
            ->map(fn (Project $p) => [
                'value' => $p->id,
                'label' => $p->name,
                'company' => $p->company_id,
                'companyName' => $p->company->name,
            ])
            ->values()
            ->all();
    }

    /** O arquivo no formato pedido: planilha do Excel ou CSV. */
    private function exportar(string $prefixo, array $cabecalho, string $formato, Closure $linhas): StreamedResponse|BinaryFileResponse
    {
        return $formato === 'xlsx'
            ? $this->xlsx($prefixo, $cabecalho, $linhas)
            : $this->csv($prefixo, $cabecalho, $linhas);
    }

    /**
     * Planilha .xlsx de verdade, que abre no Excel sem assistente e sem o
     * problema de acento ou de separador do CSV. Escrita linha a linha, como
     * o CSV: relatório de nove mil resgates não cabe montado em memória.
     */
    private function xlsx(string $prefixo, array $cabecalho, Closure $linhas): BinaryFileResponse
    {
        $caminho = tempnam(sys_get_temp_dir(), 'welog_relatorio_');

        $planilha = new XlsxWriter;
        $planilha->openToFile($caminho);
        $planilha->addRow(Row::fromValues($cabecalho));

        $linhas(fn (array $campos) => $planilha->addRow(Row::fromValues(
            array_map(fn ($campo) => (string) ($campo ?? ''), $campos),
        )));

        $planilha->close();

        return response()
            ->download($caminho, sprintf('%s-%s.xlsx', $prefixo, now()->format('Y-m-d')), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    /**
     * Resposta CSV em streaming, linha a linha: separador ponto e vírgula e
     * BOM UTF-8, que é o que faz o Excel pt-BR abrir acentos e colunas certos
     * sem passar pelo assistente de importação.
     */
    private function csv(string $prefixo, array $cabecalho, Closure $linhas): StreamedResponse
    {
        $arquivo = sprintf('%s-%s.csv', $prefixo, now()->format('Y-m-d'));

        return response()->streamDownload(function () use ($cabecalho, $linhas) {
            $saida = fopen('php://output', 'w');

            // Sem o BOM o Excel pt-BR lê "Produção" como "ProduÃ§Ã£o".
            fwrite($saida, "\xEF\xBB\xBF");
            fputcsv($saida, $cabecalho, self::CSV_SEPARATOR, escape: '');

            $linhas(fn (array $campos) => fputcsv(
                $saida,
                array_map(fn ($campo) => (string) ($campo ?? ''), $campos),
                self::CSV_SEPARATOR,
                escape: '',
            ));

            fclose($saida);
        }, $arquivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** CPF com máscara (000.000.000-00) — padrão de apresentação do sistema. */
    private function cpfMascarado(?string $cpf): string
    {
        return $cpf === null
            ? ''
            : preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $cpf);
    }
}

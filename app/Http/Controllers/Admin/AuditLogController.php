<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Auditoria;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Consulta da trilha de auditoria — só o Admin WeGift (grupo role:admin).
 *
 * Responde às perguntas de um assessment de segurança: quem entrou, quem
 * tentou entrar e não conseguiu, quem mudou permissão, quem exportou dados
 * pessoais. A exportação em CSV é a evidência que se entrega ao auditor — e
 * ela própria fica registrada na trilha.
 */
class AuditLogController extends Controller
{
    private const PERIODOS = ['24h' => 'hoje', '7d' => '7 dias', '30d' => '30 dias', '90d' => '90 dias'];

    public function index(Request $request): Response
    {
        $filtros = $this->filtros($request);

        $base = fn () => $this->consulta(array_merge($filtros, ['group' => null]));

        $registros = $this->consulta($filtros)
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Admin/Audit/Index', [
            'logs' => [
                'data' => $registros->through(fn (AuditLog $l) => [
                    'id' => $l->id,
                    'action' => $l->action,
                    'group' => $l->group(),
                    'description' => $l->description,
                    'user_name' => $l->user_name,
                    'user_email' => $l->user_email,
                    'user_role' => $l->user_role,
                    'subject' => $l->subject_type ? "{$l->subject_type} #{$l->subject_id}" : null,
                    'properties' => $l->properties,
                    'ip' => $l->ip,
                    'user_agent' => $l->user_agent,
                    'created_at' => $l->created_at?->toIso8601String(),
                ])->items(),
                'links' => $registros->linkCollection(),
                'total' => $registros->total(),
            ],
            'filters' => [
                'group' => $filtros['group'] ?? '',
                'period' => $filtros['period'] ?? '',
                'q' => $filtros['q'] ?? '',
            ],
            'groups' => AuditLog::GROUPS,
            'periods' => self::PERIODOS,
            // Contagem por grupo, respeitando período e busca: a aba "Acesso"
            // diz quantos dentro do que já está filtrado, não no histórico todo.
            'counts' => $this->contagens($base()),
            'retentionDays' => (int) config('welog.audit.retention_days'),
        ]);
    }

    /** CSV com os mesmos filtros da tela — a evidência para o auditor. */
    public function export(Request $request): StreamedResponse
    {
        $filtros = $this->filtros($request);

        Auditoria::registrar(AuditLog::EXPORT_DOWNLOADED, 'Exportou a trilha de auditoria (CSV).', dados: [
            'relatorio' => 'auditoria',
            'filtros' => array_filter($filtros),
        ]);

        $consulta = $this->consulta($filtros)->oldest('id');
        $fuso = config('integrations.business_timezone', 'America/Sao_Paulo');

        return response()->streamDownload(function () use ($consulta, $fuso) {
            $saida = fopen('php://output', 'w');
            // BOM: sem ele o Excel abre o CSV em Latin-1 e quebra os acentos.
            fwrite($saida, "\xEF\xBB\xBF");
            fputcsv($saida, ['Data/hora', 'Usuário', 'E-mail', 'Perfil', 'Ação', 'Descrição', 'Alvo', 'IP', 'Detalhes'], ';');

            $consulta->chunkById(1000, function ($registros) use ($saida, $fuso) {
                foreach ($registros as $l) {
                    fputcsv($saida, [
                        $l->created_at?->setTimezone($fuso)->format('d/m/Y H:i:s'),
                        $l->user_name ?? 'sistema',
                        $l->user_email,
                        $l->user_role,
                        $l->action,
                        $l->description,
                        $l->subject_type ? "{$l->subject_type} #{$l->subject_id}" : '',
                        $l->ip,
                        $l->properties ? json_encode($l->properties, JSON_UNESCAPED_UNICODE) : '',
                    ], ';');
                }
            });

            fclose($saida);
        }, 'auditoria-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{group: ?string, period: ?string, q: ?string} */
    private function filtros(Request $request): array
    {
        return $request->validate([
            'group' => ['nullable', Rule::in(array_keys(AuditLog::GROUPS))],
            'period' => ['nullable', Rule::in(array_keys(self::PERIODOS))],
            'q' => ['nullable', 'string', 'max:120'],
        ]) + ['group' => null, 'period' => null, 'q' => null];
    }

    private function consulta(array $filtros): Builder
    {
        return AuditLog::query()
            ->when($filtros['group'] ?? null, fn ($q, $v) => $q->where('action', 'like', "{$v}.%"))
            ->when($filtros['period'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $this->desde($v)))
            ->when($filtros['q'] ?? null, function ($q, $termo) {
                $like = '%'.mb_strtolower($termo).'%';

                // LOWER dos dois lados em vez de ILIKE: roda igual no Postgres
                // e no SQLite dos testes (mesma escolha do log de webhooks).
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(description) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(user_email) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(user_name) LIKE ?', [$like])
                    ->orWhere('ip', $termo));
            });
    }

    /** "hoje" é meia-noite no fuso do negócio, não em UTC. */
    private function desde(string $periodo): CarbonImmutable
    {
        $agora = CarbonImmutable::now(config('integrations.business_timezone'));

        return match ($periodo) {
            '24h' => $agora->startOfDay(),
            '7d' => $agora->subDays(7),
            '30d' => $agora->subDays(30),
            '90d' => $agora->subDays(90),
        };
    }

    /** @return array<string, int> total + um por grupo, com zero para grupo vazio */
    private function contagens(Builder $base): array
    {
        $porAcao = $base->reorder()
            ->groupBy('action')
            ->selectRaw('action, count(*) as total')
            ->pluck('total', 'action');

        $contagens = ['total' => 0] + array_fill_keys(array_keys(AuditLog::GROUPS), 0);

        foreach ($porAcao as $acao => $n) {
            $grupo = explode('.', $acao, 2)[0];
            $contagens['total'] += (int) $n;
            $contagens[$grupo] = ($contagens[$grupo] ?? 0) + (int) $n;
        }

        return $contagens;
    }
}

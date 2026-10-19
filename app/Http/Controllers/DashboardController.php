<?php

namespace App\Http\Controllers;

use App\Models\AppnozImportRow;
use App\Models\Project;
use App\Models\Redemption;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $porEstagio = Project::query()
            ->selectRaw('pipeline_status, count(*) as total')
            ->groupBy('pipeline_status')
            ->pluck('total', 'pipeline_status');

        $total = (int) $porEstagio->sum();
        $concluidos = (int) ($porEstagio[Project::STATUS_DONE] ?? 0);

        return Inertia::render('Dashboard', [
            // Mandar o mapa cru obrigaria a tela a conhecer a ordem dos
            // estágios; aqui ela chega ordenada e com o percentual pronto.
            'pipeline' => collect(Project::PIPELINE_STATUSES)->map(fn (string $status) => [
                'status' => $status,
                'total' => (int) ($porEstagio[$status] ?? 0),
                'percent' => $total > 0 ? (int) round(($porEstagio[$status] ?? 0) / $total * 100) : 0,
            ])->values(),

            'stats' => [
                'totalProjects' => $total,
                'newToday' => Project::whereDate('created_at', today())->count(),
                'awaitingRelease' => Project::where('pipeline_status', Project::STATUS_IN_STOCK)->whereNull('released_at')->count(),
                // Os dois lados da esteira de resgate: o que saiu e ainda está
                // na rua, e o que já chegou ao destinatário.
                'shippedRedemptions' => Redemption::where('status', Redemption::STATUS_SHIPPED)->count(),
                'deliveredRedemptions' => Redemption::where('status', Redemption::STATUS_DELIVERED)->count(),
                // Sem cartão próprio, mas ainda alimenta o aviso de atenção.
                'blockedRows' => AppnozImportRow::whereIn('status', AppnozImportRow::BLOCKED_STATUSES)->count(),
            ],

            'summary' => [
                'done' => $concluidos,
                'inProgress' => $total - $concluidos,
                // "Em atraso" é prazo vencido com o projeto ainda aberto — a
                // única das três que exige ação, por isso vem do banco e não
                // de subtração.
                'late' => Project::whereNotNull('delivery_deadline')
                    ->whereDate('delivery_deadline', '<', today())
                    ->where('pipeline_status', '!=', Project::STATUS_DONE)
                    ->count(),
            ],

            'recentProjects' => Project::with('company')
                ->latest()
                ->limit(8)
                ->get()
                ->map(fn (Project $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'company' => $p->company->name,
                    'created_at' => $p->created_at?->toDateString(),
                    'pipeline_status' => $p->pipeline_status,
                    'delivery_deadline' => $p->delivery_deadline?->toDateString(),
                    // Negativo = atrasado. Calculado aqui porque depende do
                    // fuso do negócio, não do relógio do navegador do operador.
                    'days_left' => $p->delivery_deadline
                        ? (int) Carbon::today()->diffInDays($p->delivery_deadline, false)
                        : null,
                    'stock_available' => $p->hasStockAvailable(),
                    'released' => $p->isReleased(),
                ]),
        ]);
    }
}

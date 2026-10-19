<?php

namespace App\Jobs\FluxoB;

use App\Contracts\Integrations\OmieGateway;
use App\Integrations\Omie\OmieApiException;
use App\Integrations\Support\OmieDate;
use App\Models\AppnozImportRow;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Fluxo B: vincula as NFs emitidas pela Omie às linhas de importação
 * (pedido → nota), habilitando o rastreio na Flash.
 *
 * É a reserva do webhook NFe.NotaAutorizada (ProcessOmieInvoiceWebhook): pega
 * a nota cujo webhook não chegou.
 */
class SyncOmieInvoices implements ShouldQueue
{
    use Queueable;

    /**
     * Teto de páginas por execução (100 notas cada). A conta emite nota da
     * operação inteira — centenas por semana —, e a nota de um resgate pode
     * estar longe da primeira página. Ler só a primeira deixava resgate sem
     * nota para sempre.
     */
    private const MAX_PAGINAS = 20;

    public function __construct()
    {
        $this->onQueue('integrations');
    }

    public function handle(OmieGateway $omie): void
    {
        $pending = AppnozImportRow::query()
            ->where('status', AppnozImportRow::STATUS_ORDER_CREATED)
            ->whereNull('nf_key')
            ->get()
            ->keyBy('omie_order_id');

        // Janela de 7 dias cobre atrasos de emissão sem varrer o histórico todo
        for ($pagina = 1; $pending->isNotEmpty() && $pagina <= self::MAX_PAGINAS; $pagina++) {
            try {
                $invoices = $omie->listInvoices(now()->subDays(7), $pagina);
            } catch (OmieApiException $e) {
                // Página depois da última: a Omie responde "não existem registros".
                if ($e->isNotFound()) {
                    break;
                }

                throw $e;
            }

            if ($invoices === []) {
                break;
            }

            foreach ($invoices as $invoice) {
                $row = $pending->get($invoice['omie_order_id'] ?? null);

                if ($row === null) {
                    continue;
                }

                $row->registrarNota($invoice['nf_key'], $this->emissao($invoice['issued_at'] ?? null));

                $pending->forget($invoice['omie_order_id']);
            }
        }
    }

    /**
     * A Omie manda dd/mm/aaaa, e lido como data americana o 30/09 não existia.
     * O que não for nesse formato (ISO, do fake) segue pelo parse comum.
     */
    private function emissao(?string $valor): DateTimeInterface
    {
        return OmieDate::parse($valor, 'ListarNF dEmi')
            ?? rescue(fn () => Carbon::parse((string) $valor), now(), false);
    }
}

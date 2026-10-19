<?php

namespace App\Services\FluxoB;

use App\Contracts\Integrations\FlashGateway;
use App\Jobs\FluxoB\PollFlashTracking;
use App\Models\AppnozImportRow;
use App\Support\TextoFlash;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Resgate com NF emitida → encomenda na Flash (importação da Bridge Link).
 *
 * O número da encomenda (`num_enc_cli`) é o mesmo que o rastreio consulta —
 * WEBOX + nº da NF —, então a encomenda importada aqui é a que o
 * PollFlashTracking acompanha depois, sem tradução nenhuma.
 *
 * Reenviar é seguro: número já importado é ignorado pela Bridge Link. Por
 * isso uma recusa no meio não exige cuidado com o que já foi.
 */
class SendShipmentsToFlash
{
    /** Encomendas por chamada: a API aceita lote, e o teto é por requisição. */
    private const LOTE = 50;

    public function __construct(private readonly FlashGateway $flash) {}

    /**
     * Os resgates prontos para a Flash: NF emitida e ainda não enviados.
     *
     * Nota simulada (welog:nf:simular) nunca vai à Flash real — ela não existe
     * na Sefaz; com o driver fake, vai, para o fluxo de homologação inteiro
     * poder ser exercitado.
     */
    public static function pendentes(): Builder
    {
        return AppnozImportRow::query()
            ->where('status', AppnozImportRow::STATUS_INVOICED)
            ->whereNotNull('nf_key')
            ->whereNull('flash_sent_at')
            ->when(self::flashReal(), fn ($q) => $q->where('nf_simulated', false));
    }

    public static function podeEnviar(AppnozImportRow $row): bool
    {
        return filled($row->nf_key)
            && PollFlashTracking::referencia($row) !== null
            && ! ($row->nf_simulated && self::flashReal());
    }

    /**
     * @param  iterable<AppnozImportRow>  $rows
     * @return int quantas encomendas foram enviadas
     *
     * @throws Throwable a recusa da Flash, depois de gravada nos resgates do lote
     */
    public function handle(iterable $rows): int
    {
        $prontos = Collection::make($rows)->filter(fn (AppnozImportRow $r) => self::podeEnviar($r))->values();

        foreach ($prontos->chunk(self::LOTE) as $lote) {
            $ids = $lote->pluck('id')->all();

            try {
                $this->flash->importShipments($lote->map(fn (AppnozImportRow $r) => $this->encomenda($r))->values()->all());
            } catch (Throwable $e) {
                // A recusa fica no resgate: é lá que alguém vai procurar por
                // que a encomenda não andou.
                AppnozImportRow::whereIn('id', $ids)->update(['flash_error' => mb_substr($e->getMessage(), 0, 1000)]);

                throw $e;
            }

            AppnozImportRow::whereIn('id', $ids)->update(['flash_sent_at' => now(), 'flash_error' => null]);
        }

        return $prontos->count();
    }

    /**
     * A encomenda no formato da importação Flash. Conta, contrato, centro de
     * custo e remetente o gateway completa.
     *
     * @return array<string, mixed>
     */
    public function encomenda(AppnozImportRow $r): array
    {
        $digitos = fn (?string $v) => preg_replace('/\D/', '', (string) $v) ?: null;
        $preenchido = fn ($v) => $v !== null && $v !== '';

        // Texto sem acento nem pontuação: era o que derrubava a importação
        // (ver TextoFlash). Documentos e telefone já vão só com dígitos.
        $texto = fn (?string $v) => TextoFlash::limpar($v);

        return array_filter([
            'num_enc_cli' => PollFlashTracking::referencia($r),
            'endHawbs' => array_filter([
                'nome_des' => $texto($r->customer_name),
                'logr_dest' => $texto($r->street),
                'num_des' => $texto($r->number),
                'compl_end_dest' => $texto($r->complement),
                'bairro_des' => $texto($r->district),
                'cid_dest' => $texto($r->city),
                'uf_dest' => $texto($r->state),
                'cep_dest' => $digitos($r->zip),
                'fone1_des' => $digitos($r->phone),
            ], $preenchido),
            'cpf_des' => $digitos($r->cpf),
            // Sem `email_des` e sem `obs1`: o exemplo de importação da Bridge
            // Link não os traz, e o e-mail é o único campo que não sai sem
            // pontuação ("@" e "."). A quantidade vai como texto, como no
            // exemplo ("1").
            'qtde_itens' => (string) max(1, (int) $r->quantity),
            'chave_nf' => $digitos($r->nf_key),
            'cod_doc' => $r->nfNumber(),
        ], $preenchido);
    }

    private static function flashReal(): bool
    {
        return config('integrations.flash.driver') === 'api';
    }
}

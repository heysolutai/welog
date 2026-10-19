<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Projects\ProjectPurger;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * Apaga Projetos e o rastro que eles deixaram, mantendo uma lista escolhida à
 * mão (ids, números de pedido ou os N mais recentes).
 *
 * Existe porque o filtro de pedidos da Omie só passou a valer agora: 1666
 * Projetos entraram por omissão, quase nenhum de verdade. Consertar o código
 * não limpa o que já está no banco. Para manter "os do projeto Venda" sem
 * listar um por um, use `welog:projects:purge-non-venda`.
 *
 * A cascata (rastreio → resgates → autorizações → Projetos → usuários órfãos →
 * empresas vazias) mora em `ProjectPurger` e roda em TRANSAÇÃO: ou vai tudo,
 * ou não vai nada.
 *
 * Por padrão é SIMULAÇÃO — mostra o que apagaria e não toca em nada. Só com
 * `--force` executa, e em produção ainda pede confirmação.
 */
class PurgeProjects extends Command
{
    use ConfirmableTrait;

    protected $signature = 'welog:projects:purge
                            {--keep=3 : Quantos Projetos manter (os mais recentes)}
                            {--keep-ids= : Ids do WeLog a manter, separados por vírgula (ignora --keep)}
                            {--keep-orders= : Números de pedido da OMIE a manter — é o número que aparece na lista}
                            {--execute : Executa de verdade; sem isto é apenas simulação}
                            {--force : Pula a confirmação de produção (obrigatório com docker exec -T, que não tem TTY)}';

    protected $description = 'Apaga Projetos e o rastro deles (resgates, rastreio, clientes finais, empresas)';

    public function handle(ProjectPurger $purger): int
    {
        $manter = $this->idsParaManter();

        if ($manter === []) {
            $this->error('Nada a manter — recuse-se a esvaziar a base inteira.');
            $this->line('  --keep-orders=66906,66905   (o número que aparece na lista, do pedido Omie)');
            $this->line('  --keep-ids=1595,1594        (o id do WeLog, o que aparece na URL)');
            $this->line('  --keep=3                    (os N mais recentes)');

            return self::FAILURE;
        }

        $alvo = Project::whereNotIn('id', $manter);

        if ((clone $alvo)->count() === 0) {
            $this->info('Nenhum Projeto para apagar.');

            return self::SUCCESS;
        }

        $inventario = $purger->inventariar($manter);

        $this->mostrar($inventario, $manter);

        if (! $this->option('execute')) {
            $this->newLine();
            $this->warn('SIMULAÇÃO — nada foi apagado.');
            $this->line('  Confira o backup antes: <options=bold>php artisan welog:backup:status</>');
            $this->line('  Para executar de verdade, repita com <options=bold>--execute</>.');

            return self::SUCCESS;
        }

        /*
         * Duas travas SEPARADAS, de propósito.
         *
         * `--execute` liga a exclusão; `--force` pula a confirmação de produção
         * (é a flag que o `ConfirmableTrait` do Laravel lê, o nome não é
         * escolha minha). Se as duas fossem a mesma, quem passasse uma flag só
         * ganharia as duas permissões de uma vez — e a última porta antes de
         * uma exclusão irreversível deixaria de existir.
         *
         * Em `docker exec -T` não há TTY: o prompt não tem como ser respondido
         * e o comando aborta. Por isso `--force` é obrigatório lá, e é uma
         * decisão consciente, não um detalhe de invocação.
         */
        if (! $this->confirmToProceed('Isto apaga dados de forma IRREVERSÍVEL')) {
            return self::FAILURE;
        }

        $apagados = $purger->apagar($manter);

        $this->newLine();
        $this->info('Concluído.');
        $this->table(['Tabela', 'Apagados'], array_map(
            fn ($chave, $valor) => [$chave, $valor],
            array_keys($apagados),
            $apagados,
        ));

        if ($inventario['empresas_com_usuario'] > 0) {
            $this->newLine();
            $this->warn($inventario['empresas_com_usuario'].' empresa(s) sem Projeto NÃO foram apagadas.');
            $this->line('  Elas ainda têm usuário vinculado (admin cliente, provavelmente).');
            $this->line('  Apagá-las anularia o `company_id` dessas contas (`nullOnDelete`) e a pessoa');
            $this->line('  cairia no 403 sem saída. Remova o usuário antes, se for o caso.');
        }

        return self::SUCCESS;
    }

    /** @param  array<string, int>  $inv */
    private function mostrar(array $inv, array $manter): void
    {
        $this->newLine();
        $this->line('Mantendo <options=bold>'.count($manter).'</> Projeto(s):');

        // Nome e pedido junto do id: confirmar uma exclusão irreversível
        // olhando só para "1595, 1594" é confirmar no escuro.
        foreach (Project::whereIn('id', $manter)->orderBy('id')->get() as $projeto) {
            $this->line("  · #{$projeto->id}  pedido {$projeto->omie_order_number}  {$projeto->name}");
        }

        $this->newLine();

        $this->table(['Será apagado', 'Qtde'], [
            ['Projetos', $inv['projetos']],
            ['  └ autorizações de resgate', $inv['entitlements']],
            ['  └ resgates', $inv['resgates']],
            ['     └ eventos de rastreio', $inv['rastreios']],
            ['Usuários cliente final órfãos', $inv['usuarios']],
            ['Empresas sem Projeto e sem usuário', $inv['empresas']],
        ]);
    }

    /** @return array<int, int> */
    private function idsParaManter(): array
    {
        /*
         * `--keep-orders` existe porque o número que a lista mostra na frente
         * do nome ("66906 RODRIGO…") é o do PEDIDO NA OMIE, não o id do
         * Projeto no WeLog — o mesmo registro é 66906 na tela e 1595 na URL.
         * Exigir o id obrigaria a abrir Projeto por Projeto para copiá-lo da
         * barra de endereço, numa operação que não perdoa engano.
         */
        $pedidos = $this->listaDeOpcao('keep-orders');

        if ($pedidos !== []) {
            $encontrados = Project::whereIn('omie_order_number', $pedidos)
                ->pluck('id', 'omie_order_number');

            $faltando = array_diff($pedidos, $encontrados->keys()->map('strval')->all());

            if ($faltando !== []) {
                $this->error('Pedidos Omie não encontrados em --keep-orders: '.implode(', ', $faltando));

                return [];
            }

            return $encontrados->values()->all();
        }

        $ids = $this->listaDeOpcao('keep-ids');

        if ($ids !== []) {
            // Id inexistente vira exclusão a mais sem ninguém notar: confere.
            $encontrados = Project::whereIn('id', $ids)->pluck('id')->map('strval')->all();
            $faltando = array_diff($ids, $encontrados);

            if ($faltando !== []) {
                $this->error('Ids inexistentes em --keep-ids: '.implode(', ', $faltando));

                return [];
            }

            return array_map('intval', $encontrados);
        }

        $quantos = max(0, (int) $this->option('keep'));

        return $quantos === 0 ? [] : Project::latest('id')->limit($quantos)->pluck('id')->all();
    }

    /**
     * Valores separados por vírgula, sem os vazios. Como string: número de
     * pedido da Omie tem zero à esquerda em algumas contas, e converter para
     * inteiro cedo demais transformaria "0066" em "66".
     *
     * @return array<int, string>
     */
    private function listaDeOpcao(string $opcao): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option($opcao)),
        )));
    }
}

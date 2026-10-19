<?php

namespace App\Console\Commands;

use App\Models\AppnozImport;
use App\Models\AppnozImportRow;
use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\Redemption;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

/**
 * Zera a OPERAÇÃO e preserva a CONFIGURAÇÃO — o "começar do zero" de antes de
 * entrar gente de verdade na plataforma.
 *
 * Existe porque os dois purges irmãos se recusam a esvaziar a base inteira, e
 * essa recusa é proposital: `welog:projects:purge` e `welog:companies:purge`
 * são bisturi de rotina, usados com a operação rodando, e neles "apagar tudo"
 * é quase sempre engano de quem digitou. Aqui é o oposto — apagar tudo é o
 * pedido —, então o comando é separado, tem nome próprio e as mesmas travas.
 *
 *   APAGA     empresas · Projetos · autorizações · resgates · rastreio
 *             usuários do lado do cliente · Importações Parceiros
 *             log de webhooks
 *
 *   PRESERVA  contas da WeGift · catálogo de produtos (espelho da Omie)
 *             templates de e-mail · benefícios fiscais
 *             mapeamento das listas do Trello
 *
 *   php artisan welog:base:reset                        (simula)
 *   php artisan welog:base:reset --execute --force      (apaga)
 *
 * O que ele NÃO faz, e ninguém deve supor que faça:
 *
 *   · o Trello não é tocado. Os cards já criados continuam no quadro e
 *     precisam ser arquivados à mão — o WeLog não apaga card de ninguém.
 *   · a Omie não é tocada. A reconciliação varre os pedidos dos ÚLTIMOS 2
 *     DIAS a cada 15 minutos e recria Projeto para todo pedido classificado
 *     nos projetos configurados. Pedido recente volta sozinho; para não
 *     voltar, tire-o do filtro na Omie antes.
 */
class ResetOperationalData extends Command
{
    use ConfirmableTrait;

    protected $signature = 'welog:base:reset
                            {--execute : Executa de verdade; sem isto é apenas simulação}
                            {--force : Pula a confirmação de produção (obrigatório com docker exec -T, que não tem TTY)}
                            {--manter-webhooks : Preserva o log de webhooks recebidos}';

    protected $description = 'Zera a operação (empresas, Projetos, resgates, usuários de cliente) e mantém a configuração';

    /** Papéis da equipe WeGift: são estes que sobrevivem ao reset. */
    private const PAPEIS_WEGIFT = [
        User::ROLE_ADMIN_WEGIFT,
        User::ROLE_WEGIFT_N1,
        User::ROLE_WEGIFT_N2,
    ];

    public function handle(): int
    {
        $usuariosCliente = User::whereNotIn('role', self::PAPEIS_WEGIFT);
        $equipe = User::whereIn('role', self::PAPEIS_WEGIFT)->count();

        if ($equipe === 0) {
            $this->error('Nenhuma conta da WeGift na base — o reset deixaria a plataforma sem ninguém que entra.');
            $this->line('  Crie o acesso da equipe antes de limpar.');

            return self::FAILURE;
        }

        $inventario = [
            ['Empresas', Company::count()],
            ['Projetos', Project::count()],
            ['  └ autorizações de resgate', ProjectEntitlement::count()],
            ['  └ resgates', Redemption::count()],
            ['     └ eventos de rastreio', TrackingEvent::count()],
            ['Usuários do lado do cliente', (clone $usuariosCliente)->count()],
            ['Importações Parceiros', AppnozImport::count()],
            ['  └ linhas', AppnozImportRow::count()],
            ['Log de webhooks', $this->option('manter-webhooks') ? 0 : WebhookEvent::count()],
        ];

        $this->newLine();
        $this->table(['Será apagado', 'Qtde'], $inventario);

        $this->line('<options=bold>Permanece:</> '.$equipe.' conta(s) da WeGift · catálogo de produtos · '
            .'templates de e-mail · benefícios fiscais · mapeamento do Trello');

        if (array_sum(array_column($inventario, 1)) === 0) {
            $this->newLine();
            $this->info('A base já está limpa.');

            return self::SUCCESS;
        }

        if (! $this->option('execute')) {
            $this->newLine();
            $this->warn('SIMULAÇÃO — nada foi apagado.');
            $this->line('  Confira o backup antes: <options=bold>php artisan welog:backup:status</>');
            $this->line('  Para executar de verdade, repita com <options=bold>--execute</>.');

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed('Isto apaga a operação inteira de forma IRREVERSÍVEL')) {
            return self::FAILURE;
        }

        $apagados = $this->apagar($usuariosCliente);

        $this->newLine();
        $this->info('Concluído.');
        $this->table(['Tabela', 'Apagados'], array_map(
            fn ($chave, $valor) => [$chave, $valor],
            array_keys($apagados),
            $apagados,
        ));

        $this->newLine();
        $this->warn('O Trello e a Omie NÃO foram tocados.');
        $this->line('  · Cards já criados seguem no quadro; arquive-os à mão.');
        $this->line('  · Pedido da Omie dos últimos 2 dias vira Projeto de novo na próxima varredura');
        $this->line('    (a cada 15 min). Para não voltar, tire o pedido do filtro na Omie.');

        return self::SUCCESS;
    }

    /**
     * A ordem é de baixo para cima na cadeia de chaves: filho antes do pai.
     * Numa transação só — um reset pela metade é pior que nenhum, porque
     * deixaria resgate apontando para Projeto que não existe mais.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $usuariosCliente
     * @return array<string, int>
     */
    private function apagar($usuariosCliente): array
    {
        return DB::transaction(function () use ($usuariosCliente) {
            $contagem = [
                'tracking_events' => TrackingEvent::query()->delete(),
                'redemptions' => Redemption::query()->delete(),
                'project_entitlements' => ProjectEntitlement::query()->delete(),
                'projects' => Project::query()->delete(),
                'appnoz_import_rows' => AppnozImportRow::query()->delete(),
                'appnoz_imports' => AppnozImport::query()->delete(),
                // Antes das empresas: o `company_id` do usuário é
                // `nullOnDelete`, então apagar a empresa primeiro deixaria a
                // conta órfã em vez de apagada.
                'users' => (clone $usuariosCliente)->delete(),
                'companies' => Company::query()->delete(),
            ];

            if (! $this->option('manter-webhooks')) {
                $contagem['webhook_events'] = WebhookEvent::query()->delete();
            }

            return $contagem;
        });
    }
}

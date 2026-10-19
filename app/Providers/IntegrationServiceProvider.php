<?php

namespace App\Providers;

use App\Contracts\Integrations\CepGateway;
use App\Contracts\Integrations\CorreiosGateway;
use App\Contracts\Integrations\FlashGateway;
use App\Contracts\Integrations\OmieGateway;
use App\Contracts\Integrations\ReconheceGateway;
use App\Contracts\Integrations\TrelloGateway;
use App\Integrations\Cep\CepFakeGateway;
use App\Integrations\Cep\ViaCepGateway;
use App\Integrations\Correios\CorreiosApiGateway;
use App\Integrations\Correios\CorreiosFakeGateway;
use App\Integrations\Flash\FlashApiGateway;
use App\Integrations\Flash\FlashFakeGateway;
use App\Integrations\Omie\OmieAccounts;
use App\Integrations\Omie\OmieFakeGateway;
use App\Integrations\Reconhece\ReconheceApiGateway;
use App\Integrations\Reconhece\ReconheceFakeGateway;
use App\Integrations\Trello\TrelloApiGateway;
use App\Integrations\Trello\TrelloFakeGateway;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Binding contrato → implementação conforme o driver de cada integração
 * (config/integrations.php): 'fake' em dev/testes, 'api' com credenciais.
 */
class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // O fake é singleton de verdade: ele guarda estado (pedidos, estoque)
        // que os testes montam, e uma instância por conta perderia isso.
        $this->app->singleton(OmieFakeGateway::class);
        $this->app->singleton(OmieAccounts::class);

        /*
         * `OmieGateway` sem qualificação = conta PADRÃO da Omie.
         *
         * Todo código que não precisa saber de multi-CNPJ continua injetando o
         * contrato e recebendo a conta padrão. Quem precisa de uma conta
         * específica (webhook, reconsulta de um Projeto) pede o gateway ao
         * `OmieAccounts`.
         */
        $this->app->singleton(OmieGateway::class, function () {
            $driver = config('integrations.omie.driver');

            if (! in_array($driver, ['fake', 'api'], true)) {
                throw new InvalidArgumentException("Driver Omie inválido: {$driver}");
            }

            return app(OmieAccounts::class)->gateway();
        });

        $this->app->singleton(TrelloGateway::class, fn () => match ($driver = config('integrations.trello.driver')) {
            'fake' => new TrelloFakeGateway,
            'api' => new TrelloApiGateway(
                config('integrations.trello.base_url'),
                config('integrations.trello.key'),
                config('integrations.trello.token'),
            ),
            default => throw new InvalidArgumentException("Driver Trello inválido: {$driver}"),
        });

        $this->app->singleton(FlashGateway::class, fn () => match ($driver = config('integrations.flash.driver')) {
            'fake' => new FlashFakeGateway,
            'api' => new FlashApiGateway(
                (string) config('integrations.flash.base_url'),
                (string) config('integrations.flash.login'),
                (string) config('integrations.flash.senha'),
                (int) config('integrations.flash.cliente_id'),
                (array) config('integrations.flash.ctt_ids'),
                config('integrations.flash.key'),
                config('integrations.flash.import.usuario'),
                config('integrations.flash.import.senha'),
                filled(config('integrations.flash.import.ccusto_id')) ? (int) config('integrations.flash.import.ccusto_id') : null,
                config('integrations.flash.import.remetente'),
                (array) config('integrations.flash.import.contrato', []),
            ),
            default => throw new InvalidArgumentException("Driver Flash inválido: {$driver}"),
        });

        $this->app->singleton(ReconheceFakeGateway::class);

        $this->app->singleton(ReconheceGateway::class, fn () => match ($driver = config('integrations.reconhece.driver')) {
            'fake' => app(ReconheceFakeGateway::class),
            'api' => new ReconheceApiGateway(
                config('integrations.reconhece.base_url'),
                config('integrations.reconhece.api_key'),
            ),
            default => throw new InvalidArgumentException("Driver Reconhece inválido: {$driver}"),
        });

        $this->app->singleton(CorreiosGateway::class, fn () => match ($driver = config('integrations.correios.driver')) {
            'fake' => new CorreiosFakeGateway,
            'api' => new CorreiosApiGateway(
                config('integrations.correios.base_url'),
                config('integrations.correios.usuario'),
                config('integrations.correios.codigo_acesso'),
                config('integrations.correios.contrato'),
            ),
            default => throw new InvalidArgumentException("Driver Correios inválido: {$driver}"),
        });

        $this->app->singleton(CepGateway::class, fn () => match ($driver = config('integrations.cep.driver')) {
            'fake' => new CepFakeGateway,
            'api' => new ViaCepGateway(config('integrations.cep.base_url')),
            default => throw new InvalidArgumentException("Driver CEP inválido: {$driver}"),
        });
    }
}

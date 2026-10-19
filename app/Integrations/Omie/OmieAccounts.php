<?php

namespace App\Integrations\Omie;

use App\Contracts\Integrations\OmieGateway;
use InvalidArgumentException;
use Mockery\MockInterface;

/**
 * As contas (CNPJs) da Omie e o gateway de cada uma.
 *
 * A WeGift opera com mais de um CNPJ, e cada um é um aplicativo Omie com
 * app_key/app_secret próprios. Consultar um pedido com a chave da conta errada
 * devolve "não encontrado" — o dado não é compartilhado entre aplicativos.
 *
 * Quem identifica a conta é o TOKEN da URL do webhook: cada conta registra
 * `/webhooks/omie/{token}` com o seu token. O evento guarda a conta de origem,
 * e toda consulta posterior (ConsultarPedido, ConsultarCliente, estoque) usa a
 * chave daquela conta.
 *
 * Sem `OMIE_ACCOUNTS` configurado existe uma conta só, chamada `default`, com
 * as variáveis antigas — nada muda para quem tem um CNPJ.
 */
class OmieAccounts
{
    public const DEFAULT = 'default';

    /** @var array<string, OmieGateway> */
    private array $gateways = [];

    /**
     * Nomes das contas configuradas, sempre com ao menos uma.
     *
     * @return array<int, string>
     */
    public function names(): array
    {
        $names = (array) config('integrations.omie.accounts', []);

        return $names === [] ? [self::DEFAULT] : $names;
    }

    /** Conta usada quando ninguém disse qual: a primeira da lista. */
    public function defaultName(): string
    {
        return $this->names()[0];
    }

    public function isMultiAccount(): bool
    {
        return count($this->names()) > 1;
    }

    /**
     * Nome de exibição da conta: `OMIE_WEBOX_LABEL="WeBox"`.
     *
     * O nome técnico é curto porque vira prefixo de variável; quem opera a tela
     * precisa ler o CNPJ pelo nome que usa no dia a dia.
     */
    public function label(?string $account): string
    {
        $account = $this->resolveName($account);

        if ($account === self::DEFAULT) {
            return (string) (env('OMIE_LABEL') ?: 'Omie');
        }

        $rotulo = env('OMIE_'.strtoupper(str_replace('-', '_', $account)).'_LABEL');

        return (string) ($rotulo ?: ucfirst($account));
    }

    /** @return array<int, array{value: string, label: string}> para selects da interface */
    public function options(): array
    {
        return array_map(
            fn (string $conta) => ['value' => $conta, 'label' => $this->label($conta)],
            $this->names(),
        );
    }

    /** Credenciais da conta. @return array{app_key: ?string, app_secret: ?string, webhook_token: ?string} */
    public function credentials(string $account): array
    {
        // Conta única mantém as variáveis originais; contas nomeadas ganham
        // prefixo (OMIE_MATRIZ_APP_KEY). O fallback para as originais existe
        // para quem migra de uma conta para duas sem reescrever o .env todo.
        if ($account === self::DEFAULT) {
            return [
                'app_key' => config('integrations.omie.app_key'),
                'app_secret' => config('integrations.omie.app_secret'),
                'webhook_token' => config('integrations.omie.webhook_token'),
            ];
        }

        $prefix = 'OMIE_'.strtoupper(str_replace('-', '_', $account)).'_';

        return [
            'app_key' => env($prefix.'APP_KEY') ?: config('integrations.omie.app_key'),
            'app_secret' => env($prefix.'APP_SECRET') ?: config('integrations.omie.app_secret'),
            'webhook_token' => env($prefix.'WEBHOOK_TOKEN') ?: config('integrations.omie.webhook_token'),
        ];
    }

    /**
     * Gateway da conta. `null` devolve a conta padrão — é o caminho de todo
     * código que não precisa saber que existe mais de um CNPJ.
     */
    public function gateway(?string $account = null): OmieGateway
    {
        $account = $this->resolveName($account);

        return $this->gateways[$account] ??= $this->build($account);
    }

    /**
     * A conta dona deste token de webhook, ou null se nenhum bate.
     *
     * Comparação em tempo constante em TODAS as contas (sem early return):
     * o tempo de resposta não pode revelar qual token chegou perto.
     */
    public function accountForToken(string $token): ?string
    {
        $found = null;

        foreach ($this->names() as $account) {
            $expected = (string) ($this->credentials($account)['webhook_token'] ?? '');

            if ($expected !== '' && hash_equals($expected, $token)) {
                $found ??= $account;
            }
        }

        return $found;
    }

    /**
     * A conta pela `appKey` que a Omie manda DENTRO do webhook.
     *
     * O token da URL continua sendo a credencial — é ele que autoriza —, mas
     * ele depende de cada conta ter registrado a URL certa no painel. A
     * `appKey` vem no corpo do evento e diz de qual aplicativo ele saiu, sem
     * depender de configuração nossa. Serve para dois casos:
     *
     *   · uma conta registrou a URL de outra (ou a mesma URL nas duas), e o
     *     token sozinho apontaria a conta errada;
     *   · conferir, nos eventos já gravados, de qual CNPJ veio cada um.
     *
     * Devolve null quando a chave não é de nenhuma conta configurada, e aí
     * quem chama fica com o que o token disse.
     */
    public function accountForAppKey(?string $appKey): ?string
    {
        if ($appKey === null || $appKey === '') {
            return null;
        }

        foreach ($this->names() as $account) {
            if ((string) ($this->credentials($account)['app_key'] ?? '') === $appKey) {
                return $account;
            }
        }

        return null;
    }

    /** Nome válido e conhecido, ou exceção — erro de digitação não vira consulta na conta errada. */
    public function resolveName(?string $account): string
    {
        if ($account === null || $account === '') {
            return $this->defaultName();
        }

        if (! in_array($account, $this->names(), true)) {
            throw new InvalidArgumentException(
                "Conta Omie desconhecida: {$account}. Configuradas: ".implode(', ', $this->names())
            );
        }

        return $account;
    }

    private function build(string $account): OmieGateway
    {
        $driver = config('integrations.omie.driver');

        /*
         * Dublê registrado no container vale para TODAS as contas.
         *
         * Um teste que faz `$this->mock(OmieGateway::class)` está dizendo "toda
         * conversa com a Omie passa por aqui" — respeitar isso é o que mantém
         * a suíte falando de comportamento, e não de qual CNPJ instanciou o
         * cliente HTTP. Fora de teste não existe mock, então nada muda.
         */
        if (app()->resolved(OmieGateway::class)) {
            $registrado = app(OmieGateway::class);

            if ($registrado instanceof MockInterface) {
                return $registrado;
            }
        }

        if ($driver === 'fake') {
            return app(OmieFakeGateway::class);
        }

        if ($driver !== 'api') {
            throw new InvalidArgumentException("Driver Omie inválido: {$driver}");
        }

        $cred = $this->credentials($account);

        return new OmieApiGateway(
            config('integrations.omie.base_url'),
            (string) $cred['app_key'],
            (string) $cred['app_secret'],
        );
    }
}

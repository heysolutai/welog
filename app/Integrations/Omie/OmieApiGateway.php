<?php

namespace App\Integrations\Omie;

use App\Contracts\Integrations\OmieGateway;
use App\Integrations\Support\ApiCircuitBreaker;
use App\Integrations\Support\ApiThrottle;
use App\Integrations\Support\OmieDate;
use App\Integrations\Support\ResilientHttp;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;

/**
 * Cliente REST da Omie (driver 'api'). Padrão da API Omie: POST em
 * /{recurso}/ com JSON {call, app_key, app_secret, param: [...]}.
 *
 * NOTA: o mapeamento fino de campos será validado quando as credenciais
 * do ambiente da CONTRATANTE forem disponibilizadas (reunião de
 * acompanhamento) — a estrutura das chamadas segue a documentação pública.
 */
class OmieApiGateway implements OmieGateway
{
    /** Janela em que o mesmo CPF não volta a ser consultado na Omie. */
    private const CLIENTE_CACHE_SEGUNDOS = 120;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $appKey,
        private readonly string $appSecret,
    ) {}

    private function client(): PendingRequest
    {
        return ResilientHttp::for('omie', $this->baseUrl);
    }

    private function call(string $resource, string $call, array $param): array
    {
        /*
         * A Omie já recusou o acesso há pouco? Então nem sai daqui.
         *
         * Sem esta porta, uma fila cheia continua tentando sozinha: cada job
         * leva o mesmo 403 e reforça o castigo do outro lado, transformando um
         * bloqueio de minutos em aplicativo suspenso. Falhar agora é de
         * propósito — a fila reenfileira com backoff e tenta quando a janela
         * já tiver passado.
         */
        ApiCircuitBreaker::ensureClosed('omie');

        // Teto de consumo da conta — passar disso bloqueia a integração inteira.
        ApiThrottle::acquire('omie', (int) config('integrations.omie.requests_per_minute'));

        /*
         * Intervalo mínimo entre chamadas do MESMO método.
         *
         * O teto por minuto não impede rajada: a varredura dispara um
         * `ListarPedidos` por projeto configurado, um atrás do outro, e o log
         * da Omie mostrou seis no mesmo segundo — as primeiras com 200, as
         * seguintes com 500, e no fim a conta suspensa. Espaçar por método
         * resolve sem penalizar o webhook, que alterna pedido → cliente →
         * produto.
         */
        ApiThrottle::spaceOut('omie', $call, (int) config('integrations.omie.min_interval_ms'));

        $response = $this->client()->post("/{$resource}/", [
            'call' => $call,
            'app_key' => $this->appKey,
            'app_secret' => $this->appSecret,
            'param' => [$param],
        ]);

        $body = $response->json();

        /*
         * O `faultstring` vem ANTES do status, de propósito.
         *
         * A Omie responde erro de NEGÓCIO com HTTP 500 ("Pedido não localizado
         * na base de dados"). Lendo o status primeiro, a exceção carregava o
         * corpo cru — com os acentos escapados em JSON (`não`) —, e a
         * comparação de `isNotFound()` nunca casava: consulta de pedido
         * inexistente virava exceção em vez de null, o job falhava e era
         * repetido três vezes, gastando três chamadas onde bastava zero.
         */
        $fault = is_array($body) ? ($body['faultstring'] ?? null) : null;

        if ($fault !== null) {
            throw $this->abrirCircuitoSePreciso(
                new OmieApiException("Omie {$call} falhou: {$fault}", $body['faultcode'] ?? null),
            );
        }

        if ($response->failed()) {
            throw $this->abrirCircuitoSePreciso(new OmieApiException(
                "Omie {$call} falhou: HTTP {$response->status()} - ".$response->body(),
            ));
        }

        return $body;
    }

    /**
     * Recusa de ACESSO (suspensão, chave negada, consumo bloqueado) pausa a
     * integração inteira; erro de negócio (pedido não encontrado, campo
     * inválido) não pausa nada — é resposta, não porta fechada.
     *
     * A Omie costuma dizer quanto esperar ("Aguarde 56 segundos"); quando diz,
     * a pausa é esse tempo com folga, senão vale o padrão do disjuntor.
     */
    private function abrirCircuitoSePreciso(OmieApiException $e): OmieApiException
    {
        if ($e->isBlocked()) {
            $pedido = $e->retryAfterSeconds();

            ApiCircuitBreaker::open(
                'omie',
                $e->getMessage(),
                $pedido === null ? null : $pedido + 15,
            );
        }

        return $e;
    }

    /** Erros de "registro não encontrado" viram null; o resto propaga. */
    private function callOrNull(string $resource, string $call, array $param): ?array
    {
        try {
            return $this->call($resource, $call, $param);
        } catch (OmieApiException $e) {
            return $e->isNotFound() ? null : throw $e;
        }
    }

    public function listOrders(?CarbonInterface $since = null, int $page = 1, ?string $projectCode = null): array
    {
        $param = ['pagina' => $page, 'registros_por_pagina' => 100];

        if ($since !== null) {
            $param['filtrar_por_data_de'] = OmieDate::forFilter($since);
            $param['filtrar_apenas_alteracao'] = 'N';
        }

        // Filtro na ORIGEM. Medido na conta real: dois dias sem ele são 745
        // pedidos em 15 páginas, quase nenhum WeLog; com ele, o mesmo recorte
        // cabe numa página. É o que permite a varredura rodar de minuto em
        // minuto sem baixar a operação inteira da Santa Origem a cada volta.
        if ($projectCode !== null && $projectCode !== '') {
            $param['filtrar_por_projeto'] = (int) $projectCode;
        }

        $body = $this->call('produtos/pedido', 'ListarPedidos', $param);

        return [
            'items' => $body['pedido_venda_produto'] ?? [],
            'total_pages' => (int) ($body['total_de_paginas'] ?? 1),
        ];
    }

    public function getOrder(string $omieOrderId): ?array
    {
        $body = $this->callOrNull('produtos/pedido', 'ConsultarPedido', [
            'codigo_pedido' => (int) $omieOrderId,
        ]);

        return $body['pedido_venda_produto'] ?? $body;
    }

    public function listProjects(int $page = 1): array
    {
        $body = $this->call('geral/projetos', 'ListarProjetos', [
            'pagina' => $page,
            'registros_por_pagina' => 100,
        ]);

        return [
            'items' => array_map(fn (array $p) => [
                'codigo' => (string) ($p['codigo'] ?? ''),
                'nome' => (string) ($p['nome'] ?? ''),
                // A Omie devolve "S"/"N", não booleano.
                'inativo' => ($p['inativo'] ?? 'N') === 'S',
            ], $body['cadastro'] ?? []),
            'total_pages' => (int) ($body['total_de_paginas'] ?? 1),
        ];
    }

    public function createOrder(array $payload): array
    {
        $body = $this->call('produtos/pedido', 'IncluirPedido', $this->pedidoDeVenda($payload));
        $numero = (string) ($body['numero_pedido'] ?? '');

        return [
            'omie_order_id' => (string) ($body['codigo_pedido'] ?? ''),
            // A resposta da inclusão traz o número com zeros à esquerda
            // ("000000000070669"); a tela da Omie e o ConsultarPedido, sem.
            // Gravado como veio, a busca pelo número que o operador vê não
            // achava o resgate.
            'omie_order_number' => ltrim($numero, '0') !== '' ? ltrim($numero, '0') : $numero,
        ];
    }

    /**
     * O pedido no formato do IncluirPedido.
     *
     * Quem chama descreve o pedido em poucas chaves (chave de integração,
     * cliente, itens); a Omie quer `cabecalho`, `det` e `informacoes_adicionais`
     * e recusa campo fora do lugar ("Tag [CODIGO_CLIENTE] não faz parte da
     * estrutura do tipo complexo [pedido_venda_produto]"). Os valores da conta
     * — categoria, conta corrente, cenário de impostos, projeto — vêm de
     * `integrations.omie.pedido`, copiados de um resgate feito à mão.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function pedidoDeVenda(array $p): array
    {
        // Já no formato da Omie: quem montou sabe o que está mandando.
        if (isset($p['cabecalho'])) {
            return $p;
        }

        $cfg = (array) config('integrations.omie.pedido', []);
        $chave = (string) ($p['codigo_pedido_integracao'] ?? '');
        $local = (int) config('integrations.omie.stock_location', 0);
        $preenchido = fn ($v) => $v !== null && $v !== '' && $v !== 0;

        $itens = [];

        foreach (array_values((array) ($p['det'] ?? [])) as $i => $item) {
            $produto = (array) ($item['produto'] ?? []);

            $inf = array_filter([
                'codigo_beneficio_fiscal' => $produto['cbenef'] ?? null,
                'codigo_categoria_item' => $cfg['categoria'] ?? null,
                'codigo_local_estoque' => $local,
            ], $preenchido);

            $itens[] = array_filter([
                'ide' => ['codigo_item_integracao' => $chave.'-'.($i + 1)],
                'produto' => [
                    // O ID interno é como a Omie identifica o item no pedido;
                    // o código ("1034") só serve de reserva.
                    ...(isset($produto['codigo_produto'])
                        ? ['codigo_produto' => (int) $produto['codigo_produto']]
                        : ['codigo' => (string) ($produto['codigo'] ?? '')]),
                    'quantidade' => (float) ($produto['quantidade'] ?? 1),
                    'valor_unitario' => (float) ($produto['valor_unitario'] ?? 0),
                ],
                'inf_adic' => $inf === [] ? null : $inf,
            ], fn ($v) => $v !== null);
        }

        return array_filter([
            'cabecalho' => array_filter([
                'codigo_pedido_integracao' => $chave,
                'codigo_cliente' => (int) ($p['codigo_cliente'] ?? 0),
                'data_previsao' => OmieDate::forFilter(now()),
                'etapa' => (string) ($p['etapa'] ?? '10'),
                'codigo_parcela' => (string) ($cfg['parcela'] ?? '000'),
                'quantidade_itens' => count($itens),
                'codigo_cenario_impostos' => $cfg['cenario_impostos'] ?? null,
            ], $preenchido),
            'det' => $itens,
            'informacoes_adicionais' => array_filter([
                'codigo_categoria' => $cfg['categoria'] ?? null,
                'codigo_conta_corrente' => (int) ($cfg['conta_corrente'] ?? 0),
                'codProj' => (int) ($cfg['projeto'] ?? 0),
                'consumidor_final' => 'S',
                'enviar_email' => 'N',
            ], $preenchido),
            'observacoes' => filled($p['observacao'] ?? null) ? ['obs_venda' => (string) $p['observacao']] : null,
        ], fn ($v) => $v !== null);
    }

    public function findCustomerByDocument(string $document): ?array
    {
        /*
         * O mesmo CPF não é perguntado duas vezes em seguida.
         *
         * A Omie recusa a MESMA chamada repetida em menos de ~60s ("Consumo
         * redundante detectado. Aguarde 59 segundos"), e repetir é o normal
         * no Fluxo B: dois resgates da mesma pessoa no mesmo lote consultam o
         * CPF com dois segundos de diferença. Só o cliente ENCONTRADO fica
         * guardado — "não existe" deixa de valer no instante do cadastro, e
         * o cadastro (upsertCustomer) grava aqui o que acabou de criar.
         */
        $chave = $this->chaveCliente($document);

        if (($guardado = Cache::get($chave)) !== null) {
            return $guardado;
        }

        $body = $this->callOrNull('geral/clientes', 'ListarClientes', [
            'pagina' => 1,
            'registros_por_pagina' => 1,
            'clientesFiltro' => ['cnpj_cpf' => $document],
        ]);

        $cadastro = $body['clientes_cadastro'][0] ?? null;

        if ($cadastro === null) {
            return null;
        }

        // Normaliza igual ao `getCustomer`. Antes devolvia o registro CRU da
        // Omie, onde o código se chama `codigo_cliente_omie` — e quem consome
        // (o Fluxo B) lê `omie_customer_code`. Com o gateway fake, que já
        // guardava a chave normalizada, os testes passavam; contra a API real
        // o Pedido de Venda de um cliente JÁ CADASTRADO saía sem cliente.
        $cliente = $this->normalizeCustomer($cadastro);

        Cache::put($chave, $cliente, self::CLIENTE_CACHE_SEGUNDOS);

        return $cliente;
    }

    /** Por aplicativo: cada CNPJ da WeGift enxerga só os próprios clientes. */
    private function chaveCliente(string $document): string
    {
        return 'omie:cliente:'.sha1($this->appKey).':'.preg_replace('/\D/', '', $document);
    }

    public function getCustomer(string $omieCustomerCode): ?array
    {
        $body = $this->callOrNull('geral/clientes', 'ConsultarCliente', [
            'codigo_cliente_omie' => (int) $omieCustomerCode,
        ]);

        return $body === null ? null : $this->normalizeCustomer($body, $omieCustomerCode);
    }

    /**
     * Registro de cliente da Omie no vocabulário da WeLog.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, ?string>
     */
    private function normalizeCustomer(array $body, ?string $fallbackCode = null): array
    {
        // Telefone vem quebrado em DDD + número na Omie
        $telefone = trim(($body['telefone1_ddd'] ?? '').' '.($body['telefone1_numero'] ?? ''));

        return [
            'omie_customer_code' => (string) ($body['codigo_cliente_omie'] ?? $fallbackCode),
            'document' => $body['cnpj_cpf'] ?? null,
            'name' => $body['razao_social'] ?? $body['nome_fantasia'] ?? null,
            'trade_name' => $body['nome_fantasia'] ?? null,
            'contact_name' => $body['contato'] ?? null,
            'contact_email' => $body['email'] ?? null,
            'contact_phone' => $telefone ?: null,
            'zip' => $body['cep'] ?? null,
            'street' => $body['endereco'] ?? null,
            'number' => $body['endereco_numero'] ?? null,
            'complement' => $body['complemento'] ?? null,
            'district' => $body['bairro'] ?? null,
            'city' => $body['cidade'] ?? null,
            'state' => $body['estado'] ?? null,
        ];
    }

    public function upsertCustomer(array $data): array
    {
        $body = $this->call('geral/clientes', 'UpsertCliente', $data);
        $codigo = (string) ($body['codigo_cliente_omie'] ?? '');

        // Recém-gravado: o próximo resgate do mesmo CPF o encontra aqui, sem
        // repetir o ListarClientes que a Omie recusaria por redundância.
        if ($codigo !== '' && filled($data['cnpj_cpf'] ?? null)) {
            Cache::put(
                $this->chaveCliente((string) $data['cnpj_cpf']),
                $this->normalizeCustomer($data, $codigo),
                self::CLIENTE_CACHE_SEGUNDOS,
            );
        }

        return ['omie_customer_code' => $codigo];
    }

    public function listProducts(int $page = 1): array
    {
        $body = $this->call('geral/produtos', 'ListarProdutos', [
            'pagina' => $page,
            'registros_por_pagina' => 200,
            'apenas_importado_api' => 'N',
            'filtrar_apenas_omiepdv' => 'N',
            // O tamanho de cada variação da grade vem daqui ("Tamanho Corta
            // Vento: PP"); sem o parâmetro, a listagem não manda.
            'exibir_caracteristicas' => 'S',
        ]);

        return [
            'items' => $body['produto_servico_cadastro'] ?? [],
            'total_pages' => (int) ($body['total_de_paginas'] ?? 1),
        ];
    }

    public function findProductByCode(string $code): ?array
    {
        $product = $this->callOrNull('geral/produtos', 'ConsultarProduto', ['codigo' => $code]);

        return $product === null ? null : $this->withKitStructure($product);
    }

    /**
     * Traduz a estrutura de kit da Omie para o formato que o espelho consome.
     *
     * O `ConsultarProduto` já devolve `tipoItem` ("KT" = kit) e
     * `componentes_kit` — não é preciso segunda API. O que atrapalha é a
     * chave: o componente vem pelo ID INTERNO (`codigo_produto_componente`,
     * ex. 7605941668) e o catálogo daqui é indexado pelo código do produto
     * ("170"). Sem traduzir, o kit era espelhado sem nenhum item, e a aba
     * "Itens do kit" ficava vazia em produção enquanto passava nos testes —
     * o gateway fake devolvia a estrutura pronta e escondia a diferença.
     *
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>
     */
    private function withKitStructure(array $product): array
    {
        $components = $product['componentes_kit'] ?? [];

        // Só o `ConsultarProduto` devolve estrutura; o `ListarProdutos` não.
        // A marca diz ao espelho que a ausência de componentes aqui é resposta,
        // não falta de pergunta — é o que permite parar de reconsultar.
        $product['structure_checked'] = true;
        $product['is_kit'] = ($product['tipoItem'] ?? null) === 'KT' || $components !== [];
        $product['components'] = [];
        // Descrição de cada componente: sem ela, um subitem que ainda não foi
        // espelhado entra no catálogo chamado "170" — o código no lugar do nome,
        // bem na aba que existe para dizer o que tem dentro do kit.
        $product['component_names'] = [];

        foreach ($components as $component) {
            $internalId = $component['codigo_produto_componente'] ?? null;

            if ($internalId === null) {
                continue;
            }

            ['code' => $code, 'name' => $name] = $this->componentIdentity((int) $internalId);

            if ($code !== null) {
                $product['components'][$code] = (float) ($component['quantidade_componente'] ?? 1);
                $product['component_names'][$code] = $name;
            }
        }

        return $product;
    }

    /**
     * ID interno → código e descrição do componente.
     *
     * Um kit repete os mesmos componentes em todo pedido que o usa, e a
     * reconciliação varre dezenas de pedidos por vez: sem o cache, cada
     * componente viraria uma consulta por pedido e a conta cairia de novo na
     * janela de castigo da Omie.
     *
     * @return array{code: ?string, name: ?string}
     */
    private function componentIdentity(int $internalId): array
    {
        return Cache::remember(
            "omie:component:{$internalId}",
            (int) config('integrations.omie.product_code_cache_seconds', 86400),
            function () use ($internalId) {
                $component = $this->callOrNull('geral/produtos', 'ConsultarProduto', [
                    'codigo_produto' => $internalId,
                ]);

                return [
                    'code' => $component['codigo'] ?? null,
                    'name' => $component['descricao'] ?? null,
                ];
            },
        );
    }

    /** Registros por página no `ListarPosEstoque` — o teto da Omie é 500. */
    private const STOCK_PAGE_SIZE = 500;

    /**
     * Posição de estoque pela consulta EM LOTE (`ListarPosEstoque`).
     *
     * Antes era um `PosicaoEstoque` por código: ~140 chamadas a cada
     * reconciliação, de hora em hora, multiplicadas por três tentativas. A
     * Omie responde a esse volume com `Consumo redundante detectado. Aguarde
     * N segundos` — uma janela de castigo no endpoint inteiro, que derrubou a
     * reconciliação 243 vezes seguidas. Cada nova tentativa dentro da janela
     * só a renovava.
     *
     * O catálogo inteiro cabe em 3 páginas. O mapa fica em cache por alguns
     * minutos porque a reconciliação REFAZ o trabalho a cada tentativa — sem
     * isso, o retry paga o mesmo pedágio de novo.
     */
    public function getStockPositions(array $productCodes): array
    {
        $balances = Cache::remember(
            'omie:stock:'.OmieDate::forFilter(now()),
            (int) config('integrations.omie.stock_cache_seconds', 300),
            fn () => $this->fetchAllStockBalances(),
        );

        $positions = [];

        foreach ($productCodes as $code) {
            // Código ausente do relatório (produto inativo, sem movimento) é
            // saldo zero — mesma leitura da consulta individual anterior.
            $positions[$code] = (float) ($balances[$code] ?? 0);
        }

        return $positions;
    }

    /** @return array<string, float> código do produto => saldo */
    private function fetchAllStockBalances(): array
    {
        $balances = [];
        $page = 1;

        do {
            $body = $this->call('estoque/consulta', 'ListarPosEstoque', [
                'nPagina' => $page,
                'nRegPorPagina' => self::STOCK_PAGE_SIZE,
                'dDataPosicao' => OmieDate::forFilter(now()),
            ]);

            foreach ($body['produtos'] ?? [] as $item) {
                $code = $item['cCodigo'] ?? null;

                if ($code !== null) {
                    $balances[$code] = (float) ($item['nSaldo'] ?? 0);
                }
            }

            $page++;
        } while ($page <= (int) ($body['nTotPaginas'] ?? 1));

        return $balances;
    }

    /**
     * Baixa de estoque via ajuste manual (`estoque/ajuste`,
     * `IncluirAjusteEstoque`). Estrutura conforme a documentação pública da
     * Omie: o ajuste identifica o produto pelo ID INTERNO (`id_prod`), não
     * pelo código do catálogo — daí a tradução cacheada abaixo. `tipo` "SAI" é
     * a saída; `origem` "AJU" marca o lançamento como ajuste via API. Como nos
     * demais métodos, o mapeamento fino será confirmado contra a conta real
     * (mesma nota do topo da classe).
     */
    public function decreaseStock(string $productCode, float $quantity, string $reason): void
    {
        $this->adjustStock($productCode, $quantity, 'SAI', $reason);
    }

    /** Entrada de ajuste: devolução de saldo quando um resgate é cancelado. */
    public function increaseStock(string $productCode, float $quantity, string $reason): void
    {
        $this->adjustStock($productCode, $quantity, 'ENT', $reason);
    }

    private function adjustStock(string $productCode, float $quantity, string $tipo, string $reason): void
    {
        $internalId = $this->productInternalId($productCode);

        if ($internalId === null) {
            throw new OmieApiException(
                "Omie IncluirAjusteEstoque falhou: produto {$productCode} não encontrado no catálogo Omie.",
            );
        }

        $param = [
            'id_prod' => $internalId,
            'data' => OmieDate::forFilter(now()),
            'quan' => $quantity,
            'tipo' => $tipo,
            'motivo' => 'AJU',
            'obs' => $reason,
            'origem' => 'AJU',
        ];

        // Local de estoque só quando configurado: omitido, a Omie usa o padrão
        // da conta (o cenário de local único, que é o da operação hoje).
        $local = (int) config('integrations.omie.stock_location', 0);
        if ($local > 0) {
            $param['codigo_local_estoque'] = $local;
        }

        $this->call('estoque/ajuste', 'IncluirAjusteEstoque', $param);
    }

    /**
     * Código do catálogo → ID interno da Omie (inverso de `componentIdentity`).
     * Cache longo pelo mesmo motivo: o ID é imutável no cadastro, e cada baixa
     * de resgate repetiria a consulta do mesmo produto.
     */
    private function productInternalId(string $code): ?int
    {
        return Cache::remember(
            "omie:product-id:{$code}",
            (int) config('integrations.omie.product_code_cache_seconds', 86400),
            function () use ($code) {
                $product = $this->callOrNull('geral/produtos', 'ConsultarProduto', ['codigo' => $code]);

                $id = $product['codigo_produto'] ?? null;

                return $id === null ? null : (int) $id;
            },
        );
    }

    public function listInvoices(?CarbonInterface $since = null, int $page = 1): array
    {
        $param = ['pagina' => $page, 'registros_por_pagina' => 100];

        if ($since !== null) {
            $param['filtrar_por_data_de'] = OmieDate::forFilter($since);
        }

        $body = $this->call('produtos/nfconsultar', 'ListarNF', $param);

        return array_map(fn (array $nf) => [
            'nf_key' => $nf['compl']['cChaveNFe'] ?? '',
            'omie_order_id' => (string) ($nf['pedido']['nCodPed'] ?? ''),
            'issued_at' => $nf['ide']['dEmi'] ?? null,
        ], $body['nfCadastro'] ?? []);
    }

    public function findInvoiceByOrder(string $omieOrderId): ?array
    {
        $body = $this->callOrNull('produtos/nfconsultar', 'ConsultarNF', [
            'nIdPedido' => (int) $omieOrderId,
        ]);

        // A resposta É o cadastro da nota (ide, compl…); a ListarNF o embrulha
        // em `nfCadastro`. Aceita os dois.
        $nf = $body['nfCadastro'] ?? $body;
        $nf = array_is_list((array) $nf) ? ($nf[0] ?? null) : $nf;
        $chave = preg_replace('/\D/', '', (string) ($nf['compl']['cChaveNFe'] ?? ''));

        if ($nf === null || strlen($chave) !== 44) {
            return null;
        }

        // "30/09/2026" + "14:18:38" são hora de Brasília; o app guarda em UTC.
        $hora = (string) ($nf['ide']['hEmi'] ?? '');
        $hora = preg_match('/^\d{2}:\d{2}$/', $hora) ? $hora.':00' : ($hora !== '' ? $hora : '00:00:00');
        $emissao = rescue(fn () => CarbonImmutable::createFromFormat(
            'd/m/Y H:i:s',
            trim((string) ($nf['ide']['dEmi'] ?? '')).' '.$hora,
            config('integrations.business_timezone'),
        )->utc(), null, false);

        return [
            'nf_key' => $chave,
            'nf_number' => ltrim((string) ($nf['ide']['nNF'] ?? ''), '0') ?: null,
            'series' => isset($nf['ide']['serie']) ? (string) $nf['ide']['serie'] : null,
            'issued_at' => $emissao,
            'omie_invoice_id' => isset($nf['compl']['nIdNF']) ? (string) $nf['compl']['nIdNF'] : null,
        ];
    }

    public function getInvoiceXml(string $nfKey): ?string
    {
        $body = $this->callOrNull('produtos/dfedocs', 'ObterDfeDocs', ['nChaveDfe' => $nfKey]);

        $xml = $body['listaDocumentos'][0]['cXml'] ?? null;

        return $xml !== null ? base64_decode($xml, true) ?: $xml : null;
    }
}

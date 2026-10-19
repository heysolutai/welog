<?php

namespace App\Services\FluxoA;

use App\Contracts\Integrations\OmieGateway;
use App\Integrations\Omie\OmieAccounts;
use App\Integrations\Support\OmieDate;
use App\Models\Company;
use App\Models\Product;
use App\Models\Project;
use App\Services\Catalog\MirrorOmieProduct;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fluxo A, etapa 3: a partir do Pedido de Venda registrado na Omie, cria o
 * Projeto com os dados macro (cliente, quantidades, prazo) e dispara a
 * criação do card no Trello.
 */
class CreateProjectFromOmieOrder
{
    /**
     * Por que o último `handle()` não criou Projeto — para quem chamou poder
     * dizer isso em vez de só devolver null.
     *
     * Existe por causa do Log de Webhooks: um pedido recusado pelo filtro
     * aparecia como "Processado" sem Projeto nenhum, e quem olhava concluía
     * que o sistema estava lento quando ele tinha, de propósito, ignorado o
     * pedido. O motivo é lido logo após a chamada (`ProcessOmieWebhook`).
     */
    private ?string $motivoDaRecusa = null;

    public function motivoDaRecusa(): ?string
    {
        return $this->motivoDaRecusa;
    }

    /**
     * Quantas vezes uma ALTERAÇÃO pode fazer a plataforma reconsultar um
     * pedido que ainda não virou Projeto.
     *
     * Três cobre o caso real — classificar o projeto na Omie logo depois de
     * criar o pedido, às vezes em duas tentativas — e segura um pedido que
     * fica sendo editado o dia todo sem nunca ganhar classificação.
     */
    private const RECONSULTAS_POR_PEDIDO = 3;

    /**
     * Teto de tamanhos consultados numa grade. A maior grade real tem sete
     * (PP a G4); o teto só segura um cadastro torto com centenas de `_N`.
     */
    private const MAX_TAMANHOS = 20;

    private static function chaveAguardandoClassificacao(string $omieOrderId): string
    {
        return "omie:aguarda-classificacao:{$omieOrderId}";
    }

    /**
     * Vale gastar uma consulta nesta alteração?
     *
     * Só para pedido que JÁ passou por aqui e foi recusado por estar SEM
     * classificação — a única recusa que o tempo pode desfazer. Alteração de
     * pedido que nunca tentou entrar, ou que está classificado em outro
     * projeto, não consulta nada: a conta da Omie atende a operação inteira e
     * essas alterações chegam o dia todo.
     */
    private function podeReconsultar(string $omieOrderId): bool
    {
        if (! Cache::has(self::chaveAguardandoClassificacao($omieOrderId))) {
            return false;
        }

        $chave = "omie:reconsulta-alteracao:{$omieOrderId}";
        $jaFeitas = (int) Cache::get($chave, 0);

        if ($jaFeitas >= self::RECONSULTAS_POR_PEDIDO) {
            return false;
        }

        Cache::put($chave, $jaFeitas + 1, now()->addWeek());

        return true;
    }

    public function __construct(
        private readonly OmieAccounts $accounts,
        private readonly MirrorOmieProduct $mirrorProduct,
        private readonly CreateTrelloCardForProject $createTrelloCard,
        private readonly OmieProjectMarker $marker,
        /**
         * Gateway fixo, ignorando a conta. Só quem monta o serviço à mão usa
         * (testes que apontam para uma resposta específica da Omie); no app o
         * caminho é sempre a conta do evento.
         */
        private readonly ?OmieGateway $omie = null,
    ) {}

    /**
     * @param  bool  $create  false = só atualiza um Projeto que já existe.
     *                        Nasce de `VendaProduto.Alterada`: alteração não é
     *                        origem de Projeto. Aceitar que ela criasse faria
     *                        um pedido que nunca passou pela inclusão (ou cuja
     *                        inclusão foi recusada de propósito, como os do
     *                        Fluxo B) entrar na esteira pela porta dos fundos.
     */
    public function handle(string $omieOrderId, bool $create = true, ?string $account = null): ?Project
    {
        $this->motivoDaRecusa = null;

        // Cada CNPJ da WeGift e um aplicativo Omie: a consulta precisa sair da
        // conta que enviou o evento, senao o pedido "nao existe".
        $omie = $this->omie ?? $this->accounts->gateway($account);

        /*
         * ALTERAÇÃO de pedido que ainda não tem Projeto: reconsultar, com teto.
         *
         * O caso real: o pedido nasce na Omie e só depois alguém classifica o
         * projeto ("Welog - Amostra"). Na inclusão, o `ConsultarPedido` ainda
         * não trazia classificação nenhuma, o filtro recusou, e a alteração
         * que trouxe a classificação era descartada aqui sem nem olhar — o
         * pedido só entrava quando alguém clicava em reprocessar à mão.
         *
         * O filtro continua sendo a autoridade: quem não passa nele segue
         * fora, e isso mantém Suvinil e os demais no lugar deles.
         *
         * O TETO existe porque a conta da Omie atende a operação inteira, e
         * alteração de pedido que não é nosso chega o tempo todo. Sem ele,
         * cada uma viraria um ConsultarPedido — e foi rajada de consulta que
         * suspendeu a conta. Depois de algumas tentativas por pedido, a
         * alteração volta a ser descartada sem consultar.
         */
        if (! $create && ! Project::where('omie_order_id', $omieOrderId)->exists()) {
            if (! $this->podeReconsultar($omieOrderId)) {
                $this->motivoDaRecusa = 'Alteração de pedido que não tem Projeto na plataforma: nada a atualizar.';
                Log::info('Alteração de pedido sem Projeto correspondente — nada a atualizar.', [
                    'omie_order_id' => $omieOrderId,
                ]);

                return null;
            }

            Log::info('Alteração de pedido que estava sem classificação — reconsultando na Omie.', [
                'omie_order_id' => $omieOrderId,
            ]);

            // Segue adiante: o pedido é consultado e, se passar no filtro
            // agora, NASCE aqui. Quem não passar cai no motivo de sempre,
            // logo abaixo.
        }

        $order = $omie->getOrder($omieOrderId);

        if ($order === null) {
            $this->motivoDaRecusa = 'Pedido não encontrado na Omie (conta consultada: '.($account ?: 'padrão').').';
            Log::warning('Pedido Omie não encontrado ao criar Projeto', ['omie_order_id' => $omieOrderId]);

            return null;
        }

        // A conta da Omie é a da operação inteira: sem esta porta, todo pedido
        // de venda da Santa Origem virava Projeto WeLog.
        // O filtro é por conta: cada CNPJ tem o próprio código de "Venda".
        if (! $this->marker->matches($order, $account)) {
            /*
             * Pedido SEM classificação nenhuma fica marcado como "pode mudar".
             *
             * É a única recusa reversível: o operador cria o pedido e
             * classifica o projeto minutos depois, e a alteração que traz a
             * classificação precisa poder criar o Projeto. Pedido já
             * classificado em OUTRO projeto (Suvinil e afins) não entra nesta
             * marca: ele não vai virar nosso, e reconsultá-lo a cada alteração
             * gastaria chamada da Omie para sempre responder a mesma coisa.
             */
            // `projectCodeOf` já devolve string vazia para pedido sem projeto
            // (inclusive quando a Omie manda 0), então esta é a checagem.
            if ($this->marker->projectCodeOf($order) === '') {
                Cache::put(self::chaveAguardandoClassificacao($omieOrderId), true, now()->addWeek());
            }

            // O motivo nomeia o CÓDIGO que reprovou e os aceitos: é a diferença
            // entre "o sistema está lento" e "este pedido é de um projeto da
            // Omie que não está na lista" — que foi exatamente a dúvida que
            // este texto veio responder no Log de Webhooks.
            $codigos = $this->marker->projectCodes($account);
            $this->motivoDaRecusa = 'Pedido do projeto Omie '
                .($this->marker->projectCodeOf($order) ?: 'não classificado')
                .', fora do filtro em vigor ('
                .($codigos === [] ? 'nenhum código configurado' : implode(', ', $codigos)).').';

            // O log registra o critério em vigor E o valor que o reprovou: sem
            // os dois, "ignorado" é indistinguível de um filtro mal configurado
            // barrando pedido legítimo.
            Log::info('Pedido Omie fora do escopo do Projeto WeLog — ignorado.', [
                'omie_order_id' => $omieOrderId,
                'numero_pedido' => $order['cabecalho']['numero_pedido'] ?? null,
                'conta_omie' => $account,
                'criterio' => $this->marker->criterio($account),
                'cod_projeto' => $this->marker->projectCodeOf($order),
                'obs_venda' => $order['observacoes']['obs_venda'] ?? null,
            ]);

            return null;
        }

        $project = DB::transaction(function () use ($order, $omieOrderId, $account, $omie) {
            $company = $this->resolveCompany($order, $omie, $account);

            // Item tirado do Projeto à mão pelo Admin WeGift não volta com a
            // alteração do pedido: a Omie reenvia o pedido inteiro a cada
            // mudança, e sem este filtro a remoção durava até a próxima.
            // `strval`: código só com números vira chave inteira no array.
            $removidos = array_map('strval', array_keys((array) (Project::where('omie_order_id', $omieOrderId)->first()?->removed_items ?? [])));

            $items = array_values(array_filter(
                $order['det'] ?? [],
                fn (array $i) => ! in_array(mb_strtolower((string) ($i['produto']['codigo'] ?? '')), $removidos, true),
            ));
            $totalQty = (int) collect($items)->sum(fn (array $i) => $i['produto']['quantidade'] ?? 0);

            // "Nº do pedido do cliente" digitado no Pedido de Venda: é onde a
            // operação escreve COMO A CAMPANHA SE CHAMA ("Projeto Teste -
            // Template"), e é de lá que sai o nome do Projeto aqui.
            $clientOrderNumber = trim((string) ($order['informacoes_adicionais']['numero_pedido_cliente'] ?? ''));
            $numeroPedido = $order['cabecalho']['numero_pedido'] ?? "Pedido {$omieOrderId}";

            /*
             * Nome do Projeto: "<pedido> - <nome da campanha>".
             *
             * A razão social só entra quando o campo da Omie está vazio. Ela
             * era o padrão antes e não informava nada: a empresa já aparece
             * embaixo do título, e dois pedidos do mesmo cliente ficavam com
             * nomes que só diferiam no número. O número do pedido fica na
             * frente porque é por ele que a operação procura.
             */
            $atributos = [
                'company_id' => $company->id,
                'name' => trim($numeroPedido.' - '.($clientOrderNumber !== '' ? $clientOrderNumber : $company->name)),
                'omie_order_number' => $order['cabecalho']['numero_pedido'] ?? null,
                'client_order_number' => $clientOrderNumber !== '' ? $clientOrderNumber : null,
                'omie_account' => $account,
                // Tipo da campanha (Evento, Com estoque, Amostra, Sem estoque,
                // Bosch), pelo projeto em que o pedido foi classificado na
                // Omie. É ele que define o template do card no Trello.
                'template' => $this->marker->templateFor($order)?->value,
                'quantity' => $totalQty,
                'delivery_deadline' => OmieDate::parse(
                    $order['cabecalho']['data_previsao'] ?? null,
                    "pedido {$omieOrderId} · data_previsao",
                ),
                // Data em que o pedido entrou na Omie — "Data da compra" na tela.
                'ordered_at' => OmieDate::parse(
                    $order['infoCadastro']['dInc'] ?? null,
                    "pedido {$omieOrderId} · infoCadastro.dInc",
                ),
            ];

            // Nome e prazo editados à mão pelo operador não podem ser desfeitos
            // por `VendaProduto.Alterada`: sem esta porta, a primeira alteração
            // do pedido na Omie reverteria a edição que a UAT pediu.
            $existente = Project::where('omie_order_id', $omieOrderId)->first();
            if ($existente?->details_edited_at !== null) {
                unset($atributos['name'], $atributos['delivery_deadline']);
            }

            $project = Project::updateOrCreate(['omie_order_id' => $omieOrderId], $atributos);

            // Kits/produtos do pedido, espelhados da Omie (com BOM)
            $sync = [];
            foreach ($items as $item) {
                $code = $item['produto']['codigo'] ?? null;
                if ($code === null) {
                    continue;
                }

                $product = $this->mirrorProduct->handle($code, $account);
                if ($product !== null) {
                    // O mesmo código pode vir em MAIS de uma linha do pedido
                    // (item avulso repetido, preços diferentes). As quantidades
                    // SOMAM: antes a última linha vencia e `project_products`
                    // registrava menos do que o pedido pede.
                    $sync[$product->id] = [
                        'quantity' => ($sync[$product->id]['quantity'] ?? 0)
                            + (int) ($item['produto']['quantidade'] ?? 1),
                    ];
                }
            }
            $project->products()->sync($sync);

            $this->espelharPaisDaGrade($items, $account);
            $this->espelharTamanhosDaGrade($items, $account);

            return $project;
        });

        $this->createTrelloCard->handle($project);

        return $project;
    }

    /**
     * O pai da grade entra no catálogo junto com os tamanhos do pedido.
     *
     * Toda variação da Omie vem com `_N` no código (PRD11363_5), e o pedido
     * traz só os tamanhos: o pai (PRD11363) ficava fora da WeLog, que então
     * não sabia o nome do produto nem tinha onde pendurar os tamanhos. Pelo
     * prefixo do código ele é espelhado — no CATÁLOGO, não no pedido: o
     * saldo continua sendo de cada tamanho, como a Omie manda.
     *
     * Falhar aqui não derruba o Projeto: sem o pai, os tamanhos ainda se
     * agrupam pelo prefixo (Product::paisDaGrade).
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function espelharPaisDaGrade(array $items, ?string $account): void
    {
        $pais = collect($items)
            ->map(fn (array $item) => preg_match('/^(.+)_\d+$/u', (string) ($item['produto']['codigo'] ?? ''), $m) === 1 ? $m[1] : null)
            ->filter()
            ->unique()
            ->reject(fn (string $pai) => Product::whereRaw('LOWER(omie_code) = ?', [mb_strtolower($pai)])->exists());

        foreach ($pais as $pai) {
            try {
                $this->mirrorProduct->handle($pai, $account);
            } catch (Throwable $e) {
                Log::channel('integrations')->warning('Pai da grade não espelhado.', [
                    'codigo' => $pai,
                    'conta' => $account,
                    'erro' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * O caminho inverso: o pedido traz o PAI da grade (PRD11361 × 20) e os
     * tamanhos entram no catálogo junto.
     *
     * É dos tamanhos no catálogo que sai a escolha no portal
     * (Product::opcoesDeVariacao). Sem eles, quem não rodou "Importar da Omie"
     * depois de criar a grade recebia o pai sem tamanho nenhum para escolher.
     *
     * A Omie numera os tamanhos em sequência a partir de `_1`: consulta `_1`,
     * `_2`… até o primeiro que não existe. Pai que já tem tamanho no catálogo
     * não custa chamada. Produto sem grade custa uma (`_1` não existe), e essa
     * resposta fica guardada por um dia — a reconciliação repassa os pedidos
     * dos últimos dois dias a cada 15 minutos.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function espelharTamanhosDaGrade(array $items, ?string $account): void
    {
        $pais = collect($items)
            ->map(fn (array $item) => (string) ($item['produto']['codigo'] ?? ''))
            ->filter(fn (string $codigo) => $codigo !== '' && preg_match('/_\d+$/u', $codigo) !== 1)
            ->unique()
            ->reject(fn (string $pai) => $this->temTamanhosNoCatalogo($pai));

        foreach ($pais as $pai) {
            $semGrade = 'omie:sem-grade:'.($account ?? '').'|'.mb_strtolower($pai);

            if (Cache::has($semGrade)) {
                continue;
            }

            try {
                for ($n = 1; $n <= self::MAX_TAMANHOS; $n++) {
                    $codigo = "{$pai}_{$n}";
                    $tamanho = $this->mirrorProduct->handle($codigo, $account);

                    // A resposta tem de ser do código pedido: retorno genérico
                    // da Omie não pode virar um tamanho que não existe.
                    if ($tamanho === null || mb_strtolower((string) $tamanho->omie_code) !== mb_strtolower($codigo)) {
                        break;
                    }
                }

                if ($n === 1) {
                    Cache::put($semGrade, true, now()->addDay());
                }
            } catch (Throwable $e) {
                Log::channel('integrations')->warning('Tamanhos da grade não espelhados.', [
                    'codigo' => $pai,
                    'conta' => $account,
                    'erro' => $e->getMessage(),
                ]);
            }
        }
    }

    private function temTamanhosNoCatalogo(string $pai): bool
    {
        $base = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($pai));

        return Product::query()
            ->whereRaw("LOWER(omie_code) LIKE ? ESCAPE '!'", [$base.'!_%'])
            ->pluck('omie_code')
            ->contains(fn ($codigo) => preg_match('/^'.preg_quote(mb_strtolower($pai), '/').'_\d+$/u', mb_strtolower((string) $codigo)) === 1);
    }

    /**
     * O `ConsultarPedido` da Omie devolve apenas `cabecalho.codigo_cliente` —
     * CNPJ e razão social exigem uma segunda chamada. Sem ela toda empresa
     * caía no literal "desconhecido" e os Projetos nasciam sem cliente.
     */
    private function resolveCompany(array $order, OmieGateway $omie, ?string $account = null): Company
    {
        $code = $order['cabecalho']['codigo_cliente'] ?? null;

        // Alguns retornos já trazem o bloco embutido; aproveitamos e evitamos
        // uma chamada à Omie (que tem teto de consumo).
        $document = $order['cliente']['cnpj'] ?? $order['cliente']['cnpj_cpf'] ?? null;
        $name = $order['cliente']['razao_social'] ?? null;
        $cadastro = [];

        if ($document === null && $code !== null) {
            $customer = $omie->getCustomer((string) $code) ?? [];

            $document = $customer['document'] ?? null;
            $name = $customer['name'] ?? null;

            // Contato e endereço vindos do ERP: poupa o operador de redigitar
            // o que a Omie já tem, e é o que falta para expedir.
            $cadastro = array_intersect_key($customer, array_flip([
                'trade_name', 'contact_name', 'contact_email', 'contact_phone',
                'zip', 'street', 'number', 'complement', 'district', 'city', 'state',
            ]));
        }

        $cnpj = preg_replace('/\D/', '', (string) $document);

        if ($cnpj === '') {
            // Sem documento não dá para casar a empresa: cai numa chave estável
            // por código de cliente, em vez de juntar tudo num "desconhecido".
            $cnpj = $code !== null ? "omie-{$code}" : 'sem-documento';

            Log::warning('Pedido Omie sem CNPJ do cliente — Projeto criado com empresa provisória', [
                'omie_order_id' => $order['omie_order_id'] ?? null,
                'codigo_cliente' => $code,
            ]);
        }

        $cadastro = $this->normalizeCompanyData($cadastro);

        $company = Company::firstOrCreate(
            ['cnpj' => $cnpj],
            $cadastro + [
                'name' => $name ?? "Cliente {$cnpj}",
                'omie_customer_code' => $code,
                // De qual CNPJ da WeGift veio este cliente.
                'omie_account' => $account,
            ],
        );

        if ($company->wasRecentlyCreated) {
            return $company;
        }

        // Numa reimportação, atualiza só o que veio de fato E ainda está vazio
        // aqui: correção feita à mão pelo Admin WeGift não pode ser desfeita
        // por um retorno pobre da Omie.
        $updates = array_filter(
            ['name' => $name, 'omie_customer_code' => $code],
            fn ($v) => $v !== null && $v !== '',
        );

        foreach ($cadastro as $campo => $valor) {
            if (blank($company->{$campo})) {
                $updates[$campo] = $valor;
            }
        }

        if ($updates !== []) {
            $company->update($updates);
        }

        return $company;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function normalizeCompanyData(array $data): array
    {
        if (isset($data['zip'])) {
            $data['zip'] = preg_replace('/\D/', '', (string) $data['zip']);
        }

        if (isset($data['state'])) {
            $data['state'] = strtoupper(substr(trim((string) $data['state']), 0, 2));
        }

        if (isset($data['contact_email'])) {
            $data['contact_email'] = strtolower(trim((string) $data['contact_email']));
        }

        return array_filter($data, fn ($v) => filled($v));
    }
}

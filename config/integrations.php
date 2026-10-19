<?php

/*
|--------------------------------------------------------------------------
| Integrações Externas — WeLog
|--------------------------------------------------------------------------
| Cada provedor tem um "driver": 'fake' (fixtures locais, sem rede) ou
| 'api' (chamadas reais). O binding contrato → driver acontece no
| IntegrationServiceProvider. Em desenvolvimento e testes, use 'fake';
| troque para 'api' conforme as credenciais forem disponibilizadas.
*/

return [

    /*
    | Fuso do negócio (WeGift/clientes no Brasil). O app ARMAZENA em UTC — não
    | mude `config/app.php`, isso reinterpretaria todos os timestamps já
    | gravados. Este fuso é para formatar datas enviadas às APIs e agendamentos:
    | `now()` em UTC entre 21h e 0h BRT já está no dia seguinte, e os filtros
    | da Omie passariam a consultar "amanhã".
    */
    'business_timezone' => env('WELOG_BUSINESS_TIMEZONE', 'America/Sao_Paulo'),

    /*
    | Retenção das tabelas que crescem sem teto. Aplicada pelo `model:prune`
    | agendado (ver routes/console.php). Eventos de webhook com falha NUNCA são
    | podados — são a trilha de quem precisa investigar.
    */
    'retention' => [
        'webhook_events_days' => (int) env('WELOG_RETENTION_WEBHOOK_EVENTS_DAYS', 90),
        'tracking_events_days' => (int) env('WELOG_RETENTION_TRACKING_EVENTS_DAYS', 365),
    ],

    'omie' => [
        'driver' => env('OMIE_DRIVER', 'fake'),

        /*
         * Varredura automática de pedidos novos (a rede por trás do webhook).
         *
         * DESLIGADA por decisão da operação. Ela lista os pedidos dos últimos
         * dois dias a cada 15 minutos e cria Projeto para todo pedido que
         * ainda não tem — o que, com a conta atendendo a operação inteira,
         * enche a plataforma de pedido que ninguém pediu para acompanhar. Foi
         * ela também que levou a conta da Omie à suspensão por rajada.
         *
         * O que se perde: webhook que não chega (a Omie já ficou 7 dias sem
         * enviar) deixa de virar Projeto sozinho, e ninguém é avisado. Para
         * recuperar, sem esperar agendamento:
         *
         *   php artisan welog:omie:sync-projects --dias=2 --dry-run   (confere)
         *   php artisan welog:omie:sync-projects --dias=2             (cria)
         *
         * `true` religa o agendamento.
         */
        'reconcile_orders' => filter_var(env('OMIE_RECONCILE_ORDERS', false), FILTER_VALIDATE_BOOLEAN),
        'base_url' => env('OMIE_BASE_URL', 'https://app.omie.com.br/api/v1'),
        'app_key' => env('OMIE_APP_KEY'),
        'app_secret' => env('OMIE_APP_SECRET'),

        /*
         * CONTAS (CNPJs) da Omie.
         *
         * A WeGift tem mais de um CNPJ, e cada um é um aplicativo com chave
         * própria. Receber o webhook dos dois é fácil (basta a URL), mas
         * CONSULTAR o pedido exige a chave da conta CERTA: a chave do CNPJ A
         * não enxerga o pedido do CNPJ B.
         *
         *   OMIE_ACCOUNTS=matriz,filial
         *   OMIE_MATRIZ_APP_KEY=...      OMIE_MATRIZ_APP_SECRET=...
         *   OMIE_MATRIZ_WEBHOOK_TOKEN=...   (token na URL registrada nessa conta)
         *   OMIE_FILIAL_APP_KEY=...      OMIE_FILIAL_APP_SECRET=...
         *   OMIE_FILIAL_WEBHOOK_TOKEN=...
         *
         * Como cada conta registra a própria URL (/webhooks/omie/{token}), o
         * token diz de QUAL conta o evento veio — e é essa conta que o sistema
         * usa depois para consultar o pedido.
         *
         * Vazio = conta única, com OMIE_APP_KEY/OMIE_APP_SECRET/OMIE_WEBHOOK_TOKEN
         * (comportamento antigo, nada muda).
         */
        'accounts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('OMIE_ACCOUNTS', ''))
        ))),
        /*
         * Intervalo mínimo entre duas chamadas do MESMO método, em ms.
         *
         * Não é o teto por minuto: é a rajada. A Omie responde 500 e depois
         * suspende o aplicativo quando o mesmo método chega várias vezes no
         * mesmo segundo ("consumo redundante"), e foi exatamente isso que
         * derrubou a conta — uma varredura com um `ListarPedidos` por projeto,
         * seis seguidos, sem pausa.
         *
         * 2s é a folga que sobrevive a vários workers somados. Baixar isso só
         * faz sentido se a Omie liberar o consumo.
         */
        'min_interval_ms' => (int) env('OMIE_MIN_INTERVAL_MS', 2000),
        // Rate limit da Omie ≈ 240 req/min — margem de segurança
        'requests_per_minute' => (int) env('OMIE_REQUESTS_PER_MINUTE', 180),
        // Saldo relido dentro desta janela vem do cache: a reconciliação refaz
        // as mesmas ~140 consultas a cada tentativa, e repetição é o que a
        // Omie bloqueia como "consumo redundante".
        'stock_cache_seconds' => (int) env('OMIE_STOCK_CACHE_SECONDS', 300),
        // ID interno do produto → código. É imutável no cadastro da Omie, então
        // o cache é longo: evita uma consulta por componente em cada pedido que
        // repete o mesmo kit.
        'product_code_cache_seconds' => (int) env('OMIE_PRODUCT_CODE_CACHE_SECONDS', 86400),
        // Local de estoque usado na baixa por resgate (IncluirAjusteEstoque).
        // 0 = omitir o campo e deixar a Omie usar o local padrão da conta —
        // o cenário atual; contas com múltiplos locais informam o código aqui.
        'stock_location' => (int) env('OMIE_STOCK_LOCATION', 0),
        /*
         * Pedido de Venda do resgate (Fluxo B) — os valores da CONTA que o
         * IncluirPedido exige e que nenhum resgate traz.
         *
         * Copiados do pedido 65452, um resgate Suvinil feito à mão na Omie.
         * `projeto` é o codProj Suvinil: é ele que mantém o pedido fora dos
         * Projetos WeLog (Fluxo A), que só aceitam OMIE_PROJECT_CODES.
         */
        'pedido' => [
            'categoria' => env('OMIE_PEDIDO_CATEGORIA', '1.01.02'),
            'conta_corrente' => (int) env('OMIE_PEDIDO_CONTA_CORRENTE', 7310887094),
            'cenario_impostos' => env('OMIE_PEDIDO_CENARIO_IMPOSTOS', '7310891385'),
            'projeto' => (int) env('OMIE_PEDIDO_PROJETO', 7386090746),
            'parcela' => env('OMIE_PEDIDO_PARCELA', '000'),
        ],
        /*
         * Marcação que identifica um Pedido de Venda como Projeto WeLog.
         *
         * A conta da Omie atende a operação inteira da Santa Origem — 532
         * pedidos só nos últimos 5 dias — e o que separa um Projeto WeLog de
         * um pedido comum é o texto escrito no campo de observação do pedido
         * (`observacoes.obs_venda`).
         *
         * Aceita vários termos separados por vírgula; a comparação ignora
         * caixa e acento. VAZIO = sem filtro (todo pedido vira Projeto, que é
         * o comportamento antigo) — a pendência aparece no
         * `welog:projects:audit`.
         *
         *   OMIE_PROJECT_MARKER="projeto welog,kit onboarding wegift"
         */
        'project_marker' => env('OMIE_PROJECT_MARKER', ''),

        /*
         * Códigos do cadastro de PROJETOS da Omie que viram Projeto WeLog.
         *
         * Critério PREFERENCIAL, e quando preenchido substitui inteiramente o
         * `project_marker` acima: a Santa Origem já classifica cada pedido num
         * Projeto da Omie, e campo escolhido numa lista não erra por acento,
         * por caixa nem por alguém esquecer de digitar a observação.
         *
         * Rode `php artisan welog:omie:projetos` para ver os códigos da conta.
         * Hoje são dois:
         *   7407717220  Venda     ← os pedidos que viram Projeto WeLog
         *   7386090746  Suvinil   ← Fluxo B, não gera Projeto
         *
         * Aceita vários separados por vírgula. VAZIO = cai no `project_marker`;
         * os dois vazios = sem filtro nenhum (comportamento antigo).
         *
         *   OMIE_PROJECT_CODES="7407717220"
         */
        'project_codes' => env('OMIE_PROJECT_CODES', ''),

        /*
         * De-para projeto da Omie → template do card no Trello, no formato
         * `codigo=template`, por vírgula. Os templates são os do quadro da
         * operação: evento | com_estoque | amostra | sem_estoque |
         * bosch_sem_estoque (ver `App\Support\Projects\ProjectTemplate`).
         *
         * Código mapeado aqui é ACEITO automaticamente como Projeto WeLog —
         * configurar em dois lugares seria a chance de aceitar um pedido sem
         * saber que card criar, ou de ter template para um projeto que nem
         * entra. Quem não usa template segue só com `project_codes`.
         *
         *   OMIE_PROJECT_TEMPLATES="7828126392=evento,7828126432=com_estoque"
         */
        'project_templates' => env('OMIE_PROJECT_TEMPLATES', ''),

        // Fim do bloco Omie; a varredura de diretórios do Fluxo B fica em `appnoz`.
        // Token de verificação dos webhooks recebidos da Omie (pedido de venda
        // incluído/alterado, movimentação de estoque). A mesma URL registrada
        // na Omie carrega este token no path: /webhooks/omie/{token}
        'webhook_token' => env('OMIE_WEBHOOK_TOKEN'),
    ],

    'trello' => [
        'driver' => env('TRELLO_DRIVER', 'fake'),
        'base_url' => env('TRELLO_BASE_URL', 'https://api.trello.com/1'),
        'key' => env('TRELLO_API_KEY'),
        'token' => env('TRELLO_API_TOKEN'),
        // Secret usado na verificação HMAC-SHA1 dos webhooks
        'secret' => env('TRELLO_API_SECRET'),
        'board_id' => env('TRELLO_BOARD_ID'),
        // URL pública que o Trello chama nos webhooks (ngrok em dev)
        'webhook_callback_url' => env('TRELLO_WEBHOOK_CALLBACK_URL'),
        // Etiqueta de estoque no card — a convenção amarela do quadro da
        // operação. A plataforma cria a etiqueta no board se não existir.
        'stock_label_name' => env('TRELLO_STOCK_LABEL_NAME', 'Em estoque'),
        'stock_label_color' => env('TRELLO_STOCK_LABEL_COLOR', 'yellow'),

        /*
         * Card MODELO de cada tipo de campanha, no formato `template=card`.
         * O card é o código curto da URL (trello.com/c/<ESTE>/...).
         *
         * Com o modelo, o card novo nasce como CÓPIA dele: etiquetas (e com
         * elas a cor), checklists, campos personalizados, anexos e capa. Era a
         * parte que a plataforma não tinha como reproduzir sozinha — dava para
         * pintar uma etiqueta, não para trazer o checklist de produção que a
         * fábrica usa.
         *
         * Sem modelo configurado, o card é criado do zero com a etiqueta do
         * tipo, como antes.
         *
         *   TRELLO_CARD_TEMPLATES="evento=33wyYj44,com_estoque=Zmz776Yy"
         */
        'card_templates' => env('TRELLO_CARD_TEMPLATES', ''),
    ],

    /*
    | Flash — rastreio pela API compatível Flash Courier (hoje servida pela
    | Bridge Link; ver docs/INTEGRACOES.md). Mesmos login, senha, clienteId e
    | cttId da conta Flash.
    |
    | `key` é o header Authorization do serviço de token: HMAC-SHA256 da senha
    | com o login como chave. Vazio = calculado a partir de login e senha.
    |
    | `tracking_reference`: qual dado da linha vai como `numEncCli` — tem de
    | ser o MESMO formato parametrizado no cadastro com a Bridge Link/Flash:
    |   nf_number          prefixo + nº da NF sem zeros (WEBOX44883) ← o combinado
    |   nf_key             chave da NF, 44 dígitos
    |   omie_order_number  número do pedido na Omie
    |
    | `tracking_prefix`: o prefixo do modo `nf_number`.
    */
    'flash' => [
        'driver' => env('FLASH_DRIVER', 'fake'),
        'base_url' => env('FLASH_BASE_URL', 'https://bridgelink.com.br'),
        'login' => env('FLASH_LOGIN'),
        'senha' => env('FLASH_SENHA'),
        'key' => env('FLASH_KEY', env('FLASH_API_KEY')),
        'cliente_id' => env('FLASH_CLIENTE_ID'),
        'ctt_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('FLASH_CTT_IDS', ''))))),
        'tracking_reference' => env('FLASH_TRACKING_REFERENCE', 'nf_number'),
        'tracking_prefix' => env('FLASH_TRACKING_PREFIX', 'WEBOX'),

        /*
         * Importação de encomendas (POST /FlashPegasus/rest/padrao/importacao,
         * HTTP Basic): o resgate com NF emitida vira encomenda na Bridge
         * Link, com o mesmo número que o rastreio consulta (WEBOX + nº da NF).
         *
         * Usuário e senha vazios = os do rastreio. `ccusto_id` só importa se
         * o contrato tiver mais de um centro de custo. `enabled` false desliga
         * o envio automático (o botão "Enviar à Flash" continua).
         */
        'import' => [
            'enabled' => filter_var(env('FLASH_IMPORT_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'usuario' => env('FLASH_IMPORT_USUARIO'),
            'senha' => env('FLASH_IMPORT_SENHA'),
            'ccusto_id' => env('FLASH_IMPORT_CCUSTO_ID'),
            'remetente' => env('FLASH_IMPORT_REMETENTE', 'WeGift'),

            /*
             * Campos do contrato que o exemplo de importação da Bridge Link
             * manda em toda encomenda. A documentação os dá como opcionais,
             * mas a importação seguia em HTTP 500 sem eles. Os valores são os
             * da conta da WeGift (pedir ao suporte); vazio não vai.
             */
            'contrato' => array_filter([
                'tipo_enc_id' => env('FLASH_IMPORT_TIPO_ENC_ID'),
                'prod_flash_id' => env('FLASH_IMPORT_PROD_FLASH_ID'),
                'frq_rec_id' => env('FLASH_IMPORT_FRQ_REC_ID'),
                'id_local_rem' => env('FLASH_IMPORT_ID_LOCAL_REM'),
                'num_cliente' => env('FLASH_IMPORT_NUM_CLIENTE'),
            ], fn ($valor) => $valor !== null && $valor !== ''),
        ],
    ],

    /*
    | NF simulada (Fluxo B) — `php artisan welog:nf:simular`.
    |
    | Marca pedidos como faturados com uma chave de NF fictícia, para testar o
    | ciclo NF → rastreio sem emitir nota de verdade. Desligado por padrão: só
    | o .env de homologação liga. A Flash real não conhece a nota simulada (a
    | API é só de consulta): o rastreio dela só avança com FLASH_DRIVER=fake.
    */
    'simulated_invoices' => (bool) env('WELOG_SIMULATED_INVOICES', false),

    /*
    | Consulta de endereço por CEP. Provedor público e sem credencial; o
    | resultado é cacheado por 30 dias (CEP praticamente não muda), então o
    | volume de chamadas externas é baixo.
    */
    'cep' => [
        'driver' => env('CEP_DRIVER', 'api'),
        'base_url' => env('CEP_BASE_URL', 'https://viacep.com.br'),
    ],

    'correios' => [
        'driver' => env('CORREIOS_DRIVER', 'fake'),
        'base_url' => env('CORREIOS_BASE_URL', 'https://api.correios.com.br'),
        'usuario' => env('CORREIOS_USUARIO'),
        'codigo_acesso' => env('CORREIOS_CODIGO_ACESSO'),
        'contrato' => env('CORREIOS_CONTRATO'),
        // Prepostagem (PPN) depende do contrato do cliente — desligada por padrão
        'prepostagem_enabled' => (bool) env('CORREIOS_PREPOSTAGEM_ENABLED', false),
        // Serviço contratado usado na prepostagem. Padrão 03298 = PAC Contrato;
        // varia por contrato (ex.: 03220 SEDEX Contrato) — confirmar no CWS.
        'codigo_servico' => env('CORREIOS_CODIGO_SERVICO', '03298'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fluxo B — varredura dos diretórios de relatórios appnoz
    |--------------------------------------------------------------------------
    | O operador WeGift gera o relatório na appnoz e salva "em um diretório
    | específico previamente definido" (Anexo I, item 36). Os diretórios são
    | parametrizados aqui porque "previamente definido" é acordo entre pessoas,
    | não constante de código: um cliente novo, uma pasta por linha de produto
    | ou um caminho que muda de nome não podem exigir deploy.
    |
    | Caminhos são relativos ao disco `local` (storage/app/private).
    */
    'appnoz' => [
        // Lista separada por vírgula: APPNOZ_INBOX_DIRS="appnoz-inbox,suvinil-inbox"
        'inbox_directories' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('APPNOZ_INBOX_DIRS', 'appnoz-inbox')),
        ))),

        // Para onde vai o arquivo já lido. Único de propósito: o histórico do
        // que entrou não deve depender de qual pasta o operador escolheu.
        'processed_directory' => env('APPNOZ_PROCESSED_DIR', 'appnoz-processed'),

        // Uma leitura por dia (cronograma S5). O relatório é gerado à mão pelo
        // operador, num ritmo de horas — varrer de dez em dez minutos era
        // custo sem ganho.
        'scan_time' => env('APPNOZ_SCAN_TIME', '07:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Suvinil — webhook de resgates (Importações Parceiros)
    |--------------------------------------------------------------------------
    | Segunda porta de entrada do mesmo material que hoje chega por diretório:
    | em vez de o operador exportar o relatório e salvar na pasta, a Suvinil
    | chama a nossa URL quando o resgate acontece do lado dela.
    |
    | O token vai NA URL, como no Omie, porque o parceiro não assina o corpo.
    | É a credencial inteira: quem tem a URL, entra. Trate-a como senha, gere
    | com `php artisan tinker --execute="echo bin2hex(random_bytes(24));"` e
    | NUNCA a deixe cair em e-mail, ticket ou print.
    |
    | Vazio = webhook desligado. Isso é proposital: sem token configurado a
    | rota recusa tudo, em vez de virar uma porta aberta por esquecimento.
    */
    'suvinil' => [
        'webhook_token' => (string) env('SUVINIL_WEBHOOK_TOKEN', ''),

        // Teto por chamada. O webhook é síncrono do lado deles: um lote de mil
        // linhas estoura o timeout do parceiro e ele reenvia o mesmo lote,
        // inteiro, para sempre. Acima disso a resposta é 422 explicando.
        'max_rows' => (int) env('SUVINIL_WEBHOOK_MAX_ROWS', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconhece — webhook de cadastro de produto
    |--------------------------------------------------------------------------
    | O par do webhook da Suvinil, na direção oposta: por lá entram os resgates,
    | por aqui entra o catálogo que dá sentido a eles. O resgate chega com o
    | código do produto da Reconhece, e é este cadastro que diz qual kit da Omie
    | expedir.
    |
    | O token é a credencial inteira: vai no header (X-API-Key ou
    | Authorization), como a Reconhece pediu, ou na URL, como o da Suvinil.
    | Vazio significa webhook desligado — a rota recusa tudo.
    */
    'reconhece' => [
        'driver' => env('RECONHECE_DRIVER', 'fake'),
        'base_url' => env('RECONHECE_BASE_URL', ''),
        'api_key' => env('RECONHECE_API_KEY', ''),

        'webhook_token' => (string) env('RECONHECE_WEBHOOK_TOKEN', ''),

        // Teto por chamada. Mais alto que o de resgates porque cadastro é só
        // gravação, sem chamada externa no meio.
        'max_rows' => (int) env('RECONHECE_WEBHOOK_MAX_ROWS', 500),
    ],

];

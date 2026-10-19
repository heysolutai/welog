<?php

namespace App\Models;

use App\Support\SupportContact;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de e-mail parametrizável (Módulo 11).
 *
 * Cada chave é uma das comunicações automatizadas, e cada uma existe em até
 * três ALCANCES, do mais geral ao mais específico:
 *
 *   padrão   `company_id` e `project_id` nulos — a voz da WeGift, vale para todos
 *   empresa  `company_id` — a voz do cliente, em todos os Projetos dele
 *   projeto  `company_id` + `project_id` — a voz de UMA campanha
 *
 * O envio usa o mais específico que existir, e os níveis convivem em vez de se
 * substituírem: apagar o de baixo devolve o de cima, intacto. Por isso
 * "restaurar" é apagar, e não copiar o texto de cima — texto copiado congela
 * no dia da cópia e deixa de acompanhar as melhorias do nível acima.
 *
 * Cada modelo também declara o PÚBLICO a quem se dirige. Não é uma escolha do
 * operador, é um fato do evento: o aviso de etapa fala com quem administra o
 * cliente, o de presente liberado fala com quem ganha o presente. A tela usa
 * isso para não misturar as duas vozes na mesma lista.
 */
class EmailTemplate extends Model
{
    protected $fillable = ['key', 'name', 'subject', 'title', 'body', 'company_id', 'project_id'];

    public const KEY_PROJECT_RELEASED = 'project_released';

    public const KEY_REDEMPTION_CONFIRMED = 'redemption_confirmed';

    public const KEY_REDEMPTION_SHIPPED = 'redemption_shipped';

    public const KEY_REDEMPTION_DELIVERED = 'redemption_delivered';

    /**
     * Eventos de rastreio que não são nem a expedição nem a entrega. Antes
     * servia aos dois; hoje cada um tem modelo próprio, e este fica para o
     * que o robô dos Correios trouxer no meio do caminho.
     */
    public const KEY_SHIPMENT_UPDATE = 'shipment_update';

    public const KEY_STAGE_CHANGED = 'stage_changed';

    /**
     * A jornada do resgate, na ordem em que a pessoa vive: as mensagens que o
     * Estúdio de mensagens edita. `quando` diz na tela em que momento sai.
     */
    public const JORNADA = [
        self::KEY_PROJECT_RELEASED => ['etapa' => 'Presente liberado', 'quando' => 'Quando o resgate é aberto para a pessoa'],
        self::KEY_REDEMPTION_CONFIRMED => ['etapa' => 'Resgate confirmado', 'quando' => 'Quando a pessoa confirma o endereço'],
        self::KEY_REDEMPTION_SHIPPED => ['etapa' => 'Pedido expedido', 'quando' => 'Quando o presente sai para entrega'],
        self::KEY_REDEMPTION_DELIVERED => ['etapa' => 'Pedido entregue', 'quando' => 'Quando o presente chega'],
    ];

    /**
     * As variáveis que quem escreve usa, com nome em português: `{nome}`,
     * `{produto}`... `exemplo` é o que a pré-visualização mostra no lugar.
     *
     * Os modelos antigos usavam `{{ userName }}`: o envio continua entendendo,
     * e o `render` monta os dois vocabulários a partir dos mesmos dados.
     */
    public const VARIAVEIS = [
        'nome' => ['label' => 'Nome', 'exemplo' => 'Maria Souza'],
        'primeiro_nome' => ['label' => 'Primeiro nome', 'exemplo' => 'Maria'],
        'produto' => ['label' => 'Presente', 'exemplo' => 'Kit Comemorativo'],
        'empresa' => ['label' => 'Empresa', 'exemplo' => 'Sua empresa'],
        'endereco' => ['label' => 'Endereço de entrega', 'exemplo' => 'Av. Paulista, 1000 · São Paulo/SP'],
        'resumo_do_pedido' => ['label' => 'Resumo do pedido', 'exemplo' => null],
        'codigo_rastreio' => ['label' => 'Código de rastreio', 'exemplo' => 'BR123456789BR'],
        'transportadora' => ['label' => 'Transportadora', 'exemplo' => 'Correios'],
        'link_rastreio' => ['label' => 'Link de rastreio', 'exemplo' => 'https://rastreio.correios.com.br'],
        'data' => ['label' => 'Data', 'exemplo' => null],
        'hora' => ['label' => 'Horário', 'exemplo' => null],
        'contato' => ['label' => 'Contato de atendimento', 'exemplo' => null],
    ];

    /**
     * Onde o botão do e-mail fica dentro da mensagem. Não é variável: é uma
     * posição. O que vem depois dele (um agradecimento, a assinatura) sai
     * embaixo do botão. Mensagem sem o marcador sai sem botão.
     */
    public const BOTAO = '{botao}';

    /** O fim de toda mensagem padrão: o botão e a assinatura, ambos editáveis. */
    public const FECHO = "\n\n{botao}\n\nAbraços,  \nWeLog";

    /** Vocabulário antigo → novo: `{{ userName }}` e `{nome}` saem dos mesmos dados. */
    private const APELIDOS = [
        'userName' => 'nome',
        'productName' => 'produto',
        'companyName' => 'empresa',
        'address' => 'endereco',
        'trackingCode' => 'codigo_rastreio',
        'carrier' => 'transportadora',
        'trackingUrl' => 'link_rastreio',
    ];

    /** Quem recebe: o Colaborador que ganha o presente. */
    public const AUDIENCE_COLLABORATOR = 'collaborator';

    /** Quem recebe: quem administra o cliente (Admin Cliente / Admin Projeto). */
    public const AUDIENCE_CLIENT_ADMIN = 'client_admin';

    public const AUDIENCE_LABELS = [
        self::AUDIENCE_COLLABORATOR => 'Colaborador',
        self::AUDIENCE_CLIENT_ADMIN => 'Admin Cliente',
    ];

    /** Alcance desta linha, para a tela dizer de onde o texto vem. */
    public function escopo(): string
    {
        return match (true) {
            $this->project_id !== null => 'projeto',
            $this->company_id !== null => 'empresa',
            default => 'padrao',
        };
    }

    /**
     * Definições (nome, variáveis disponíveis e conteúdo padrão) de cada modelo.
     * Fonte da verdade para o seed e para o "restaurar padrão".
     */
    public static function definitions(): array
    {
        return [
            self::KEY_PROJECT_RELEASED => [
                'name' => 'Presente liberado para resgate',
                'audience' => self::AUDIENCE_COLLABORATOR,
                'variables' => [
                    'userName' => 'Nome do Usuário',
                    'productName' => 'Nome do kit/produto',
                    'companyName' => 'Nome da Empresa Cliente',
                    'contato' => 'Contato de suporte da WeGift (e-mail/WhatsApp configurado)',
                ],
                'variaveis' => ['nome', 'primeiro_nome', 'produto', 'empresa', 'data', 'hora', 'contato'],
                'subject' => 'Seu presente está disponível para resgate!',
                'body' => <<<'MD'
                    # Olá, {{ userName }}!

                    A **{{ companyName }}** preparou um presente para você:

                    **{{ productName }}**

                    Seu resgate já está liberado. Acesse a plataforma WeLog, confirme seu endereço de entrega e finalize o resgate.

                    {botao}

                    Abraços,  
                    WeLog
                    MD,
            ],
            self::KEY_REDEMPTION_CONFIRMED => [
                'name' => 'Confirmação de resgate',
                'audience' => self::AUDIENCE_COLLABORATOR,
                'variables' => [
                    'userName' => 'Nome do Usuário',
                    'productName' => 'Nome do kit/produto',
                    'address' => 'Endereço de entrega informado',
                    'contato' => 'Contato de suporte da WeGift (e-mail/WhatsApp configurado)',
                ],
                'variaveis' => [
                    'nome', 'primeiro_nome', 'produto', 'empresa', 'endereco', 'resumo_do_pedido', 'data', 'hora', 'contato',
                ],
                'subject' => 'Resgate confirmado: acompanhe seu pedido',
                'body' => <<<'MD'
                    # Resgate confirmado!

                    Olá, {{ userName }}! Recebemos a confirmação do seu resgate:

                    **{{ productName }}**

                    Entrega em: {{ address }}

                    Assim que o pedido for expedido, você receberá o código de rastreio por aqui.

                    Precisa corrigir alguma informação? Fale com a gente: {{ contato }}

                    {botao}

                    Abraços,  
                    WeLog
                    MD,
            ],
            self::KEY_REDEMPTION_SHIPPED => [
                'name' => 'Pedido expedido',
                'audience' => self::AUDIENCE_COLLABORATOR,
                // Para a tela de Modelos de E-mail, que lista `{{ nome }}`: o
                // envio entende os dois formatos.
                'variables' => self::rotulos([
                    'nome', 'produto', 'codigo_rastreio', 'transportadora', 'link_rastreio', 'data', 'hora', 'contato',
                ]),
                'variaveis' => [
                    'nome', 'primeiro_nome', 'produto', 'empresa', 'endereco',
                    'codigo_rastreio', 'transportadora', 'link_rastreio', 'data', 'hora', 'contato',
                ],
                'subject' => 'Seu presente está a caminho!',
                'title' => 'Seu pedido foi expedido',
                'body' => <<<'MD'
                    Olá, {nome}!

                    Seu presente **{produto}** já saiu para entrega pela {transportadora}.

                    Código de rastreio: **{codigo_rastreio}**

                    Precisa de ajuda? Fale com a gente: {contato}

                    {botao}

                    Abraços,  
                    WeLog
                    MD,
            ],
            self::KEY_REDEMPTION_DELIVERED => [
                'name' => 'Pedido entregue',
                'audience' => self::AUDIENCE_COLLABORATOR,
                'variables' => self::rotulos([
                    'nome', 'produto', 'codigo_rastreio', 'transportadora', 'link_rastreio', 'data', 'hora', 'contato',
                ]),
                'variaveis' => [
                    'nome', 'primeiro_nome', 'produto', 'empresa', 'endereco',
                    'codigo_rastreio', 'transportadora', 'link_rastreio', 'data', 'hora', 'contato',
                ],
                'subject' => 'Seu presente chegou!',
                'title' => 'Pedido entregue',
                'body' => <<<'MD'
                    Olá, {nome}!

                    Seu presente **{produto}** foi entregue. Esperamos que aproveite!

                    Precisa de ajuda? Fale com a gente: {contato}

                    {botao}

                    Abraços,  
                    WeLog
                    MD,
            ],
            self::KEY_SHIPMENT_UPDATE => [
                'name' => 'Atualização de status do pedido',
                'audience' => self::AUDIENCE_COLLABORATOR,
                'variables' => [
                    'userName' => 'Nome do Usuário',
                    'eventTitle' => 'Título do evento (ex.: expedido, entregue)',
                    'eventDescription' => 'Descrição do evento',
                    'trackingCode' => 'Código de rastreio (vazio nas transportadoras que informam link)',
                    'trackingUrl' => 'Link de acompanhamento',
                    'rastreio' => 'Código e/ou link, conforme a transportadora informa',
                    'carrier' => 'Transportadora',
                    'contato' => 'Contato de suporte da WeGift (e-mail/WhatsApp configurado)',
                ],
                'subject' => '{{ eventTitle }}',
                'body' => <<<'MD'
                    # {{ eventTitle }}

                    Olá, {{ userName }}!

                    {{ eventDescription }}

                    Rastreio: **{{ rastreio }}** ({{ carrier }})

                    Precisa corrigir alguma informação? Fale com a gente: {{ contato }}

                    {botao}

                    Abraços,  
                    WeLog
                    MD,
            ],
            self::KEY_STAGE_CHANGED => [
                'name' => 'Mudança de etapa do Projeto',
                'audience' => self::AUDIENCE_CLIENT_ADMIN,
                'variables' => [
                    'projeto' => 'Nome do Projeto',
                    'etapa' => 'Nova etapa da esteira',
                    'empresa' => 'Nome da Empresa Cliente',
                    'pedido' => 'Número do pedido na Omie',
                    'contato' => 'Contato de suporte da WeGift (e-mail/WhatsApp configurado)',
                ],
                'subject' => 'Projeto {{ projeto }}: nova etapa {{ etapa }}',
                'body' => <<<'MD'
                    # O Projeto mudou de etapa

                    Olá! O Projeto **{{ projeto }}**, da **{{ empresa }}**, acaba de entrar na etapa **{{ etapa }}**.

                    Pedido: {{ pedido }}

                    Acompanhe os detalhes na plataforma WeLog.

                    {botao}

                    Abraços,  
                    WeLog
                    MD,
            ],
        ];
    }

    /**
     * Renderiza assunto e corpo do modelo, substituindo os placeholders
     * {{ variavel }} pelos valores. Usa o registro do banco; se ausente,
     * cai no conteúdo padrão da definição.
     *
     * @param  int|null  $companyId  empresa dona da comunicação
     * @param  int|null  $projectId  campanha, quando o envio pertence a uma.
     *                               Vence a empresa, que vence o padrão.
     * @return array{subject: string, body: string}
     */
    public static function render(string $key, array $data, ?int $companyId = null, ?int $projectId = null): array
    {
        $template = static::resolve($key, $companyId, $projectId);
        $definition = static::definitions()[$key] ?? null;

        return static::compor(
            subject: $template->subject ?? $definition['subject'] ?? '',
            // Linha com `title` nulo é do tempo em que o título era a primeira
            // linha "# ..." do corpo: o corpo já o traz.
            title: $template !== null ? $template->title : ($definition['title'] ?? null),
            body: $template->body ?? $definition['body'] ?? '',
            data: static::dados($data, $companyId),
        );
    }

    /**
     * Monta o e-mail a partir de um texto qualquer — o gravado, ou o que está
     * na tela do Estúdio para o "Enviar teste para mim".
     *
     * @return array{subject: string, body: string}
     */
    public static function compor(string $subject, ?string $title, string $body, array $data): array
    {
        $body = filled($title) ? "# {$title}\n\n{$body}" : $body;

        return [
            // Assunto é texto puro no cabeçalho da mensagem: escapar deixaria
            // "&amp;" visível na caixa de entrada.
            'subject' => static::substitute($subject, $data, escape: false),
            // Corpo é interpolado em {!! !!} e renderizado como Markdown. O
            // texto do modelo é de quem administra (confiável), mas os VALORES
            // vêm de nome/endereço do cliente e da descrição dos Correios — sem
            // escape, viram vetor de injeção de HTML (phishing).
            'body' => static::substitute($body, $data, escape: true),
        ];
    }

    /**
     * Os dados do envio nos dois vocabulários, mais as variáveis globais.
     *
     * - `{{ contato }}` / `{contato}`: o e-mail de atendimento do Cliente,
     *   quando ele tem um ligado no portal; senão o contato da WeGift, que sem
     *   configuração degrada para "responda este e-mail" (nunca "null").
     * - `{data}` e `{hora}`: o momento do envio, no fuso de Brasília.
     * - `{primeiro_nome}` e `{resumo_do_pedido}` saem dos dados que já vieram.
     *
     * Valor explícito em `$data` sempre prevalece.
     */
    public static function dados(array $data, ?int $companyId = null): array
    {
        $atendimento = $companyId !== null ? Company::find($companyId)?->emailDeAtendimento() : null;
        $agora = now()->timezone('America/Sao_Paulo');

        $data += ['contato' => $atendimento ?? SupportContact::line()];

        foreach (self::APELIDOS as $antigo => $novo) {
            if (array_key_exists($antigo, $data)) {
                $data += [$novo => $data[$antigo]];
            }
        }

        $data += [
            'data' => $agora->format('d/m/Y'),
            'hora' => $agora->format('H:i'),
            'primeiro_nome' => strtok(trim((string) ($data['nome'] ?? '')), ' ') ?: '',
        ];

        if (! array_key_exists('resumo_do_pedido', $data) && filled($data['produto'] ?? null)) {
            $data['resumo_do_pedido'] = '**'.$data['produto'].'**'
                .(filled($data['endereco'] ?? null) ? "  \nEntrega em: ".$data['endereco'] : '');
        }

        return $data;
    }

    /**
     * O modelo que vale para este envio: o mais específico que existir.
     *
     * Projeto vence empresa, que vence o padrão. Uma consulta só, ordenada do
     * mais específico para o mais geral: três idas ao banco para responder uma
     * pergunta que cabe numa, e esta roda a cada e-mail enviado.
     */
    public static function resolve(string $key, ?int $companyId = null, ?int $projectId = null): ?self
    {
        return static::query()
            ->where('key', $key)
            ->where(function ($q) use ($companyId, $projectId) {
                // O padrão entra sempre: é o piso de qualquer envio.
                $q->where(fn ($p) => $p->whereNull('company_id')->whereNull('project_id'));

                if ($companyId !== null) {
                    $q->orWhere(fn ($p) => $p->where('company_id', $companyId)->whereNull('project_id'));
                }

                if ($projectId !== null) {
                    $q->orWhere(fn ($p) => $p->where('project_id', $projectId));
                }
            })
            // Quem tem Projeto vem primeiro; depois quem tem empresa; por
            // último o padrão, que não tem nenhum dos dois.
            ->orderByRaw('CASE WHEN project_id IS NOT NULL THEN 0 WHEN company_id IS NOT NULL THEN 1 ELSE 2 END')
            ->first();
    }

    /**
     * O corpo dividido no botão: o que vem antes, o que vem depois e se o
     * botão existe. O marcador sozinho no parágrafo é o caso comum; no meio
     * de uma linha ele também vale, para o botão nunca virar texto à vista.
     *
     * @return array{antes: string, depois: string, comBotao: bool}
     */
    public static function partesDoCorpo(string $body): array
    {
        $partes = explode(self::BOTAO, $body, 2);

        return [
            'antes' => rtrim($partes[0]),
            // Um botão só: um segundo marcador perdido não vira texto.
            'depois' => ltrim(str_replace(self::BOTAO, '', $partes[1] ?? '')),
            'comBotao' => count($partes) === 2,
        ];
    }

    /**
     * Ordem de exibição das telas de modelos: a jornada do resgate, depois o
     * resto. Por id, as chaves que nasceram depois (expedido, entregue)
     * apareciam antes de "Presente liberado".
     */
    public static function ordem(string $key): int
    {
        $posicao = array_search($key, array_keys(static::definitions()), true);

        return $posicao === false ? PHP_INT_MAX : $posicao;
    }

    /** @return array<string, string> token => rótulo, a partir de VARIAVEIS */
    private static function rotulos(array $tokens): array
    {
        return array_combine($tokens, array_map(fn (string $t) => self::VARIAVEIS[$t]['label'], $tokens));
    }

    /**
     * O texto com as variáveis no vocabulário do Estúdio: `{{ userName }}`
     * vira `{nome}`. É como o texto antigo aparece para quem vai editar — sem
     * isso, o cliente veria código no meio da própria mensagem.
     */
    public static function amigavel(string $texto): string
    {
        return preg_replace_callback(
            '/\{\{\s*(\w+)\s*\}\}/',
            fn (array $m) => '{'.(self::APELIDOS[$m[1]] ?? $m[1]).'}',
            $texto,
        );
    }

    /**
     * Troca as variáveis pelos valores. Dois formatos:
     *
     * - `{{ userName }}`, o antigo (tolerante a espaços): sem valor, some.
     * - `{nome}`, o do Estúdio: chave desconhecida fica como está — chave
     *   entre chaves pode ser texto de verdade, e apagar o que a pessoa
     *   escreveu seria pior que deixar à vista.
     *
     * O escape é de HTML: o negrito e a quebra de linha do resumo do pedido
     * (Markdown) passam intactos.
     */
    private static function substitute(string $text, array $data, bool $escape): string
    {
        $valor = function (string $chave) use ($data, $escape): string {
            $value = (string) ($data[$chave] ?? '');

            return $escape ? e($value) : $value;
        };

        $text = preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', fn (array $m) => $valor($m[1]), $text);

        return preg_replace_callback(
            '/(?<!\{)\{([a-z_]+)\}(?!\})/',
            fn (array $m) => array_key_exists($m[1], $data) ? $valor($m[1]) : $m[0],
            $text,
        );
    }
}

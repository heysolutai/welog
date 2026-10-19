<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Empresa Cliente. Sobre os dois nomes:
 *
 * - `name` é a razão social espelhada da Omie — a sincronização do pedido
 *   SOBRESCREVE o campo a cada reimportação, então edição manual aqui não dura.
 * - `display_name` é o nome de exibição definido pela WeGift (tela de
 *   Empresas), usado no que o Usuário vê (e-mails, portal). A sincronização
 *   com a Omie NUNCA escreve nele.
 */
class Company extends Model
{
    protected $fillable = [
        'name', 'display_name', 'trade_name', 'cnpj', 'omie_customer_code', 'omie_account',
        'contact_name', 'contact_email', 'contact_phone',
        'zip', 'street', 'number', 'complement', 'district', 'city', 'state',
        'notes', 'customizations', 'logo_path',
    ];

    /**
     * Customizações disponíveis, com o padrão de cada uma.
     *
     * A chave é o contrato entre o banco, a tela e quem consome a feature.
     * Feature nova entra AQUI e aparece sozinha no modal de Customizações —
     * é o que evita que cada ajuste pedido vire uma migração e três telas.
     *
     * Padrão `false`: uma feature não pode começar ligada em todo Cliente que
     * já existe só porque foi criada hoje.
     */
    public const CUSTOMIZATIONS = [
        /*
         * A chave guarda o nome de quando só existia a logo do Projeto; hoje
         * ela liga a marca nos dois níveis (ver LOGO_NIVEIS). Renomear custaria
         * migrar o JSON de todo Cliente para nada mudar na tela.
         */
        'project_logo' => [
            'label' => 'Logo',
            'description' => 'Marca do Cliente e das campanhas nas telas.',
            'description_off' => 'Só a marca WeLog nas telas.',
            'icon' => 'image',
            'default' => false,
        ],

        /*
         * A ÚNICA que nasce ligada, e a exceção é deliberada.
         *
         * O padrão `false` das demais existe para que feature nova não apareça
         * sozinha em Cliente antigo. Aqui ele inverteria o sentido: desligado
         * significa "a WeLog não escreve para o colaborador", e um padrão
         * `false` calaria a plataforma inteira, para todos os Clientes, no
         * mesmo instante em que este código subisse.
         *
         * `description_off` é o texto do estado DESLIGADO: a tela troca conforme
         * a chave, para dizer o que está acontecendo agora. O nome não troca —
         * a chave ao lado já diz se está ligada.
         */
        'welog_sends_emails' => [
            'label' => 'E-mails de comunicação de resgate',
            'description' => 'A WeLog envia convite, liberação e avisos de entrega.',
            'description_off' => 'O Cliente avisa o pessoal pelo link de primeiro acesso.',
            'icon' => 'emails',
            'default' => true,
        ],

        /*
         * Desligada, o Cliente coleta todos os campos. Ligada, vale a seleção
         * de IMPORT_COLUMNS — na planilha de importação e no resgate (CPF
         * desmarcado não é pedido no portal).
         */
        'import_fields' => [
            'label' => 'Definir campos para resgate',
            'description' => 'O Cliente coleta só os campos marcados.',
            'description_off' => 'O Cliente coleta todos os campos.',
            'icon' => 'fields',
            'default' => false,
        ],

        /*
         * O e-mail de atendimento do Cliente no portal do Colaborador: uma
         * faixa discreta no topo de todas as telas, e o mesmo endereço no
         * "Precisa corrigir alguma informação?". Desligada, vale o contato
         * padrão da WeGift (config).
         */
        'portal_support' => [
            'label' => 'Atendimento no portal',
            'description' => 'O portal mostra o e-mail de atendimento deste Cliente.',
            'description_off' => 'O portal mostra o contato padrão da WeGift.',
            'icon' => 'support',
            'default' => false,
        ],

        /*
         * O endereço (/primeiro-acesso/sotreq) e o aviso de "Não achou?" da
         * página onde o colaborador pede o link de senha. O aviso existe porque
         * cada empresa tem o seu correio: no Outlook o e-mail cai em "Lixo
         * eletrônico" ou "Outros", não em "spam" nem "promoções".
         *
         * Desligada, volta o aviso padrão; o endereço continua o que estiver —
         * ele está publicado na intranet do Cliente e não pode mudar sozinho.
         */
        'first_access_page' => [
            'label' => 'Página de primeiro acesso',
            'description' => 'Endereço e aviso de "Não achou?" do jeito deste Cliente.',
            'description_off' => 'Aviso padrão; o endereço segue o atual.',
            'icon' => 'external',
            'default' => false,
        ],
    ];

    /**
     * Onde a logo aparece, com a chave `project_logo` ligada.
     *
     * Padrão só no Projeto: era o único nível que existia, e Cliente que já
     * usava não pode ganhar a marca no topo de todas as telas sem pedir.
     */
    public const LOGO_NIVEIS = [
        'cliente' => [
            'label' => 'Cliente',
            'description' => 'Topo das telas do Admin Cliente',
        ],
        'projeto' => [
            'label' => 'Projeto',
            'description' => 'Ao lado do nome do Projeto',
        ],
        // A mesma logo de cada Projeto, no topo do portal de quem resgata.
        'colaborador' => [
            'label' => 'Colaborador',
            'description' => 'Topo do portal, com a logo do Projeto',
        ],
    ];

    private const LOGO_NIVEIS_PADRAO = ['projeto'];

    /**
     * Colunas da planilha de importação de Colaboradores que o Cliente pode
     * ligar e desligar.
     *
     * `locked`: nome e e-mail não saem. Sem e-mail não existe conta nem como
     * entrar, e o cadastro exige o nome — desmarcá-los faria toda linha ser
     * recusada, com a planilha certinha na mão de quem subiu.
     *
     * O código do kit não está aqui: ele não é escolha do Cliente, é do
     * Projeto. Aparece sozinho quando o Projeto tem mais de um kit, porque aí
     * não há outro jeito de saber qual kit cada pessoa recebe.
     */
    public const IMPORT_COLUMNS = [
        'nome' => ['label' => 'Nome', 'locked' => true],
        'email' => ['label' => 'E-mail', 'locked' => true],
        'funcao' => ['label' => 'Função', 'locked' => false],
        'cpf' => ['label' => 'CPF', 'locked' => false],
        'telefone' => ['label' => 'Telefone', 'locked' => false],
    ];

    /**
     * As colunas que a planilha deste Cliente usa, na ordem do modelo.
     *
     * Chave "Definir campos para resgate" desligada, ou nunca configurado =
     * todas. A seleção gravada fica guardada mesmo com a chave desligada:
     * religar devolve o que estava. E as travadas entram sempre, mesmo que o
     * JSON gravado diga o contrário: a trava vale no servidor, não só na tela.
     *
     * @return array<int, string>
     */
    public function colunasDaPlanilha(): array
    {
        $gravadas = $this->customizacao('import_fields')
            ? ($this->customizations['import_columns'] ?? null)
            : null;

        return array_values(array_filter(
            array_keys(self::IMPORT_COLUMNS),
            fn (string $coluna) => self::IMPORT_COLUMNS[$coluna]['locked']
                || ! is_array($gravadas)
                || in_array($coluna, $gravadas, true),
        ));
    }

    /**
     * Se este Cliente coleta CPF dos Colaboradores.
     *
     * Desmarcar a coluna CPF nas Customizações quer dizer "não coletamos
     * CPF" — e isso vale para o resgate também: pedir no portal o dado que o
     * Cliente decidiu não entregar só trava a pessoa na frente do formulário.
     */
    public function pedeCpf(): bool
    {
        return in_array('cpf', $this->colunasDaPlanilha(), true);
    }

    /**
     * Os níveis em que a logo aparece (`cliente`, `projeto`), na ordem de
     * LOGO_NIVEIS. Vale só com a chave `project_logo` ligada.
     *
     * @return array<int, string>
     */
    public function logoNiveis(): array
    {
        $gravados = $this->customizations['logo_niveis'] ?? self::LOGO_NIVEIS_PADRAO;

        return array_values(array_intersect(array_keys(self::LOGO_NIVEIS), (array) $gravados));
    }

    public function mostraLogoNo(string $nivel): bool
    {
        return $this->customizacao('project_logo') && in_array($nivel, $this->logoNiveis(), true);
    }

    /**
     * A WeLog envia o e-mail desta etapa da jornada (EmailTemplate::JORNADA)?
     *
     * Duas chaves: a geral, "E-mails de comunicação de resgate", e a de cada
     * etapa, no Estúdio de mensagens. A Sotreq, por exemplo, quer os e-mails a
     * partir do resgate, mas não o primeiro aviso: ela mesma divulga a
     * campanha. Chave que não é da jornada segue só a geral.
     */
    /**
     * A pessoa entra pelo link de primeiro acesso, e não por um e-mail da WeLog?
     *
     * É assim quando a WeLog não faz o primeiro contato: e-mails de
     * comunicação desligados, ou a etapa "Presente liberado" desligada no
     * Estúdio de mensagens. O Cliente divulga o link (a Sotreq faz assim).
     *
     * Nesse modo, cadastro e liberação só criam a conta: nada de token de
     * senha nem de e-mail. O token custa um bcrypt (0,2 a 0,4s) por pessoa, e
     * gerado para um e-mail que não sai, era só tempo perdido — nove mil
     * pessoas, quase uma hora de worker.
     */
    public function entradaPeloPrimeiroAcesso(): bool
    {
        return ! $this->enviaEtapa(EmailTemplate::KEY_PROJECT_RELEASED);
    }

    public function enviaEtapa(string $chave): bool
    {
        if (! $this->customizacao('welog_sends_emails')) {
            return false;
        }

        return ! in_array($chave, $this->etapasDesligadas(), true);
    }

    /** @return array<int, string> as etapas da jornada com o envio desligado */
    public function etapasDesligadas(): array
    {
        return array_values((array) ($this->customizations['etapas_desligadas'] ?? []));
    }

    /**
     * O e-mail de atendimento que o portal do Colaborador mostra — ou null,
     * quando a customização está desligada ou sem endereço.
     */
    public function emailDeAtendimento(): ?string
    {
        $email = trim((string) ($this->customizations['support_email'] ?? ''));

        return $this->customizacao('portal_support') && $email !== '' ? $email : null;
    }

    /**
     * O aviso de "Não achou?" da página de primeiro acesso — ou null, quando
     * vale o padrão (customização desligada ou sem texto).
     */
    public function dicaDePrimeiroAcesso(): ?string
    {
        $dica = trim((string) ($this->customizations['first_access_dica'] ?? ''));

        return $this->customizacao('first_access_page') && $dica !== '' ? $dica : null;
    }

    /**
     * Endereços de primeiro acesso que este Cliente já usou.
     *
     * Guardados porque o link fica publicado na intranet: trocar o endereço
     * não pode deixar quem clica no antigo numa página de erro.
     *
     * @return array<int, string>
     */
    public function slugsAntigosDePrimeiroAcesso(): array
    {
        return array_values((array) ($this->customizations['first_access_slugs_antigos'] ?? []));
    }

    /** O Cliente que já usou este endereço de primeiro acesso, se algum usou. */
    public static function porSlugAntigoDePrimeiroAcesso(string $slug): ?self
    {
        $slug = mb_strtolower(trim($slug));

        // Poucos Clientes, e só no caminho de exceção (endereço não achado):
        // varrer em PHP sai mais simples que consultar JSON em dois bancos.
        return static::query()
            ->whereNotNull('customizations')
            ->get()
            ->first(fn (self $c) => in_array($slug, $c->slugsAntigosDePrimeiroAcesso(), true));
    }

    /** A logo da empresa, para o topo das telas do Admin Cliente — ou null. */
    public function logoDoClienteUrl(): ?string
    {
        return $this->mostraLogoNo('cliente') ? $this->logo_url : null;
    }

    /**
     * URL da logo da empresa, ou null.
     *
     * Guarda o caminho no disco, não a URL: a URL depende do APP_URL e mudaria
     * sozinha entre dev, staging e produção.
     */
    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->logo_path
            ? Storage::disk('public')->url($this->logo_path)
            : null);
    }

    protected function casts(): array
    {
        return [
            'customizations' => 'array',
            'first_access_token_at' => 'datetime',
        ];
    }

    /**
     * O endereço que o Cliente publica na intranet dele: um por Cliente, com o
     * nome dele no fim (/primeiro-acesso/sotreq).
     */
    public function linkDePrimeiroAcesso(): string
    {
        return route('primeiro-acesso.form', $this->slugDePrimeiroAcesso());
    }

    /**
     * O nome do Cliente no endereço, criado na primeira vez e fixo depois.
     *
     * Sai do nome de exibição, sem a natureza jurídica ("Sotreq S.A." vira
     * "sotreq"). Fixo porque é publicado: renomear o Cliente não pode matar o
     * endereço que já está na intranet. Dois Clientes com o mesmo nome levam
     * um número no segundo.
     */
    public function slugDePrimeiroAcesso(): string
    {
        if (filled($this->first_access_slug)) {
            return $this->first_access_slug;
        }

        $nome = preg_replace(
            '/\b(s\.?\s?\/?a\.?|ltda\.?|me|epp|eireli|s\/s|sociedade an[oô]nima)\s*$/iu',
            '',
            trim((string) ($this->display_name ?: $this->name)),
        );

        $base = Str::slug(trim((string) $nome)) ?: 'cliente-'.$this->id;
        $slug = $base;

        for ($n = 2; static::where('first_access_slug', $slug)->whereKeyNot($this->id)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        $this->forceFill(['first_access_slug' => $slug])->saveQuietly();

        return $slug;
    }

    /** Estado de UMA customização, caindo no padrão quando nunca foi tocada. */
    public function customizacao(string $chave): bool
    {
        return (bool) ($this->customizations[$chave]
            ?? self::CUSTOMIZATIONS[$chave]['default']
            ?? false);
    }

    /**
     * Todas as chaves conhecidas resolvidas, para a tela.
     *
     * Sempre o conjunto COMPLETO, não só o que está gravado: a tela precisa
     * listar feature recém-criada em Cliente antigo, e uma chave ausente no
     * JSON viraria uma linha que some do modal.
     */
    public function customizacoes(): array
    {
        return collect(self::CUSTOMIZATIONS)
            ->map(fn (array $def, string $chave) => $this->customizacao($chave))
            ->all();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** Administradores da empresa (usuários da plataforma, não contato comercial). */
    public function admins(): HasMany
    {
        return $this->hasMany(User::class)->where('role', User::ROLE_ADMIN_CLIENT);
    }

    /**
     * Nome de empresa que não é nome: vazio, só dígitos, ou o próprio
     * documento (com ou sem máscara). É o que o sync Omie/appnoz deixa quando
     * o ERP não devolve a razão social — e o que faz a empresa aparecer "com o
     * CNPJ no lugar do nome" na lista de Clientes.
     */
    public function temNomeProvisorio(): bool
    {
        // Só dígitos e pontuação de documento (., /, -, espaço): cobre o nome
        // vazio, o CNPJ "corrido" e o CNPJ mascarado. Qualquer letra = nome real.
        return preg_match('~^[\d./\s-]*$~', trim((string) $this->name)) === 1;
    }

    /**
     * O que falta na ficha da empresa, em nome de gente.
     *
     * Empresa que nasce do pedido da Omie vem com documento e pouco mais: sem
     * endereço não há para onde despachar, e com a razão social em branco ela
     * aparece na tela como um CNPJ corrido. A lista alimenta o cadastro manual
     * de Projeto, que abre a ficha já preenchida apontando o que falta, em vez
     * de criar o Projeto em cima de um cliente pela metade.
     *
     * @return array<int, string>
     */
    public function pendenciasDeCadastro(): array
    {
        $faltando = [];

        if ($this->temNomeProvisorio()) {
            $faltando[] = 'razão social';
        }

        foreach ([
            'zip' => 'CEP',
            'street' => 'logradouro',
            'number' => 'número',
            'district' => 'bairro',
            'city' => 'cidade',
            'state' => 'UF',
        ] as $campo => $rotulo) {
            if (blank($this->{$campo})) {
                $faltando[] = $rotulo;
            }
        }

        return $faltando;
    }

    /**
     * Nome pelo qual a empresa é apresentada ao Usuário: o de exibição quando
     * a WeGift definiu um, senão a razão social vinda da Omie.
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => $this->display_name ?: (string) $this->name);
    }

    /** Endereço em uma linha, ou null quando não há o mínimo para exibir. */
    protected function fullAddress(): Attribute
    {
        return Attribute::get(function (): ?string {
            if (blank($this->street) && blank($this->city)) {
                return null;
            }

            $logradouro = $this->street.($this->number ? ", {$this->number}" : '');

            return implode(', ', array_filter([
                $logradouro ?: null,
                $this->complement,
                $this->district,
                $this->city && $this->state ? "{$this->city}/{$this->state}" : ($this->city ?: null),
                $this->zip ? 'CEP '.$this->formatted_zip : null,
            ]));
        });
    }

    protected function formattedZip(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->zip
            ? preg_replace('/^(\d{5})(\d{3})$/', '$1-$2', $this->zip)
            : null);
    }

    /**
     * O que ainda falta para o cadastro estar completo.
     *
     * A empresa criada pelo webhook de pedido da Omie nasce só com razão social
     * e CNPJ — isto diz ao operador o que conferir antes de operar entregas,
     * em vez de descobrir na hora de expedir.
     *
     * @return array<int, string>
     */
    public function missingFields(): array
    {
        $rotulos = [
            'contact_email' => 'e-mail de contato',
            'contact_phone' => 'telefone',
            'zip' => 'CEP',
            'street' => 'endereço',
            'city' => 'cidade',
            'state' => 'UF',
        ];

        return array_values(array_filter(
            $rotulos,
            fn (string $campo) => blank($this->{$campo}),
            ARRAY_FILTER_USE_KEY,
        ));
    }
}

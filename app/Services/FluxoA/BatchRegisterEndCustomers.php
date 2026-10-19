<?php

namespace App\Services\FluxoA;

use App\Exceptions\DomainException;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Services\Support\SpreadsheetReader;
use App\Support\NomeProprio;
use Illuminate\Support\Facades\Validator;

/**
 * Módulo 7 — cadastro em lote via template Excel/CSV com campos
 * pré-definidos: nome, email, funcao, cpf, telefone.
 * Valida estruturalmente cada linha e reporta erros específicos.
 *
 * A planilha cadastra as duas pontas do Projeto. "Função" diz o que a linha
 * é: em branco ou "Colaborador", nasce quem resgata; "Admin Projeto", nasce
 * quem administra. Cadastrar os admins um a um depois de subir a planilha era
 * o passo que ficava para trás — e é justamente quem precisa entrar primeiro.
 *
 * A planilha traz só os dados das pessoas. O que cada uma recebe (quais
 * itens são garantidos e entre quais ela escolhe 1) é decidido UMA vez, na
 * tela de importação, e vale para todos os Colaboradores do arquivo. Marcar
 * X e O célula a célula, item a item, era o passo em que o Cliente travava.
 */
class BatchRegisterEndCustomers
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly RegisterEndCustomer $registerOne,
        private readonly RegisterProjectAdmin $registerAdmin,
    ) {}

    public const TEMPLATE_COLUMNS = ['nome', 'email', 'funcao', 'cpf', 'telefone'];

    public const FUNCAO_COLABORADOR = 'colaborador';

    public const FUNCAO_ADMIN = 'admin_projeto';

    /**
     * Como a coluna "Função" pode vir escrita — planilha é texto livre, e
     * recusar a linha por causa de um "Admin de Projeto" com "de" no meio é
     * rigor que não protege nada.
     */
    private const FUNCOES = [
        '' => self::FUNCAO_COLABORADOR,
        'colaborador' => self::FUNCAO_COLABORADOR,
        'colaboradora' => self::FUNCAO_COLABORADOR,
        'cliente' => self::FUNCAO_COLABORADOR,
        'cliente_final' => self::FUNCAO_COLABORADOR,
        'admin' => self::FUNCAO_ADMIN,
        'admin_projeto' => self::FUNCAO_ADMIN,
        'admin_de_projeto' => self::FUNCAO_ADMIN,
        'admin_do_projeto' => self::FUNCAO_ADMIN,
        'administrador' => self::FUNCAO_ADMIN,
        'administrador_de_projeto' => self::FUNCAO_ADMIN,
        'administrador_do_projeto' => self::FUNCAO_ADMIN,
    ];

    /** As colunas do modelo deste Projeto: as que o Cliente marcou nas Customizações. */
    public static function templateColumns(Project $project): array
    {
        return $project->company?->colunasDaPlanilha() ?? array_keys(Company::IMPORT_COLUMNS);
    }

    /**
     * O que cada Colaborador da planilha recebe, como foi escolhido na tela.
     *
     * Nada escolhido (Projeto de um item só, ou chamada sem a escolha) é
     * todos os itens garantidos, o que a planilha sempre fez. Item nas duas
     * listas fica só como garantido, porque é o que a pessoa recebe de
     * qualquer jeito.
     *
     * @param  array<int, int|string>  $garantidos
     * @param  array<int, int|string>  $opcoes
     * @return array{garantidos: array<int, int>, opcoes: array<int, int>}
     */
    public static function itensEscolhidos(Project $project, array $garantidos = [], array $opcoes = []): array
    {
        $doProjeto = $project->products()->pluck('products.id')->map(fn ($id) => (int) $id)->all();
        $garantidos = array_values(array_unique(array_map('intval', $garantidos)));
        $opcoes = array_values(array_diff(array_unique(array_map('intval', $opcoes)), $garantidos));

        if (array_diff([...$garantidos, ...$opcoes], $doProjeto) !== []) {
            throw new DomainException('Um dos itens escolhidos não faz parte deste Projeto.');
        }

        if ($garantidos === [] && $opcoes === []) {
            return ['garantidos' => $doProjeto, 'opcoes' => []];
        }

        // Escolher entre um item só é recebê-lo: a tela não deixa chegar aqui.
        if (count($opcoes) === 1) {
            throw new DomainException('A escolha precisa de 2 ou mais itens. Marque outro item como escolha ou deixe este como garantido.');
        }

        return ['garantidos' => $garantidos, 'opcoes' => $opcoes];
    }

    /**
     * Linha de exemplo alinhada às colunas do modelo. Fica aqui, junto da
     * lista: separadas, bastava uma coluna nova para o exemplo passar a
     * preencher a célula errada.
     *
     * @return array<int, string>
     */
    public static function templateExample(Project $project): array
    {
        $exemplo = [
            'nome' => 'João da Silva',
            'email' => 'joao@empresa.com.br',
            // Escrito por extenso para o modelo ensinar o valor aceito — em
            // branco, quem abre a planilha não descobre que existe a outra.
            'funcao' => 'Colaborador',
            'cpf' => '111.222.333-44',
            // O formato em que o telefone é gravado. Outros ("(41) 99999-0000",
            // sem o 55…) também entram: o cadastro normaliza.
            'telefone' => '+5541999990000',
        ];

        return array_map(fn (string $coluna) => $exemplo[$coluna], self::templateColumns($project));
    }

    /**
     * Lê a planilha e devolve as linhas, sem cadastrar nada.
     *
     * A leitura é barata; quem custa é o cadastro — um bcrypt por linha. Por
     * isso ela ficou na requisição do upload e o resto foi para a fila: com
     * nove mil linhas, o laço inteiro passa de dez minutos e o nginx corta a
     * requisição aos cinco.
     *
     * @return array<int, array<string, string>>
     */
    public function lerPlanilha(string $filePath): array
    {
        $rows = $this->reader->read($filePath);

        if ($rows === []) {
            throw new DomainException('A planilha está vazia ou não segue o template.');
        }

        return array_map(self::traduzirCabecalho(...), $rows);
    }

    /**
     * Nomes de coluna que as pessoas escrevem no lugar dos do modelo.
     *
     * "E-mail" com hífen é o jeito mais comum de escrever no Brasil, e sem
     * esta tradução ele virava `e_mail` e TODA linha era recusada por falta de
     * e-mail — com o e-mail ali, na planilha, uma coluna ao lado.
     */
    public const APELIDOS = [
        'e_mail' => 'email',
        'email_corporativo' => 'email',
        'nome_completo' => 'nome',
        'celular' => 'telefone',
        'fone' => 'telefone',
        'whatsapp' => 'telefone',
    ];

    /** @param  array<string, string>  $row */
    private static function traduzirCabecalho(array $row): array
    {
        $traduzida = [];

        foreach ($row as $coluna => $valor) {
            $destino = self::APELIDOS[$coluna] ?? $coluna;

            // Se a planilha trouxer as duas grafias, vale a do modelo.
            if (! array_key_exists($destino, $traduzida) || $destino === $coluna) {
                $traduzida[$destino] = $valor;
            }
        }

        return $traduzida;
    }

    /** Uma linha passa pelas mesmas regras de função que o cadastro usa. */
    public function funcaoDaLinha(array $row): ?string
    {
        return $this->funcaoDe($row);
    }

    /**
     * Lê e cadastra tudo de uma vez.
     *
     * Serve a planilha pequena, que cabe folgada na requisição. Acima de
     * algumas centenas de linhas o caminho é `lerPlanilha` + fila.
     *
     * @return array{created: int, admins: int, errors: array<int, array{row: int, message: string}>}
     */
    public function handle(Project $project, string $filePath, User $actor, ?array $itens = null): array
    {
        return $this->processarLinhas($project, self::marcarRepetidos($this->lerPlanilha($filePath)), $actor, 2, $itens);
    }

    /** Chave que marca a linha cujo e-mail já apareceu antes na planilha. */
    public const REPETIDA_DA_LINHA = '_repetida_da_linha';

    /**
     * Marca as linhas com e-mail que já apareceu antes NA MESMA planilha.
     *
     * Feito na planilha inteira, antes dos lotes: a fila corta o arquivo de
     * cinquenta em cinquenta, e a repetição pode estar a quatro mil linhas de
     * distância, em outro lote. A primeira ocorrência entra; as outras são
     * recusadas apontando para ela.
     *
     * @param  array<int, array<string, string>>  $linhas
     * @return array<int, array<string, string|int>>
     */
    public static function marcarRepetidos(array $linhas, int $primeiraLinha = 2): array
    {
        $vistos = [];

        foreach (array_values($linhas) as $indice => $row) {
            $email = mb_strtolower(trim((string) ($row['email'] ?? '')));

            if ($email === '') {
                continue;
            }

            if (isset($vistos[$email])) {
                $linhas[$indice][self::REPETIDA_DA_LINHA] = $vistos[$email];
            } else {
                $vistos[$email] = $primeiraLinha + $indice;
            }
        }

        return array_values($linhas);
    }

    /**
     * Cadastra um punhado de linhas já lidas.
     *
     * `$primeiraLinha` é o número que a primeira delas tem NA PLANILHA — o
     * lote que começa na linha 4.001 precisa reportar "linha 4.017", não
     * "linha 17", senão o operador procura o erro no lugar errado.
     *
     * `$itens` é a escolha feita na tela (ver `itensEscolhidos`): a mesma para
     * todos os Colaboradores do arquivo. Sem ela, todos os itens garantidos.
     *
     * Cada erro leva a linha como veio (`data`): é com ela que a tela monta a
     * planilha só das recusadas, para corrigir e subir de novo.
     *
     * @param  array<int, array<string, string>>  $rows
     * @param  array{garantidos: array<int, int>, opcoes: array<int, int>}|null  $itens
     * @return array{created: int, admins: int, ignored: int, errors: array<int, array{row: int, message: string, data: array<string, string>}>}
     */
    public function processarLinhas(Project $project, array $rows, User $actor, int $primeiraLinha = 2, ?array $itens = null): array
    {
        $created = 0;
        $admins = 0;
        $ignored = 0;
        $errors = [];

        $itens ??= self::itensEscolhidos($project);

        $jaNoProjeto = $this->jaNoProjeto($project, $rows);

        /*
         * Coluna desmarcada pelo Cliente é ignorada, mesmo que venha preenchida.
         *
         * Desmarcar CPF quer dizer "não coletamos CPF", e isso tem de valer
         * também para quem sobe uma planilha antiga, com a coluna ainda lá.
         * Sem isto, o dado que o Cliente decidiu não entregar entraria do
         * mesmo jeito. Função desmarcada faz todo mundo ser Colaborador.
         */
        $desligadas = array_flip(array_diff(
            array_keys(Company::IMPORT_COLUMNS),
            $project->company?->colunasDaPlanilha() ?? array_keys(Company::IMPORT_COLUMNS),
        ));

        foreach (array_values($rows) as $index => $row) {
            $rowNumber = $primeiraLinha + $index;
            $repetidaDa = $row[self::REPETIDA_DA_LINHA] ?? null;
            $original = array_diff_key($row, [self::REPETIDA_DA_LINHA => true]);
            $row = array_diff_key($original, $desligadas);

            $recusar = function (string $message) use (&$errors, $rowNumber, $original) {
                $errors[] = ['row' => $rowNumber, 'message' => $message, 'data' => $original];
            };

            // Linha toda em branco é o fim da planilha que o Excel arrasta
            // junto, não erro de ninguém (a validação também a pula).
            if (implode('', array_map(fn ($v) => trim((string) $v), $row)) === '') {
                continue;
            }

            if ($repetidaDa !== null) {
                $recusar("E-mail repetido na planilha (também na linha {$repetidaDa}).");

                continue;
            }

            $funcao = $this->funcaoDe($row);

            if ($funcao === null) {
                $recusar('Função "'.trim((string) ($row['funcao'] ?? '')).'" não reconhecida. '
                    .'Use "Colaborador" ou "Admin Projeto".');

                continue;
            }

            // O Admin administra, não resgata: sem CPF nem telefone, que só
            // existem para a entrega.
            $regras = $funcao === self::FUNCAO_ADMIN
                ? [
                    'nome' => ['required', 'string', 'max:255'],
                    'email' => ['required', 'email'],
                ]
                : [
                    'nome' => ['required', 'string', 'max:255'],
                    'email' => ['required', 'email'],
                    'cpf' => ['nullable', 'string'],
                    'telefone' => ['nullable', 'string'],
                ];

            $validator = Validator::make($row, $regras, [], [
                'nome' => 'nome', 'email' => 'e-mail',
            ]);

            if ($validator->fails()) {
                $recusar(implode(' ', $validator->errors()->all()));

                continue;
            }

            $nome = NomeProprio::formatar($row['nome']);

            if ($funcao === self::FUNCAO_ADMIN) {
                try {
                    $this->registerAdmin->handle($project, [
                        'name' => $nome,
                        'email' => $row['email'],
                    ], $actor);

                    $admins++;
                } catch (DomainException $e) {
                    $recusar($e->getMessage());
                }

                continue;
            }

            // Já é Colaborador deste Projeto: não cadastra de novo, nem manda
            // convite outra vez. Subir a mesma planilha duas vezes (ou a
            // planilha inteira depois de corrigir três linhas) não duplica
            // ninguém.
            if (isset($jaNoProjeto[mb_strtolower(trim($row['email']))])) {
                $ignored++;

                continue;
            }

            try {
                $this->registerOne->handle($project, [
                    'name' => $nome,
                    'email' => $row['email'],
                    // Colunas opcionais podem nem existir na planilha enviada.
                    'cpf' => ($row['cpf'] ?? '') ?: null,
                    'phone' => ($row['telefone'] ?? '') ?: null,
                    'product_ids' => $itens['garantidos'],
                    'choice_product_ids' => $itens['opcoes'],
                    'mode' => RegisterEndCustomer::MODE_ALL,
                ], $actor);

                $created++;
            } catch (DomainException $e) {
                $recusar($e->getMessage());
            }
        }

        return ['created' => $created, 'admins' => $admins, 'ignored' => $ignored, 'errors' => $errors];
    }

    /**
     * Os e-mails destas linhas que já são Colaboradores do Projeto, numa
     * consulta só (uma por linha seriam nove mil idas ao banco).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, true> e-mail em minúsculas => true
     */
    private function jaNoProjeto(Project $project, array $rows): array
    {
        $emails = collect($rows)
            ->map(fn (array $r) => mb_strtolower(trim((string) ($r['email'] ?? ''))))
            ->filter()
            ->unique()
            ->values();

        if ($emails->isEmpty()) {
            return [];
        }

        return User::query()
            ->whereIn('email', $emails)
            ->whereHas('entitlements', fn ($q) => $q->where('project_id', $project->id))
            ->pluck('email')
            ->mapWithKeys(fn ($email) => [mb_strtolower((string) $email) => true])
            ->all();
    }

    /**
     * A função da linha, ou `null` quando a célula traz algo que ninguém sabe
     * ler — melhor recusar a linha do que cadastrar Colaborador para quem
     * escreveu "gerente" achando que estava criando um admin.
     */
    private function funcaoDe(array $row): ?string
    {
        $valor = mb_strtolower(trim((string) ($row['funcao'] ?? '')));

        $valor = strtr($valor, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'õ' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ç' => 'c',
        ]);

        return self::FUNCOES[str_replace([' ', '-'], '_', $valor)] ?? null;
    }
}

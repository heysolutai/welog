<?php

namespace App\Services\FluxoA;

use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectEntitlement;
use App\Models\User;
use App\Support\NomeProprio;
use App\Support\Values\Cpf;
use App\Support\Values\Telefone;
use Illuminate\Support\Facades\Validator;

/**
 * Confere a planilha de Colaboradores ANTES de cadastrar qualquer um.
 *
 * Sem isto, o erro só aparecia depois: as linhas ruins eram puladas e o
 * operador descobria no relatório, com parte da planilha já dentro. Pior era
 * o cabeçalho, que ninguém conferia — uma coluna com o nome errado derrubava
 * todas as linhas, cada uma com a mesma mensagem.
 *
 * Nada aqui grava. E as regras são as MESMAS do cadastro: validação que
 * aprova o que o cadastro recusa é pior que nenhuma, porque ensina a confiar
 * no "tudo certo".
 *
 * Dois pesos, porque são perguntas diferentes:
 *  - erro: a linha NÃO entra (e-mail inválido, e-mail repetido…);
 *  - aviso: a linha entra, mas vale conferir (CPF com dígito errado…).
 */
class ValidateEntitlementSheet
{
    /** Quantas mensagens por linha voltam. O resto vira contagem. */
    private const TETO_DE_MENSAGENS = 300;

    /**
     * Quantas linhas a prévia mostra: as primeiras da planilha, só para a
     * pessoa reconhecer o arquivo. As com problema vêm à parte, em `linhas`.
     */
    private const TETO_DA_PREVIA = 5;

    public function __construct(private readonly BatchRegisterEndCustomers $batch) {}

    /**
     * @param  array<int, array<string, string>>  $rows  já lidas por `lerPlanilha`
     */
    public function handle(Project $project, array $rows, int $primeiraLinha = 2): array
    {
        $colunasAtivas = $project->company?->colunasDaPlanilha() ?? array_keys(Company::IMPORT_COLUMNS);
        $colunasDaPlanilha = array_values(array_filter(array_keys($rows[0] ?? []), fn ($c) => $c !== ''));
        // A prévia mostra a planilha como ela é: as colunas que entram (as
        // marcadas pelo Cliente), na ordem em que vieram.
        $previaColunas = array_values(array_intersect($colunasDaPlanilha, $colunasAtivas));

        $cabecalho = $this->conferirCabecalho($colunasDaPlanilha, $colunasAtivas);

        // Os itens de cada Colaborador vêm do Projeto: sem nenhum, toda linha
        // cairia no cadastro com o mesmo erro.
        if (! $project->products()->exists()) {
            array_unshift($cabecalho, [
                'tipo' => 'erro',
                'mensagem' => 'Este Projeto ainda não tem itens no pedido: não há o que autorizar aos Colaboradores.',
            ]);
        }

        $temErroNoCabecalho = collect($cabecalho)->contains('tipo', 'erro');

        [$linhas, $contagem, $previa] = $temErroNoCabecalho
            ? [[], ['prontas' => 0, 'com_erro' => count($rows), 'com_aviso' => 0], $this->previaSemConferir($rows, $previaColunas, $primeiraLinha)]
            : $this->conferirLinhas($project, $rows, $colunasAtivas, $previaColunas, $primeiraLinha);

        return [
            'total' => count($rows),
            'prontas' => $contagem['prontas'],
            'com_erro' => $contagem['com_erro'],
            'com_aviso' => $contagem['com_aviso'],
            'cabecalho' => $cabecalho,
            'linhas' => array_slice($linhas, 0, self::TETO_DE_MENSAGENS),
            'linhas_omitidas' => max(0, count($linhas) - self::TETO_DE_MENSAGENS),
            'colunas' => $this->estadoDasColunas($colunasDaPlanilha, $colunasAtivas),
            'previa_colunas' => $previaColunas,
            'previa' => array_slice($previa, 0, self::TETO_DA_PREVIA),
            'previa_omitidas' => max(0, count($previa) - self::TETO_DA_PREVIA),
            'pode_importar' => ! $temErroNoCabecalho && $contagem['prontas'] > 0,
        ];
    }

    /**
     * Cada coluna da planilha com o seu estado: `ok` entra, `ignorada` fica
     * de fora, `falta` é obrigatória e não veio.
     *
     * @return array<int, array{coluna: string, estado: string}>
     */
    private function estadoDasColunas(array $colunas, array $ativas): array
    {
        $estado = array_map(fn (string $c) => [
            'coluna' => $c,
            'estado' => in_array($c, $ativas, true) ? 'ok' : 'ignorada',
        ], $colunas);

        foreach (['nome', 'email'] as $obrigatoria) {
            if (! in_array($obrigatoria, $colunas, true)) {
                $estado[] = ['coluna' => $obrigatoria, 'estado' => 'falta'];
            }
        }

        return $estado;
    }

    /**
     * Cabeçalho errado: as linhas não são conferidas (ver conferirCabecalho),
     * mas a prévia continua mostrando o que veio, sem cor.
     */
    private function previaSemConferir(array $rows, array $colunas, int $primeiraLinha): array
    {
        $previa = [];

        foreach (array_values($rows) as $indice => $row) {
            $previa[] = [
                'linha' => $primeiraLinha + $indice,
                'status' => null,
                'valores' => $this->valoresDaPrevia($row, $colunas),
                'celulas' => [],
                'mensagens' => [],
            ];
        }

        return $previa;
    }

    private function valoresDaPrevia(array $row, array $colunas): array
    {
        $valores = [];

        foreach ($colunas as $coluna) {
            $valores[$coluna] = trim((string) ($row[$coluna] ?? ''));
        }

        // A prévia mostra nome e telefone como vão ser gravados, não como vieram.
        if (($valores['nome'] ?? '') !== '') {
            $valores['nome'] = NomeProprio::formatar($valores['nome']);
        }

        if (($valores['telefone'] ?? '') !== '') {
            $valores['telefone'] = Telefone::tentar($valores['telefone'])?->e164 ?? $valores['telefone'];
        }

        return $valores;
    }

    /**
     * O cabeçalho é conferido primeiro e, se estiver errado, sozinho.
     *
     * Coluna obrigatória ausente derruba todas as linhas com a mesma frase;
     * listar nove mil "o campo e-mail é obrigatório" esconderia a única coisa
     * a corrigir, que é o nome de uma coluna.
     *
     * @return array<int, array{tipo: string, mensagem: string}>
     */
    private function conferirCabecalho(array $colunas, array $ativas): array
    {
        $mensagens = [];

        foreach (['nome', 'email'] as $coluna) {
            if (! in_array($coluna, $colunas, true)) {
                $mensagens[] = [
                    'tipo' => 'erro',
                    'mensagem' => "Falta a coluna \"{$coluna}\". Baixe o modelo e confira o cabeçalho.",
                ];
            }
        }

        $conhecidas = array_keys(Company::IMPORT_COLUMNS);

        foreach ($colunas as $coluna) {
            if (! in_array($coluna, $conhecidas, true)) {
                $mensagens[] = [
                    'tipo' => 'aviso',
                    'mensagem' => "A coluna \"{$coluna}\" não faz parte do modelo e será ignorada.",
                ];
            } elseif (! in_array($coluna, $ativas, true)) {
                $mensagens[] = [
                    'tipo' => 'aviso',
                    'mensagem' => "A coluna \"{$coluna}\" está desmarcada nas Customizações do Cliente e será ignorada.",
                ];
            }
        }

        return $mensagens;
    }

    /**
     * Cada erro e aviso sai preso à coluna que o causou: é isso que deixa a
     * prévia pintar a célula certa, e não só a linha.
     *
     * @return array{0: array<int, array{linha: int, tipo: string, mensagem: string}>, 1: array{prontas: int, com_erro: int, com_aviso: int}, 2: array<int, array<string, mixed>>}
     */
    private function conferirLinhas(Project $project, array $rows, array $ativas, array $previaColunas, int $primeiraLinha): array
    {
        $desligadas = array_flip(array_diff(array_keys(Company::IMPORT_COLUMNS), $ativas));

        /*
         * Contas que já existem, numa consulta só.
         *
         * Uma por linha seriam nove mil idas ao banco numa requisição que o
         * operador está esperando na frente da tela.
         */
        $emails = collect($rows)
            ->map(fn (array $r) => mb_strtolower(trim((string) ($r['email'] ?? ''))))
            ->filter()
            ->unique()
            ->values();

        $existentes = User::query()
            ->whereIn('email', $emails)
            ->get(['id', 'email', 'role', 'company_id', 'active'])
            ->keyBy(fn (User $u) => mb_strtolower($u->email));

        // Quem já é Colaborador deste Projeto: a importação pula a linha.
        $noProjeto = ProjectEntitlement::query()
            ->where('project_id', $project->id)
            ->whereIn('user_id', $existentes->pluck('id'))
            ->pluck('user_id')
            ->flip();

        $mensagens = [];
        $previa = [];
        $contagem = ['prontas' => 0, 'com_erro' => 0, 'com_aviso' => 0];
        $vistos = [];

        foreach (array_values($rows) as $indice => $row) {
            $linha = $primeiraLinha + $indice;
            $row = array_diff_key($row, $desligadas);

            // Linha toda em branco não é erro de ninguém: é o fim da planilha
            // que o Excel às vezes arrasta junto.
            if (implode('', array_map('trim', $row)) === '') {
                continue;
            }

            // [coluna => [mensagens]]
            $erros = [];
            $avisos = [];

            $funcao = $this->batch->funcaoDaLinha($row);

            if ($funcao === null) {
                $erros['funcao'][] = 'Função "'.trim((string) ($row['funcao'] ?? '')).'" não reconhecida. Use "Colaborador" ou "Admin Projeto".';
            }

            $validator = Validator::make($row, [
                'nome' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email'],
            ], [], ['nome' => 'nome', 'email' => 'e-mail']);

            foreach ($validator->errors()->messages() as $coluna => $doCampo) {
                $erros[$coluna] = [...($erros[$coluna] ?? []), ...$doCampo];
            }

            $email = mb_strtolower(trim((string) ($row['email'] ?? '')));

            if ($email !== '' && isset($vistos[$email])) {
                $erros['email'][] = "E-mail repetido na planilha (também na linha {$vistos[$email]}).";
            } elseif ($email !== '') {
                $vistos[$email] = $linha;
            }

            if ($email !== '' && ($conta = $existentes->get($email)) !== null) {
                // Admin desativado desta empresa volta como Colaborador (ver
                // RegisterEndCustomer): só o admin ATIVO prende o e-mail.
                $adminDesativadoDaEmpresa = ! $conta->active
                    && in_array($conta->role, [User::ROLE_ADMIN_CLIENT, User::ROLE_PROJECT_ADMIN], true)
                    && (int) $conta->company_id === (int) $project->company_id;

                if ($conta->role !== User::ROLE_END_CUSTOMER
                    && $funcao !== BatchRegisterEndCustomers::FUNCAO_ADMIN
                    && ! $adminDesativadoDaEmpresa) {
                    $erros['email'][] = 'Este e-mail já é uma conta de acesso ao sistema (admin ou equipe WeGift). Use outro e-mail.';
                } elseif ($conta->role === User::ROLE_END_CUSTOMER
                    && (int) $conta->company_id !== (int) $project->company_id
                    && ! $conta->colaboradorSemProjeto()) {
                    // Colaborador de outra empresa que já saiu de todos os
                    // Projetos dela passa para esta; só barra quem está em um.
                    $erros['email'][] = 'Este e-mail já é Colaborador de '.($conta->company?->name ?? 'outra Empresa').', num Projeto em andamento.';
                }
            }

            if ($funcao === BatchRegisterEndCustomers::FUNCAO_COLABORADOR
                && ! isset($erros['email'])
                && ($conta = $existentes->get($email)) !== null
                && $noProjeto->has($conta->id)) {
                $avisos['email'][] = 'Já é Colaborador deste Projeto: a linha será pulada, sem cadastrar de novo nem reenviar convite.';
            }

            if ($funcao === BatchRegisterEndCustomers::FUNCAO_COLABORADOR) {
                $cpf = trim((string) ($row['cpf'] ?? ''));

                if ($cpf !== '' && Cpf::tentar($cpf) === null) {
                    $avisos['cpf'][] = "CPF \"{$cpf}\" parece inválido. A pessoa vai precisar corrigir no resgate.";
                }

                $telefone = trim((string) ($row['telefone'] ?? ''));

                if ($telefone !== '' && Telefone::tentar($telefone) === null) {
                    $avisos['telefone'][] = "Telefone \"{$telefone}\" não tem DDD e número completos. A pessoa entra sem telefone.";
                }
            }

            $daLinha = [];
            $celulas = [];

            foreach ($erros as $coluna => $doCampo) {
                $celulas[$coluna] = 'erro';

                foreach ($doCampo as $m) {
                    $daLinha[] = ['tipo' => 'erro', 'mensagem' => $m];
                }
            }

            foreach ($avisos as $coluna => $doCampo) {
                $celulas[$coluna] ??= 'aviso';

                foreach ($doCampo as $m) {
                    $daLinha[] = ['tipo' => 'aviso', 'mensagem' => $m];
                }
            }

            foreach ($daLinha as $m) {
                $mensagens[] = ['linha' => $linha, ...$m];
            }

            $status = match (true) {
                $erros !== [] => 'erro',
                $avisos !== [] => 'aviso',
                default => 'ok',
            };

            $previa[] = [
                'linha' => $linha,
                'status' => $status,
                'valores' => $this->valoresDaPrevia($row, $previaColunas),
                'celulas' => $celulas,
                'mensagens' => $daLinha,
            ];

            if ($status === 'erro') {
                $contagem['com_erro']++;

                continue;
            }

            $contagem['prontas']++;

            if ($status === 'aviso') {
                $contagem['com_aviso']++;
            }
        }

        return [$mensagens, $contagem, $previa];
    }
}

<?php

namespace App\Http\Controllers;

use App\Mail\MessageStudioTestMail;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\Project;
use App\Models\User;
use App\Support\SupportContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Estúdio de mensagens: os e-mails da jornada do resgate, na voz do Cliente.
 *
 * Uma ferramenta, dois públicos — o Admin WeGift (pelas Customizações do
 * Cliente) e o Admin Cliente (pelo menu Mensagens). Por isso responde JSON: o
 * mesmo componente abre num modal sobre a tela do Cliente e numa página do
 * lado do Cliente, sem cada tela carregar os modelos nas próprias props.
 *
 * Os alcances são os de sempre (ver EmailTemplate): a empresa inteira ou UMA
 * campanha, o mais específico vence, e "restaurar" apaga o de baixo.
 */
class MessageStudioController extends Controller
{
    /** O botão que cada e-mail leva, para a pré-visualização mostrar o real. */
    private const BOTOES = [
        EmailTemplate::KEY_PROJECT_RELEASED => 'Acessar a WeLog',
        EmailTemplate::KEY_REDEMPTION_CONFIRMED => 'Acompanhar meu pedido',
        EmailTemplate::KEY_REDEMPTION_SHIPPED => 'Acompanhar meu pedido',
        EmailTemplate::KEY_REDEMPTION_DELIVERED => 'Acompanhar meu pedido',
    ];

    public function index(Request $request, Company $company): JsonResponse
    {
        $this->autorizar($request->user(), $company);
        $projeto = $this->projeto($request, $company);
        $definicoes = EmailTemplate::definitions();
        $desligadas = $company->etapasDesligadas();

        $chaves = array_keys(EmailTemplate::JORNADA);
        $padroes = EmailTemplate::whereIn('key', $chaves)->whereNull('company_id')->whereNull('project_id')->get()->keyBy('key');
        $daEmpresa = EmailTemplate::whereIn('key', $chaves)->where('company_id', $company->id)->whereNull('project_id')->get()->keyBy('key');
        $doProjeto = $projeto === null
            ? collect()
            : EmailTemplate::whereIn('key', $chaves)->where('project_id', $projeto->id)->get()->keyBy('key');

        return response()->json([
            'empresa' => ['id' => $company->id, 'nome' => $company->label],
            'escopo' => $projeto === null
                ? ['project_id' => null, 'rotulo' => $company->label]
                : ['project_id' => $projeto->id, 'rotulo' => $projeto->name],
            'projetos' => $this->projetosVisiveis($request->user(), $company),
            'emails_ligados' => $company->customizacao('welog_sends_emails'),
            'variaveis' => $this->catalogo($company, $projeto),
            'mensagens' => collect(EmailTemplate::JORNADA)->map(function (array $jornada, string $chave) use ($padroes, $daEmpresa, $doProjeto, $projeto, $definicoes, $desligadas) {
                $padrao = $padroes->get($chave);
                $empresa = $daEmpresa->get($chave);
                $proprio = $projeto === null ? $empresa : $doProjeto->get($chave);
                // O que vale um nível acima: é para ele que "restaurar" volta.
                $herdado = $projeto === null ? $padrao : ($empresa ?? $padrao);
                $atual = $proprio ?? $herdado;

                return [
                    'key' => $chave,
                    'etapa' => $jornada['etapa'],
                    'quando' => $jornada['quando'],
                    'botao' => self::BOTOES[$chave],
                    ...$this->campos($atual, $definicoes[$chave]),
                    'padrao' => $this->campos($herdado, $definicoes[$chave]),
                    'customized' => $proprio !== null,
                    // A chave da etapa vale para o Cliente inteiro, não por
                    // Projeto: é "esta empresa recebe este aviso", não o texto.
                    'ligada' => ! in_array($chave, $desligadas, true),
                    'herdado_de' => $projeto !== null && $empresa !== null ? 'texto da empresa' : 'padrão da WeGift',
                    'variaveis' => $definicoes[$chave]['variaveis'] ?? [],
                ];
            })->values(),
        ]);
    }

    public function update(Request $request, Company $company, string $key): JsonResponse
    {
        $this->autorizar($request->user(), $company);
        $this->garantirChaveDaJornada($key);
        $projeto = $this->projeto($request, $company);

        $dados = $this->validar($request);

        EmailTemplate::updateOrCreate(
            ['key' => $key, 'company_id' => $company->id, 'project_id' => $projeto?->id],
            $dados + ['name' => EmailTemplate::definitions()[$key]['name']],
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Liga ou desliga o envio de uma etapa da jornada, para o Cliente todo.
     *
     * O texto continua guardado: desligar não apaga a mensagem, e religar
     * volta a enviar exatamente o que estava escrito.
     */
    public function toggle(Request $request, Company $company, string $key): JsonResponse
    {
        $this->autorizar($request->user(), $company);
        $this->garantirChaveDaJornada($key);

        $ligada = $request->validate(['ligada' => ['required', 'boolean']])['ligada'];

        $desligadas = array_values(array_diff($company->etapasDesligadas(), [$key]));

        if (! $ligada) {
            $desligadas[] = $key;
        }

        $company->update([
            'customizations' => [...($company->customizations ?? []), 'etapas_desligadas' => $desligadas],
        ]);

        return response()->json(['ok' => true, 'ligada' => (bool) $ligada]);
    }

    /** Volta ao nível de cima apagando a personalização deste alcance. */
    public function restore(Request $request, Company $company, string $key): JsonResponse
    {
        $this->autorizar($request->user(), $company);
        $this->garantirChaveDaJornada($key);
        $projeto = $this->projeto($request, $company);

        EmailTemplate::where('key', $key)
            ->where('company_id', $company->id)
            ->where(fn ($q) => $projeto === null ? $q->whereNull('project_id') : $q->where('project_id', $projeto->id))
            ->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * "Enviar teste para mim": o texto que está NA TELA, ainda sem salvar,
     * para o e-mail de quem clicou, com os dados de exemplo.
     *
     * Sai na hora, não pela fila: quem pede um teste fica olhando a caixa de
     * entrada esperando por ele.
     */
    public function test(Request $request, Company $company, string $key): JsonResponse
    {
        $user = $request->user();
        $this->autorizar($user, $company);
        $this->garantirChaveDaJornada($key);
        $projeto = $this->projeto($request, $company);

        $dados = $this->validar($request);
        $exemplos = collect($this->catalogo($company, $projeto))->map(fn (array $v) => $v['exemplo'])->all();

        $email = EmailTemplate::compor($dados['subject'], $dados['title'], $dados['body'], $exemplos);

        Mail::to($user->email)->send(new MessageStudioTestMail(
            '[Teste] '.$email['subject'],
            $email['body'],
            self::BOTOES[$key],
        ));

        return response()->json(['ok' => true, 'email' => $user->email]);
    }

    /**
     * As variáveis com o exemplo que a pré-visualização mostra — tirado do
     * próprio Cliente sempre que dá: o nome da empresa, o presente da campanha,
     * o contato de atendimento dele. Ver a mensagem com os dados reais é o que
     * faz a pessoa confiar no que está escrevendo.
     *
     * @return array<string, array{label: string, exemplo: string}>
     */
    private function catalogo(Company $company, ?Project $projeto): array
    {
        $produto = ($projeto ?? $company->projects()->latest()->first())?->products()->value('products.name');
        $agora = now()->timezone('America/Sao_Paulo');

        // O nome de exemplo é de uma pessoa, não o de quem está editando:
        // "Olá, Admin WeGift!" ou "Olá, RH Sotreq!" confundia a prévia.
        $exemplos = [
            'produto' => $produto ?? EmailTemplate::VARIAVEIS['produto']['exemplo'],
            'empresa' => $company->label,
            'data' => $agora->format('d/m/Y'),
            'hora' => $agora->format('H:i'),
            'contato' => $company->emailDeAtendimento() ?? SupportContact::line(),
        ];

        $catalogo = [];

        foreach (EmailTemplate::VARIAVEIS as $token => $def) {
            $catalogo[$token] = ['label' => $def['label'], 'exemplo' => $exemplos[$token] ?? $def['exemplo'] ?? ''];
        }

        $catalogo['resumo_do_pedido']['exemplo'] = '**'.$catalogo['produto']['exemplo'].'**  '."\n"
            .'Entrega em: '.$catalogo['endereco']['exemplo'];

        return $catalogo;
    }

    /**
     * Assunto, título e corpo como o Estúdio edita.
     *
     * Linha antiga guarda o título como "# ..." na primeira linha do corpo:
     * aqui ele é separado, para a pessoa ver três campos simples em vez de
     * Markdown. Ao salvar, os dois vão para o lugar certo.
     *
     * @return array{subject: string, title: string, body: string}
     */
    private function campos(?EmailTemplate $modelo, array $definicao): array
    {
        $subject = $modelo->subject ?? $definicao['subject'];
        $title = $modelo !== null ? $modelo->title : ($definicao['title'] ?? null);
        $body = $modelo->body ?? $definicao['body'];

        if (blank($title) && preg_match('/\A#\s+(.+?)\R+(.*)\z/s', ltrim($body), $m)) {
            [$title, $body] = [trim($m[1]), $m[2]];
        }

        return [
            'subject' => EmailTemplate::amigavel($subject),
            'title' => EmailTemplate::amigavel((string) $title),
            'body' => EmailTemplate::amigavel(trim($body)),
        ];
    }

    /** @return array{subject: string, title: ?string, body: string} */
    private function validar(Request $request): array
    {
        $dados = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
        ], [], ['subject' => 'assunto', 'title' => 'título', 'body' => 'mensagem']);

        return ['subject' => $dados['subject'], 'title' => $dados['title'] ?? null, 'body' => $dados['body']];
    }

    /**
     * Admin WeGift, em qualquer Cliente; Admin Cliente e Admin de Projeto, só
     * na própria empresa. O id vem na URL — sem a trava, trocar o número
     * abriria a comunicação de outro Cliente.
     */
    private function autorizar(User $user, Company $company): void
    {
        $permitido = $user->role === User::ROLE_ADMIN_WEGIFT
            || ($user->isClientSideAdmin() && $user->company_id === $company->id);

        abort_unless($permitido, 403);
    }

    /** O Projeto do alcance (null = empresa toda), conferido contra a empresa e o perfil. */
    private function projeto(Request $request, Company $company): ?Project
    {
        $id = (int) $request->query('projeto', 0);

        if ($id <= 0) {
            return null;
        }

        $projeto = Project::where('company_id', $company->id)->find($id);

        abort_if($projeto === null, 404);
        abort_unless(
            $request->user()->role === User::ROLE_ADMIN_WEGIFT || $request->user()->managesProject($projeto),
            403,
        );

        return $projeto;
    }

    /** @return array<int, array{id: int, nome: string}> */
    private function projetosVisiveis(User $user, Company $company): array
    {
        return Project::where('company_id', $company->id)
            ->when($user->isProjectAdmin(), fn ($q) => $q->whereKey($user->admin_project_id))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Project $p) => ['id' => $p->id, 'nome' => $p->name])
            ->all();
    }

    /** `key` vem da URL: o Estúdio só edita a jornada do resgate. */
    private function garantirChaveDaJornada(string $key): void
    {
        abort_unless(array_key_exists($key, EmailTemplate::JORNADA), 404);
    }
}

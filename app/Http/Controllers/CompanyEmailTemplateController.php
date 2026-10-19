<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * As mensagens que saem em nome do cliente, na voz dele.
 *
 * O texto é de quem dá o presente, não da WeGift: "a Webox preparou um
 * presente para você" soa melhor escrito do jeito da Webox.
 *
 * Dois alcances vivem aqui: a empresa inteira e UMA campanha. A empresa era
 * recorte grosso demais para quem faz várias por ano — o Natal não fala como o
 * kit de boas-vindas, e um texto que sirva para os dois não serve para nenhum.
 *
 * Os níveis convivem em vez de se substituírem: o mais específico vence no
 * envio, e apagá-lo devolve o de cima intacto. Por isso "restaurar" é DELETE,
 * e não copiar o texto de cima — texto copiado congela no dia da cópia e deixa
 * de acompanhar as melhorias do nível acima.
 */
class CompanyEmailTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        $company = $this->company($request);
        $project = $this->project($request, $company);
        $definitions = EmailTemplate::definitions();

        // Na ordem da jornada, não por id: as chaves que nasceram depois
        // (expedido, entregue) apareciam antes de "Presente liberado".
        $padroes = EmailTemplate::whereNull('company_id')->whereNull('project_id')->get()
            ->sortBy(fn (EmailTemplate $t) => EmailTemplate::ordem($t->key))
            ->values();
        $daEmpresa = EmailTemplate::where('company_id', $company->id)->whereNull('project_id')->get()->keyBy('key');
        $doProjeto = $project === null
            ? collect()
            : EmailTemplate::where('project_id', $project->id)->get()->keyBy('key');

        return Inertia::render('Company/EmailTemplates', [
            'company' => ['id' => $company->id, 'name' => $company->label],
            // O alcance escolhido e os Projetos disponíveis para escolher.
            'escopo' => $project === null
                ? ['nivel' => 'empresa', 'project_id' => null, 'rotulo' => $company->label]
                : ['nivel' => 'projeto', 'project_id' => $project->id, 'rotulo' => $project->name],
            'projetos' => $this->projetosVisiveis($request, $company),
            'templates' => $padroes->map(function (EmailTemplate $padrao) use ($daEmpresa, $doProjeto, $definitions, $project) {
                $empresa = $daEmpresa->get($padrao->key);
                $projeto = $doProjeto->get($padrao->key);

                // O que vale UM NÍVEL ACIMA do alcance aberto: é com ele que a
                // tela compara, e é para ele que "restaurar" volta.
                $herdado = $project === null ? $padrao : ($empresa ?? $padrao);
                $atual = $project === null ? ($empresa ?? $padrao) : ($projeto ?? $herdado);

                return [
                    'key' => $padrao->key,
                    'name' => $padrao->name,
                    'audience' => $definitions[$padrao->key]['audience'] ?? null,
                    // O que está valendo hoje neste alcance.
                    'subject' => $atual->subject,
                    'body' => $atual->body,
                    // E o texto herdado ao lado, para comparar antes de trocar.
                    'default_subject' => $herdado->subject,
                    'default_body' => $herdado->body,
                    'inherited_from' => $project === null
                        ? 'padrão da WeGift'
                        : ($empresa !== null ? 'texto da empresa' : 'padrão da WeGift'),
                    'customized' => $project === null ? $empresa !== null : $projeto !== null,
                    'variables' => collect($definitions[$padrao->key]['variables'] ?? [])
                        ->map(fn ($desc, $name) => ['name' => $name, 'description' => $desc])
                        ->values(),
                ];
            }),
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        $company = $this->company($request);
        $project = $this->project($request, $company);

        $this->garantirChaveConhecida($key);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
        ], [], ['subject' => 'assunto', 'body' => 'mensagem']);

        EmailTemplate::updateOrCreate(
            ['key' => $key, 'company_id' => $company->id, 'project_id' => $project?->id],
            $data + ['name' => EmailTemplate::definitions()[$key]['name'] ?? $key],
        );

        return back()->with('status', $project === null
            ? 'Mensagem salva para toda a empresa. Ela passa a valer nos próximos envios.'
            : 'Mensagem salva para o Projeto '.$project->name.'.');
    }

    /** Volta ao nível de cima apagando a personalização deste alcance. */
    public function restore(Request $request, string $key): RedirectResponse
    {
        $company = $this->company($request);
        $project = $this->project($request, $company);

        $this->garantirChaveConhecida($key);

        EmailTemplate::where('key', $key)
            ->where('company_id', $company->id)
            ->where(fn ($q) => $project === null
                ? $q->whereNull('project_id')
                : $q->where('project_id', $project->id))
            ->delete();

        return back()->with('status', $project === null
            ? 'Mensagem restaurada ao padrão da WeGift.'
            : 'Mensagem do Projeto removida. Volta a valer o texto da empresa.');
    }

    /**
     * A empresa é SEMPRE a de quem está logado.
     *
     * Nunca vem por parâmetro: com o id na URL, um admin de um cliente
     * editaria a comunicação de outro trocando um número. A WeGift edita os
     * padrões na própria tela, e não passa por aqui.
     */
    private function company(Request $request): Company
    {
        $company = $request->user()->company;

        abort_if($company === null, 403);

        return $company;
    }

    /**
     * O Projeto do alcance aberto, ou null para "toda a empresa".
     *
     * Vem da querystring, e por isso é conferido contra a empresa de quem está
     * logado: sem a trava, trocar o número na URL abriria a comunicação da
     * campanha de outro cliente. O Admin de Projeto só alcança o dele.
     */
    private function project(Request $request, Company $company): ?Project
    {
        $id = (int) $request->query('projeto', 0);

        if ($id <= 0) {
            return null;
        }

        $project = Project::where('company_id', $company->id)->find($id);

        abort_if($project === null, 404);
        abort_unless($request->user()->managesProject($project), 403);

        return $project;
    }

    /**
     * Os Projetos que quem está logado pode personalizar: todos os da empresa
     * para o Admin Cliente, só o dele para o Admin de Projeto.
     *
     * @return array<int, array{id: int, nome: string}>
     */
    private function projetosVisiveis(Request $request, Company $company): array
    {
        return Project::where('company_id', $company->id)
            ->when(
                $request->user()->isProjectAdmin(),
                fn ($q) => $q->whereKey($request->user()->admin_project_id),
            )
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Project $p) => ['id' => $p->id, 'nome' => $p->name])
            ->all();
    }

    /** `key` vem da URL: sem isto, qualquer texto viraria um modelo novo. */
    private function garantirChaveConhecida(string $key): void
    {
        abort_unless(array_key_exists($key, EmailTemplate::definitions()), 404);
    }
}

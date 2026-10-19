<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Modelos de e-mail (Módulo 11) — a visão da WeGift, que alcança os três níveis.
 *
 *   Geral    a voz da WeGift, e o piso de todo envio
 *   Cliente  a voz de uma empresa, em todos os Projetos dela
 *   Projeto  a voz de UMA campanha
 *
 * O envio usa o mais específico que existir. Aqui a WeGift edita qualquer um
 * dos três; o cliente edita os dois de baixo na tela dele. Uma tela só para os
 * três porque a pergunta é sempre a mesma — "o que esta mensagem diz?" —, e o
 * que muda é para quem ela vale.
 *
 * "Restaurar" apaga a personalização e devolve o nível de cima, em vez de
 * copiar o texto dele: texto copiado congela no dia da cópia e para de
 * acompanhar as melhorias de cima. No nível Geral, onde não há de cima, ele
 * volta ao conteúdo de fábrica da definição.
 */
class EmailTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        [$company, $project] = $this->escopo($request);

        $definitions = EmailTemplate::definitions();

        // Na ordem da jornada, não por id: as chaves que nasceram depois
        // (expedido, entregue) apareciam antes de "Presente liberado".
        $padroes = EmailTemplate::whereNull('company_id')->whereNull('project_id')->get()
            ->sortBy(fn (EmailTemplate $t) => EmailTemplate::ordem($t->key))
            ->values();
        $daEmpresa = $company === null
            ? collect()
            : EmailTemplate::where('company_id', $company->id)->whereNull('project_id')->get()->keyBy('key');
        $doProjeto = $project === null
            ? collect()
            : EmailTemplate::where('project_id', $project->id)->get()->keyBy('key');

        return Inertia::render('Admin/EmailTemplates/Index', [
            'escopo' => [
                'nivel' => $project !== null ? 'projeto' : ($company !== null ? 'empresa' : 'geral'),
                'company_id' => $company?->id,
                'project_id' => $project?->id,
                'rotulo' => $project?->name ?? $company?->label ?? 'Geral (todos os clientes)',
            ],
            'empresas' => Company::orderBy('name')
                ->get(['id', 'name', 'display_name'])
                ->map(fn (Company $c) => ['id' => $c->id, 'nome' => $c->label])
                ->all(),
            // Só os Projetos da empresa escolhida: a lista inteira seria
            // centenas de nomes que não têm nada a ver com o recorte aberto.
            'projetos' => $company === null ? [] : Project::where('company_id', $company->id)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Project $p) => ['id' => $p->id, 'nome' => $p->name])
                ->all(),
            'templates' => $padroes->map(function (EmailTemplate $padrao) use ($daEmpresa, $doProjeto, $definitions, $company, $project) {
                $empresa = $daEmpresa->get($padrao->key);
                $projeto = $doProjeto->get($padrao->key);
                $definicao = $definitions[$padrao->key] ?? [];

                // O texto de um nível acima do alcance aberto: é com ele que a
                // tela compara, e é para ele que "restaurar" volta.
                [$atual, $herdado, $personalizado, $origem] = match (true) {
                    $project !== null => [
                        $projeto ?? $empresa ?? $padrao,
                        $empresa ?? $padrao,
                        $projeto !== null,
                        $empresa !== null ? 'texto do cliente' : 'padrão geral',
                    ],
                    $company !== null => [
                        $empresa ?? $padrao,
                        $padrao,
                        $empresa !== null,
                        'padrão geral',
                    ],
                    default => [$padrao, $padrao, false, 'conteúdo de fábrica'],
                };

                return [
                    'key' => $padrao->key,
                    'name' => $padrao->name,
                    'audience' => $definicao['audience'] ?? null,
                    'subject' => $atual->subject,
                    'body' => $atual->body,
                    'default_subject' => $herdado->subject,
                    'default_body' => $herdado->body,
                    'inherited_from' => $origem,
                    'customized' => $personalizado,
                    // No nível Geral não há herança: "restaurar" volta ao
                    // conteúdo de fábrica, e só faz sentido se o texto mudou.
                    'differs_from_factory' => $company === null && (
                        $padrao->subject !== ($definicao['subject'] ?? null)
                        || $padrao->body !== ($definicao['body'] ?? null)
                    ),
                    'variables' => collect($definicao['variables'] ?? [])
                        ->map(fn ($desc, $name) => ['name' => $name, 'description' => $desc])
                        ->values(),
                ];
            }),
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        [$company, $project] = $this->escopo($request);

        $this->garantirChaveConhecida($key);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
        ], [], ['subject' => 'assunto', 'body' => 'mensagem']);

        EmailTemplate::updateOrCreate(
            ['key' => $key, 'company_id' => $company?->id, 'project_id' => $project?->id],
            $data + ['name' => EmailTemplate::definitions()[$key]['name'] ?? $key],
        );

        return back()->with('status', match (true) {
            $project !== null => 'Mensagem salva para o Projeto '.$project->name.'.',
            $company !== null => 'Mensagem salva para '.$company->label.'.',
            default => 'Modelo padrão salvo. Vale para todos os clientes sem personalização.',
        });
    }

    /** Apaga a personalização deste alcance e devolve o nível de cima. */
    public function restore(Request $request, string $key): RedirectResponse
    {
        [$company, $project] = $this->escopo($request);

        $this->garantirChaveConhecida($key);

        // Nível Geral não tem de onde herdar: volta ao conteúdo de fábrica.
        if ($company === null) {
            $definicao = EmailTemplate::definitions()[$key];

            EmailTemplate::where('key', $key)->whereNull('company_id')->whereNull('project_id')->update([
                'subject' => $definicao['subject'],
                'body' => $definicao['body'],
            ]);

            return back()->with('status', 'Modelo restaurado ao conteúdo padrão.');
        }

        EmailTemplate::where('key', $key)
            ->where('company_id', $company->id)
            ->where(fn ($q) => $project === null
                ? $q->whereNull('project_id')
                : $q->where('project_id', $project->id))
            ->delete();

        return back()->with('status', $project === null
            ? 'Personalização de '.$company->label.' removida. Volta a valer o padrão geral.'
            : 'Personalização do Projeto removida. Volta a valer o texto do cliente.');
    }

    /**
     * O alcance aberto, vindo da querystring.
     *
     * O Projeto manda na empresa: recebido sozinho, ele diz de quem é. Assim
     * um link para a campanha continua abrindo no lugar certo mesmo sem o
     * `empresa` na URL.
     *
     * @return array{0: ?Company, 1: ?Project}
     */
    private function escopo(Request $request): array
    {
        $projectId = (int) $request->query('projeto', 0);

        if ($projectId > 0) {
            $project = Project::findOrFail($projectId);

            return [$project->company, $project];
        }

        $companyId = (int) $request->query('empresa', 0);

        return [$companyId > 0 ? Company::findOrFail($companyId) : null, null];
    }

    /** `key` vem da URL: sem isto, qualquer texto viraria um modelo novo. */
    private function garantirChaveConhecida(string $key): void
    {
        abort_unless(array_key_exists($key, EmailTemplate::definitions()), 404);
    }
}

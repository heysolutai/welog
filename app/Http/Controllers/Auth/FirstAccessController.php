<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ProjectEntitlement;
use App\Models\User;
use App\Services\Auth\InviteUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A porta de entrada que o Cliente publica na intranet dele.
 *
 * O colaborador chega aqui pelo canal interno da própria empresa, digita o
 * e-mail corporativo e recebe o link de criar senha. É o caminho para o
 * Cliente grande que não quer a WeLog escrevendo para nove mil pessoas — nem
 * pelo custo de imagem, nem porque e-mail de fornecedor desconhecido pedindo
 * senha é indistinguível de golpe para quem recebe.
 *
 * O token da URL diz DE QUEM é a tela. Sem ele, um endereço só seria porta
 * aberta para a base inteira: quem tivesse o link de um Cliente dispararia
 * convite para colaborador de qualquer outro.
 */
class FirstAccessController extends Controller
{
    public function form(string $token): Response|RedirectResponse
    {
        $empresa = $this->empresaAtual($token);

        // Endereço antigo, ainda publicado na intranet: leva ao atual.
        if ($empresa === null) {
            $antiga = Company::porSlugAntigoDePrimeiroAcesso($token) ?? abort(404);

            return redirect()->route('primeiro-acesso.form', $antiga->slugDePrimeiroAcesso(), 301);
        }

        return Inertia::render('Auth/FirstAccess', [
            'token' => $token,
            'company' => [
                'name' => $empresa->display_name ?: $empresa->name,
                // O "Não achou?" do correio deste Cliente; null = o padrão.
                'dica' => $empresa->dicaDePrimeiroAcesso(),
            ],
        ]);
    }

    public function send(Request $request, string $token, InviteUser $convite): RedirectResponse
    {
        $empresa = $this->empresaDoToken($token);

        $request->validate(['email' => ['required', 'email', 'max:255']]);

        /*
         * Normalizar antes de procurar, sempre.
         *
         * Com nove mil pessoas, alguém digita com maiúscula, alguém cola com
         * espaço no fim, alguém tem o teclado do celular capitalizando a
         * primeira letra sozinho. Comparando o valor cru, essas pessoas veem
         * "não encontrado" olhando para o próprio e-mail correto, e a conta
         * disso chega inteira no suporte.
         */
        $email = mb_strtolower(trim((string) $request->input('email')));

        /*
         * O link só vale para e-mail cadastrado, e a tela diz isso com todas
         * as letras.
         *
         * A resposta já foi a mesma para todo mundo, para não revelar quem
         * trabalha lá. O preço era alto: quem digitava o e-mail errado lia
         * "Pronto" e ficava esperando um e-mail que nunca vinha. O que segura
         * a varredura agora é o limite por e-mail e por origem da rota
         * (`throttle:primeiro-acesso`).
         *
         * As condições continuam cumulativas:
         *  - é colaborador ativo DESTA empresa: o token é o escopo, e operador
         *    ou Admin Cliente entram pelo caminho deles;
         *  - ainda não definiu senha: reenviar convite a quem já tem seria um
         *    jeito de resetar a conta alheia sem acesso à caixa dela;
         *  - tem autorização em Projeto liberado: convite antes da liberação
         *    leva a pessoa a uma tela sem nada para resgatar.
         */
        $pessoa = $this->colaboradorDaEmpresa($empresa, $email);

        if ($pessoa === null) {
            return back()->withErrors([
                'email' => 'Este e-mail não está cadastrado na campanha. Use o e-mail que a sua empresa cadastrou.',
            ]);
        }

        if ($pessoa->hasSetPassword()) {
            return back()->withErrors([
                'email' => 'Este e-mail já tem senha. Entre pelo login ou use "Esqueci minha senha".',
            ]);
        }

        if (! $this->temProjetoLiberado($pessoa)) {
            return back()->withErrors([
                'email' => 'A campanha deste e-mail ainda não foi liberada. Você vai poder criar sua senha quando ela for liberada.',
            ]);
        }

        // O token do link (um bcrypt, 0,2 a 0,4s) é gerado na fila de e-mail,
        // não aqui: quando o Cliente divulga o link, centenas de pessoas pedem
        // no mesmo minuto, e calculado na requisição ele enfileirava todo
        // mundo atrás do processador.
        $convite->handleLater($pessoa);

        return back()->with('status', "Enviamos para {$email} o link para criar sua senha.");
    }

    /** Token inválido é 404, não uma tela dizendo "link errado". */
    /**
     * O Cliente da porta: pelo nome no endereço (/primeiro-acesso/sotreq) ou
     * pelo código longo de antes, que continua valendo para o link que já
     * estava publicado.
     */
    /** O Cliente pelo endereço de agora (ou pelo token antigo, longo). */
    private function empresaAtual(string $token): ?Company
    {
        return Company::where('first_access_slug', mb_strtolower($token))
            ->when(mb_strlen($token) >= 20, fn ($q) => $q->orWhere('first_access_token', $token))
            ->first();
    }

    private function empresaDoToken(string $token): Company
    {
        return $this->empresaAtual($token)
            ?? Company::porSlugAntigoDePrimeiroAcesso($token)
            ?? abort(404);
    }

    private function colaboradorDaEmpresa(Company $empresa, string $email): ?User
    {
        if ($email === '') {
            return null;
        }

        return User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('company_id', $empresa->id)
            ->where('role', User::ROLE_END_CUSTOMER)
            ->where('active', true)
            ->first();
    }

    private function temProjetoLiberado(User $pessoa): bool
    {
        return ProjectEntitlement::query()
            ->join('projects', 'projects.id', '=', 'project_entitlements.project_id')
            ->where('project_entitlements.user_id', $pessoa->id)
            ->whereNotNull('projects.released_at')
            ->exists();
    }
}

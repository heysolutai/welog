<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Models\Project;
use App\Rules\ValorValido;
use App\Services\FluxoA\RequestKits;
use App\Support\Values\Cpf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Solicitação de kits pelo admin (inclusão UAT): N unidades de um item do
 * pedido para um destinatário, sem o limite de "um por pessoa" do portal.
 *
 * Só a WeGift crítica: a rota mora no grupo `role:critical`, e a regra de
 * negócio vive no RequestKits.
 */
class ProjectRequestController extends Controller
{
    public function store(Project $project, Request $request, RequestKits $requestKits): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->canPerformCriticalActions(), 403);

        // A WeGift abre a solicitação com o que chegou por telefone ou e-mail,
        // e o CPF costuma vir depois, junto da nota. Para quem administra o
        // cliente, o CPF só é opcional quando o Cliente não coleta CPF
        // (coluna desmarcada nas Customizações).
        $cpfObrigatorio = RequestKits::cpfOpcional($project, $user) ? 'nullable' : 'required';

        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'cpf' => [$cpfObrigatorio, 'string', new ValorValido(Cpf::class)],
            'zip' => ['required', 'string'],
            'street' => ['required', 'string', 'max:255'],
            'number' => ['required', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:255'],
            'district' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['required', 'string', 'size:2'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], [
            'product_id' => 'item do pedido',
            'quantity' => 'quantidade',
            'recipient_name' => 'nome do destinatário',
            'notes' => 'observações',
        ]);

        try {
            $redemption = $requestKits->handle($project, $data, $user);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['request' => $e->getMessage()]);
        }

        Log::info('Solicitação de kits registrada.', [
            'project_id' => $project->id,
            'redemption_id' => $redemption->id,
            'product_id' => $redemption->product_id,
            'quantity' => $redemption->quantity,
            'requested_by' => $user->id,
        ]);

        return back()->with('status', 'Solicitação registrada: '.$redemption->quantity
            .' unidade(s) de '.$redemption->product->name.'.');
    }
}

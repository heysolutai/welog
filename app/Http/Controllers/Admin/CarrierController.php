<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Carrier;
use App\Models\Redemption;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tabela de parametrização das transportadoras, mantida pelo Admin WeGift.
 *
 * O que se cadastra aqui é o nome e, principalmente, COMO cada uma entrega o
 * rastreio: um código, que a WeLog transforma em link pelo molde, ou um link
 * pronto. Sem essa escolha, o campo do drawer de expedição era sempre "Código
 * de rastreio" e o operador colava uma URL nele — que ia para o e-mail do
 * presenteado escrita como se fosse um código.
 */
class CarrierController extends Controller
{
    public function index(): Response
    {
        // Uma consulta para todas: a contagem por transportadora dentro do
        // `map` seria uma query por linha da tabela.
        $usos = Redemption::selectRaw('carrier, count(*) as total')
            ->whereNotNull('carrier')
            ->groupBy('carrier')
            ->pluck('total', 'carrier');

        return Inertia::render('Admin/Carriers/Index', [
            'carriers' => Carrier::orderBy('name')->get()->map(fn (Carrier $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'tracking_field' => $c->tracking_field,
                'tracking_url_template' => $c->tracking_url_template,
                'active' => $c->active,
                // Integração própria: a tela explica por que esta não se apaga
                // e por que o código pode nascer sozinho.
                'integrated' => $c->isCorreios(),
                'shipments' => (int) ($usos[$c->slug] ?? 0),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $carrier = Carrier::create($data + ['slug' => Carrier::slugFor($data['name'])]);

        return back()->with('status', 'Transportadora '.$carrier->name.' cadastrada.');
    }

    public function update(Carrier $carrier, Request $request): RedirectResponse
    {
        // O slug fica fora do `validated`: é a identidade que os resgates
        // guardam, e trocá-la apagaria a transportadora do histórico deles.
        $carrier->update($this->validated($request, $carrier));

        return back()->with('status', 'Transportadora '.$carrier->name.' atualizada.');
    }

    /**
     * Apagar só enquanto ninguém a usou.
     *
     * Depois da primeira expedição, o resgate guarda o slug e mais nada: sem a
     * linha, a tela do presenteado deixa de saber por quem o kit foi. Nesse
     * caso o caminho é desativar — some da expedição, continua no histórico.
     */
    public function destroy(Carrier $carrier): RedirectResponse
    {
        if ($carrier->isCorreios()) {
            return back()->withErrors([
                'carrier' => 'Os Correios têm integração própria (rastreio automático) e não podem ser removidos. Desative-os se não estiverem em uso.',
            ]);
        }

        $usos = $this->shipments($carrier);

        if ($usos > 0) {
            return back()->withErrors([
                'carrier' => 'Esta transportadora já expediu '.$usos.($usos === 1 ? ' resgate' : ' resgates')
                    .' e não pode ser removida, porque o histórico deles deixaria de dizer por quem foram. Desative-a.',
            ]);
        }

        $nome = $carrier->name;
        $carrier->delete();

        return back()->with('status', 'Transportadora '.$nome.' removida.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Carrier $carrier = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('carriers', 'name')->ignore($carrier?->id)],
            'tracking_field' => ['required', Rule::in(Carrier::FIELDS)],
            /*
             * Molde só faz sentido para quem entrega código; quem entrega link
             * não tem código para encaixar nele.
             *
             * Sem a regra `url`: o molde tem `{codigo}` no meio, e as chaves
             * fazem o `FILTER_VALIDATE_URL` do PHP recusar o endereço inteiro
             * — o campo ficaria impossível de preencher corretamente.
             */
            'tracking_url_template' => ['nullable', 'string', 'max:255', 'regex:/^https?:\/\/\S*\{codigo\}\S*$/'],
            'active' => ['boolean'],
        ], [
            'tracking_url_template.regex' => 'O link precisa começar com http:// ou https:// e conter {codigo}, '
                .'que é onde o código de rastreio entra.',
        ], [
            'name' => 'nome',
            'tracking_field' => 'campo de rastreio',
            'tracking_url_template' => 'link de rastreio',
        ]);

        if (($data['tracking_field'] ?? null) === Carrier::FIELD_LINK) {
            $data['tracking_url_template'] = null;
        }

        $data['active'] = $request->boolean('active', true);

        return $data;
    }

    private function shipments(Carrier $carrier): int
    {
        return Redemption::where('carrier', $carrier->slug)->count();
    }
}

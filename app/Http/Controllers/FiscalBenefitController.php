<?php

namespace App\Http\Controllers;

use App\Models\FiscalBenefit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tabela de parametrização do benefício fiscal (Fluxo B) — mantida pelo
 * Admin WeGift.
 */
class FiscalBenefitController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Benefits/Index', [
            'benefits' => FiscalBenefit::orderBy('product_code')
                ->get(['id', 'product_code', 'benefit_code', 'description']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_code' => ['required', 'string', 'max:60'],
            'benefit_code' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        FiscalBenefit::updateOrCreate(
            ['product_code' => $data['product_code']],
            ['benefit_code' => $data['benefit_code'], 'description' => $data['description'] ?? null],
        );

        return back()->with('status', 'Parametrização salva.');
    }

    public function destroy(FiscalBenefit $benefit): RedirectResponse
    {
        $benefit->delete();

        return back()->with('status', 'Parametrização removida.');
    }
}

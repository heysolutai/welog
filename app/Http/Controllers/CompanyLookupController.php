<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sugestões de Empresa Cliente por trecho de nome ou CNPJ.
 *
 * Alimenta o autocomplete do cadastro manual de Projeto: quem digita
 * "acme" ou "12345" vê as empresas que batem sem precisar saber o CNPJ
 * inteiro de cabeça. Poucos resultados por chamada, é sugestão e não listagem.
 */
class CompanyLookupController extends Controller
{
    private const LIMIT = 8;

    public function __invoke(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        $digits = preg_replace('/\D/', '', $term);
        // LOWER dos dois lados: no Postgres o LIKE é sensível a maiúsculas.
        $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';

        $companies = Company::query()
            // A tela precisa saber se a empresa já tem responsável: sem admin,
            // o cadastro manual de Projeto pede um antes de concluir.
            ->withCount('admins')
            ->where(function ($q) use ($like, $digits) {
                $q->whereRaw('LOWER(name) LIKE ?', [$like]);

                // Só busca por CNPJ se a pessoa digitou algum número: "acme"
                // vira dígitos vazios e não pode virar `LIKE '%%'`.
                if ($digits !== '') {
                    $q->orWhere('cnpj', 'like', '%'.$digits.'%');
                }
            })
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();

        return response()->json($companies->map(fn (Company $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'cnpj' => $c->cnpj,
            // Nome provisório é o CNPJ no lugar da razão social, deixado pelo
            // sync quando o ERP não devolveu o nome. A tela não pode copiá-lo
            // para o campo de razão social: precisa pedir o nome de verdade.
            'nome_provisorio' => $c->temNomeProvisorio(),
            // O endereço volta junto para a ficha abrir preenchida, em vez de
            // obrigar a redigitar o que a empresa já tem.
            'zip' => $c->zip,
            'street' => $c->street,
            'number' => $c->number,
            'complement' => $c->complement,
            'district' => $c->district,
            'city' => $c->city,
            'state' => $c->state,
            'pendencias' => $c->pendenciasDeCadastro(),
            'has_admin' => $c->admins_count > 0,
        ]));
    }
}

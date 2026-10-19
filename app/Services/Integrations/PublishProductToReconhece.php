<?php

namespace App\Services\Integrations;

use App\Contracts\Integrations\ReconheceGateway;
use App\Exceptions\DomainException;
use App\Models\PartnerSku;
use App\Models\Product;
use App\Models\User;

/**
 * Publica um produto da Omie na vitrine da Reconhece.
 *
 * O item publicado já nasce vinculado ao produto que o expede — e é isso que
 * fecha o ciclo. O colaborador escolhe na vitrine deles, o resgate volta com o
 * código de lá, e o vínculo diz qual kit tirar do estoque. Publicar sem
 * registrar o vínculo criaria na origem o mesmo bloqueio que a tela de
 * Catálogo do Parceiro existe para resolver.
 */
class PublishProductToReconhece
{
    public function __construct(private readonly ReconheceGateway $reconhece) {}

    /**
     * @param  string  $productType  categoria de nível 1 (pai), exigida por eles
     * @param  string  $department  categoria de nível 2 (filha), exigida por eles
     *
     * @throws DomainException quando falta dado que a Reconhece exige
     */
    public function handle(Product $produto, string $productType, string $department, ?User $actor = null): PartnerSku
    {
        $this->conferirCadastro($produto, $productType, $department);

        /*
         * Produto avulso: `parentCode` igual ao `sku`.
         *
         * É como o contrato deles descreve item sem variação, e é o que o
         * catálogo da Omie é hoje — a cor vive dentro do nome ("Moletom com
         * Capuz Preto"), não em campo próprio. Inventar um produto-pai aqui
         * criaria um agrupamento que ninguém pediu e que a Omie não sustenta.
         */
        $payload = [
            'sku' => $produto->omie_code,
            'enabled' => (bool) $produto->active,
            'product' => [
                'parentCode' => $produto->omie_code,
                'name' => $produto->name,
                'description' => $produto->description,
                'productType' => trim($productType),
                'department' => trim($department),
            ],
        ];

        // A galeria inteira, na ordem: a primeira é a capa na vitrine.
        if (($fotos = $produto->fotos()) !== []) {
            $payload['images'] = $fotos;
        }

        $resposta = $this->reconhece->upsertSku($payload);

        if (! ($resposta['accepted'] ?? false)) {
            throw new DomainException(
                $resposta['message'] ?? 'A Reconhece recusou o envio, sem informar o motivo.',
            );
        }

        return $this->registrar($produto, $payload, $actor);
    }

    /**
     * O que a Reconhece exige e a Omie não garante.
     *
     * Conferir ANTES da chamada existe para o operador ler "falta a descrição
     * do produto" em vez de um erro da API do parceiro. A descrição é o caso
     * concreto: lá é obrigatória, aqui a coluna é nula, e produto da Omie sem
     * descrição é rotina.
     */
    private function conferirCadastro(Product $produto, string $productType, string $department): void
    {
        $faltando = [];

        if (blank($produto->omie_code)) {
            $faltando[] = 'código na Omie';
        }

        if (blank($produto->description)) {
            $faltando[] = 'descrição (obrigatória na Reconhece)';
        }

        if (blank(trim($productType))) {
            $faltando[] = 'categoria';
        }

        if (blank(trim($department))) {
            $faltando[] = 'departamento';
        }

        if ($faltando !== []) {
            throw new DomainException(
                'Não dá para publicar "'.$produto->name.'": falta '.implode(', ', $faltando).'.',
            );
        }
    }

    /**
     * Grava o item no catálogo do parceiro, JÁ vinculado.
     *
     * Vínculo manual anterior é preservado: se alguém amarrou este SKU a outro
     * produto, foi por um motivo que a publicação não conhece.
     */
    private function registrar(Product $produto, array $payload, ?User $actor): PartnerSku
    {
        $registro = PartnerSku::firstOrNew([
            'source' => PartnerSku::SOURCE_RECONHECE,
            'sku' => $produto->omie_code,
        ]);

        $registro->fill([
            'enabled' => $payload['enabled'],
            'parent_code' => $payload['product']['parentCode'],
            'name' => $payload['product']['name'],
            'description' => $payload['product']['description'],
            'product_type' => $payload['product']['productType'],
            'department' => $payload['product']['department'],
            'images' => $payload['images'] ?? null,
            'payload' => $payload,
        ]);

        if ($registro->product_id === null) {
            $registro->product_id = $produto->id;
            $registro->linked_at = now();
            $registro->linked_by = $actor?->id;
        }

        $registro->save();

        return $registro;
    }
}

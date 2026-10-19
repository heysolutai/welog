<?php

namespace App\Services\FluxoB;

use App\Contracts\Integrations\OmieGateway;
use App\Contracts\Integrations\TrelloGateway;
use App\Models\AppnozImportRow;
use App\Models\FiscalBenefit;
use App\Models\PartnerSku;
use App\Models\Project;
use App\Models\TrelloListMapping;

/**
 * Fluxo B, coração do módulo 3 — para cada linha válida:
 *  1. cliente na Omie: ausente → cadastra automaticamente (NÃO bloqueia);
 *  2. produto na Omie: ausente → BLOQUEIA (única condição bloqueante);
 *  3. benefício fiscal pela tabela de parametrização;
 *  4. Pedido de Venda na Omie marcado "Importação" (idempotente);
 *  5. card no Trello restrito aos estágios finais (entra em Montagem).
 */
class ProcessAppnozRow
{
    public function __construct(
        private readonly OmieGateway $omie,
        private readonly TrelloGateway $trello,
        private readonly DetectCustomerDivergences $divergences,
    ) {}

    public function handle(AppnozImportRow $row): AppnozImportRow
    {
        /*
         * O código que chega é o do PARCEIRO, e nem sempre é o da Omie.
         *
         * A Reconhece cadastra o item na vitrine dela com o código dela, e é
         * esse que volta no resgate. O vínculo em `partner_skus` é a tradução;
         * sem passar por ele, todo resgate de catálogo com código próprio
         * travaria em "produto não cadastrado" — e o operador procuraria na
         * Omie um código que nunca existiu lá.
         */
        $codigoOmie = $this->codigoNaOmie($row->product_code);

        // 2. Produto não cadastrado é a única condição bloqueante do fluxo
        $product = $this->omie->findProductByCode($codigoOmie);

        if ($product === null) {
            $row->update([
                'status' => AppnozImportRow::STATUS_BLOCKED_PRODUCT,
                'block_reason' => $this->motivoDoBloqueio($row->product_code, $codigoOmie),
            ]);

            return $row;
        }

        // 1. Cliente: busca por CPF; ausente → auto-cadastro sem interromper
        $customer = $this->omie->findCustomerByDocument($row->cpf);
        $autoCreated = false;
        // Cliente que já existe pode estar diferente do que o parceiro mandou.
        // Vira alerta, não bloqueio: o pedido segue e o operador decide depois
        // qual dos dois lados vale.
        $divergences = $customer === null ? [] : $this->divergences->handle($row, $customer);

        if ($customer === null) {
            $customer = $this->omie->upsertCustomer([
                // A Omie exige uma chave no UpsertCliente, e cliente novo ainda
                // não tem código Omie. O CPF é a chave de integração natural:
                // única por pessoa e a mesma em todo reprocesso, então o
                // upsert atualiza em vez de duplicar o cadastro.
                'codigo_cliente_integracao' => $row->cpf,
                'cnpj_cpf' => $row->cpf,
                'razao_social' => $row->customer_name,
                'email' => $row->email,
                'telefone1_numero' => $row->phone,
                'endereco' => $row->street,
                'endereco_numero' => $row->number,
                'complemento' => $row->complement,
                'bairro' => $row->district,
                'cidade' => $row->city,
                'estado' => $row->state,
                'cep' => $row->zip,
            ]);
            $autoCreated = true;
        }

        // 3. Benefício fiscal parametrizado na WeLog
        $benefitCode = FiscalBenefit::codeForProduct($codigoOmie);

        // 4. Pedido de Venda marcado como "Importação" (idempotente por chave)
        $order = $this->omie->createOrder([
            'codigo_pedido_integracao' => $row->omieIntegrationKey(),
            'codigo_cliente' => $customer['omie_customer_code'],
            'etapa' => '10',
            'marcador' => 'Importação',
            'observacao' => trim('Importação Parceiros · resgate '.($row->external_id ?? $row->id)),
            'det' => [[
                'produto' => [
                    'codigo' => $codigoOmie,
                    // A Omie identifica o item pelo ID interno e exige o valor;
                    // os dois vêm da consulta do produto feita logo acima.
                    'codigo_produto' => $product['codigo_produto'] ?? null,
                    'valor_unitario' => $product['valor_unitario'] ?? null,
                    'quantidade' => $row->quantity,
                    'cbenef' => $benefitCode,
                ],
            ]],
        ]);

        // 5. Card no Trello direto nos estágios finais (sem produção)
        $trelloCardId = $row->trello_card_id;
        if ($trelloCardId === null) {
            $listId = TrelloListMapping::where('pipeline_status', Project::STATUS_ASSEMBLY)
                ->value('trello_list_id');

            if ($listId !== null) {
                $card = $this->trello->createCard(
                    $listId,
                    "Suvinil {$order['omie_order_number']} - {$row->customer_name}",
                    "Resgate Importações Parceiros (appnoz)\nCPF: {$row->cpf}\nProduto: {$row->product_name} ({$row->product_code})\nQtde: {$row->quantity}",
                );
                $trelloCardId = $card['id'];
            }
        }

        $row->update([
            'status' => AppnozImportRow::STATUS_ORDER_CREATED,
            'block_reason' => null,
            'benefit_code' => $benefitCode,
            'omie_customer_code' => $customer['omie_customer_code'],
            'customer_auto_created' => $autoCreated,
            'customer_divergences' => $divergences ?: null,
            'omie_order_id' => $order['omie_order_id'],
            'omie_order_number' => $order['omie_order_number'],
            'trello_card_id' => $trelloCardId,
            'trello_stage' => Project::STATUS_ASSEMBLY,
        ]);

        return $row;
    }

    /**
     * Traduz o código do parceiro para o da Omie, quando existe vínculo.
     *
     * Sem vínculo, devolve o que veio: é o comportamento de sempre, e cobre o
     * parceiro que já usa o código da Omie no catálogo dele.
     */
    private function codigoNaOmie(?string $codigoDoParceiro): string
    {
        $codigo = trim((string) $codigoDoParceiro);

        if ($codigo === '') {
            return '';
        }

        $vinculo = PartnerSku::query()
            ->whereRaw('LOWER(sku) = ?', [mb_strtolower($codigo)])
            ->whereNotNull('product_id')
            ->with('product:id,omie_code')
            ->first();

        return $vinculo?->product?->omie_code ?? $codigo;
    }

    /**
     * O motivo separa os dois bloqueios que parecem um só.
     *
     * "Não existe na Omie" e "existe no catálogo do parceiro mas ninguém
     * amarrou" pedem ações opostas — cadastrar um produto, ou abrir a tela de
     * catálogo e ligar dois registros que já existem. Uma mensagem única
     * mandaria metade das pessoas para o lugar errado, e é bloqueio que
     * aparece no meio da campanha, quando ninguém tem tempo de investigar.
     */
    private function motivoDoBloqueio(?string $codigoDoParceiro, string $codigoOmie): string
    {
        $codigo = trim((string) $codigoDoParceiro);

        $semVinculo = $codigo !== '' && PartnerSku::query()
            ->whereRaw('LOWER(sku) = ?', [mb_strtolower($codigo)])
            ->whereNull('product_id')
            ->exists();

        if ($semVinculo) {
            return "O SKU '{$codigo}' veio do catálogo do parceiro, mas ainda não está "
                .'vinculado a um produto da Omie. Faça o vínculo em Produtos e reprocesse a linha.';
        }

        return "Produto '{$codigoOmie}' não cadastrado na Omie. "
            .'Cadastre o produto e reprocesse a linha.';
    }
}

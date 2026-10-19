<?php

namespace App\Contracts\Integrations;

/**
 * Publicação do catálogo na Reconhece — a vitrine onde o colaborador da
 * Suvinil escolhe o brinde.
 *
 * É o outro sentido da mesma integração. Pelo webhook entra o que ELES
 * cadastraram; por aqui sai o que NÓS queremos ver na vitrine deles. Os dois
 * caminhos convergem em `partner_skus`: o item publicado já nasce vinculado ao
 * produto da Omie que o expede, que é o vínculo que o resgate vai procurar
 * depois.
 */
interface ReconheceGateway
{
    /**
     * Cria ou atualiza um SKU no catálogo deles.
     *
     * Um método só porque o contrato é o mesmo nos dois casos: o `sku` é a
     * chave, e reenviar é como se corrige um cadastro. Separar em criar e
     * atualizar obrigaria a consultar antes só para escolher o verbo.
     *
     * @param  array<string, mixed>  $payload  no formato do contrato deles
     * @return array{sku: string, accepted: bool, message: ?string}
     */
    public function upsertSku(array $payload): array;
}

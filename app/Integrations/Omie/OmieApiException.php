<?php

namespace App\Integrations\Omie;

use RuntimeException;

class OmieApiException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $faultCode = null)
    {
        parent::__construct($message);
    }

    /** Códigos SOAP-* de "não encontrado" da Omie viram null nas consultas. */
    public function isNotFound(): bool
    {
        $mensagem = mb_strtolower($this->message);

        // Radicais, para valer no masculino e no feminino ("nota não
        // encontrada", "pedido não localizado").
        return str_contains($mensagem, 'não localizad')
            || str_contains($mensagem, 'não cadastrad')
            || str_contains($mensagem, 'não encontrad')
            // Listagem sem resultado: "ERROR: Não existem registros para a
            // página [1]!". É como a Omie diz que o CPF não tem cadastro — sem
            // isto o cliente novo nunca era criado, e o resgate travava.
            || str_contains($mensagem, 'não existem registros');
    }

    /**
     * A Omie fechou a porta: aplicativo suspenso, chave recusada ou consumo
     * bloqueado.
     *
     * Enquanto isso vale, TODA chamada é negada — e insistir não só falha como
     * prolonga o castigo. É o sinal para a plataforma parar de bater na porta,
     * não para tentar de novo em três segundos.
     */
    public function isBlocked(): bool
    {
        $mensagem = mb_strtolower($this->message);

        return str_contains($mensagem, 'suspenso')
            || str_contains($mensagem, 'chave de acesso')
            || str_contains($mensagem, 'consumo indevido')
            || str_contains($mensagem, 'consumo redundante')
            || str_contains($mensagem, 'bloqueada');
    }

    /**
     * Quantos segundos a Omie pediu para esperar ("Aguarde 56 segundos"), ou
     * null quando ela não diz — aí vale o padrão de quem trata.
     */
    public function retryAfterSeconds(): ?int
    {
        return preg_match('/aguarde\s+(\d+)\s+segundos/i', $this->message, $m) === 1
            ? (int) $m[1]
            : null;
    }
}

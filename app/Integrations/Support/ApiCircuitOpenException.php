<?php

namespace App\Integrations\Support;

use RuntimeException;

/**
 * A chamada nem saiu: a integração está pausada porque o provedor recusou o
 * acesso há pouco (ver `ApiCircuitBreaker`).
 *
 * É diferente de uma falha de chamada — aqui nada foi consumido, e insistir
 * agora só prolongaria o bloqueio do lado de lá.
 */
class ApiCircuitOpenException extends RuntimeException {}

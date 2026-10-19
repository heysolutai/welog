<?php

namespace App\Integrations\Support;

use RuntimeException;

/**
 * Teto de chamadas por minuto atingido e a espera passaria do limite tolerado.
 * É transitório de propósito: a fila retenta o job mais tarde.
 */
class ApiThrottleException extends RuntimeException {}

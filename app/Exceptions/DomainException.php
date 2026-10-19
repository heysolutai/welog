<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Violação de regra de negócio — mensagens seguras para exibir ao usuário.
 */
class DomainException extends RuntimeException {}

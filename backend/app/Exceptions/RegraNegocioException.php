<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Violação de regra de negócio com mensagem pronta para exibição ao usuário.
 * Renderizada no padrão JSON API (ver bootstrap/app.php).
 */
class RegraNegocioException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $codigo = 'REGRA_NEGOCIO',
        public readonly int $status = 422,
        public readonly ?string $ponteiro = null,
    ) {
        parent::__construct($message);
    }
}

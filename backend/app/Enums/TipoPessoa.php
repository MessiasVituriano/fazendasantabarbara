<?php

namespace App\Enums;

enum TipoPessoa: string
{
    use Concerns;

    case FORNECEDOR = 'FORNECEDOR';
    case CLIENTE = 'CLIENTE';
    case AMBOS = 'AMBOS';

    public function label(): string
    {
        return match ($this) {
            self::FORNECEDOR => 'Fornecedor',
            self::CLIENTE => 'Cliente/comprador',
            self::AMBOS => 'Fornecedor e cliente',
        };
    }
}

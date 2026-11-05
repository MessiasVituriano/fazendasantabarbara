<?php

namespace App\Enums;

enum TipoProdutoSanitario: string
{
    use Concerns;

    case VACINA = 'VACINA';
    case MEDICAMENTO = 'MEDICAMENTO';

    public function label(): string
    {
        return match ($this) {
            self::VACINA => 'Vacina',
            self::MEDICAMENTO => 'Medicamento',
        };
    }
}

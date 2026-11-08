<?php

namespace App\Enums;

enum TipoItemEstoque: string
{
    use Concerns;

    case MEDICAMENTO = 'MEDICAMENTO';
    case VACINA = 'VACINA';
    case RACAO = 'RACAO';
    case SUPLEMENTO = 'SUPLEMENTO';
    case OUTRO = 'OUTRO';

    public function label(): string
    {
        return match ($this) {
            self::MEDICAMENTO => 'Medicamento',
            self::VACINA => 'Vacina',
            self::RACAO => 'Ração',
            self::SUPLEMENTO => 'Suplemento / sal mineral',
            self::OUTRO => 'Outros insumos',
        };
    }
}

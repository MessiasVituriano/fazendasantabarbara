<?php

namespace App\Enums;

enum StatusGeral: string
{
    use Concerns;

    case ATIVO = 'ATIVO';
    case VENDIDO = 'VENDIDO';
    case MORTO = 'MORTO';
    case ABATIDO = 'ABATIDO';

    public function label(): string
    {
        return match ($this) {
            self::ATIVO => 'Ativo',
            self::VENDIDO => 'Vendido',
            self::MORTO => 'Morto',
            self::ABATIDO => 'Abatido',
        };
    }
}

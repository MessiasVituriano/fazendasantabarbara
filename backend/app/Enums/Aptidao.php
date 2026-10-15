<?php

namespace App\Enums;

enum Aptidao: string
{
    use Concerns;

    case LEITE = 'LEITE';
    case CORTE = 'CORTE';
    case MISTA = 'MISTA';

    public function label(): string
    {
        return match ($this) {
            self::LEITE => 'Leite',
            self::CORTE => 'Corte',
            self::MISTA => 'Mista',
        };
    }
}

<?php

namespace App\Enums;

enum Sexo: string
{
    use Concerns;

    case F = 'F';
    case M = 'M';

    public function label(): string
    {
        return match ($this) {
            self::F => 'Fêmea',
            self::M => 'Macho',
        };
    }
}

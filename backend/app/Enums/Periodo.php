<?php

namespace App\Enums;

enum Periodo: string
{
    use Concerns;

    case MANHA = 'MANHA';
    case TARDE = 'TARDE';

    public function label(): string
    {
        return match ($this) {
            self::MANHA => 'Manhã',
            self::TARDE => 'Tarde',
        };
    }
}

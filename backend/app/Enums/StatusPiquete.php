<?php

namespace App\Enums;

enum StatusPiquete: string
{
    use Concerns;

    case OCUPADO = 'OCUPADO';
    case DESCANSO = 'DESCANSO';
    case DEGRADADO = 'DEGRADADO';

    public function label(): string
    {
        return match ($this) {
            self::OCUPADO => 'Ocupado',
            self::DESCANSO => 'Descanso',
            self::DEGRADADO => 'Degradado',
        };
    }
}

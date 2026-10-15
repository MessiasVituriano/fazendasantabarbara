<?php

namespace App\Enums;

enum StatusReprodutivo: string
{
    use Concerns;

    case APTA = 'APTA';
    case PRENHE_A_CONFIRMAR = 'PRENHE_A_CONFIRMAR';
    case GESTANTE = 'GESTANTE';
    case PARIDA = 'PARIDA';
    case INAPTA = 'INAPTA';

    public function label(): string
    {
        return match ($this) {
            self::APTA => 'Apta',
            self::PRENHE_A_CONFIRMAR => 'Prenhe a confirmar',
            self::GESTANTE => 'Gestante',
            self::PARIDA => 'Parida',
            self::INAPTA => 'Inapta',
        };
    }
}

<?php

namespace App\Enums;

enum TipoMovimentoCapital: string
{
    use Concerns;

    case APORTE = 'APORTE';
    case RETIRADA = 'RETIRADA';

    public function label(): string
    {
        return match ($this) {
            self::APORTE => 'Aporte',
            self::RETIRADA => 'Retirada',
        };
    }
}

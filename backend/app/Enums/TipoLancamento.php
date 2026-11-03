<?php

namespace App\Enums;

enum TipoLancamento: string
{
    use Concerns;

    case RECEITA = 'RECEITA';
    case DESPESA = 'DESPESA';

    public function label(): string
    {
        return match ($this) {
            self::RECEITA => 'Receita',
            self::DESPESA => 'Despesa',
        };
    }
}

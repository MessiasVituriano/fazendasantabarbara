<?php

namespace App\Enums;

enum TipoMovimentoEstoque: string
{
    use Concerns;

    case ENTRADA = 'ENTRADA';
    case SAIDA = 'SAIDA';
    case AJUSTE = 'AJUSTE';

    public function label(): string
    {
        return match ($this) {
            self::ENTRADA => 'Entrada',
            self::SAIDA => 'Saída',
            self::AJUSTE => 'Ajuste de inventário',
        };
    }
}

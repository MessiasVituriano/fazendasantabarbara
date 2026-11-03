<?php

namespace App\Enums;

enum TipoContaFinanceira: string
{
    use Concerns;

    case CAIXA = 'CAIXA';
    case CONTA_CORRENTE = 'CONTA_CORRENTE';
    case POUPANCA = 'POUPANCA';
    case OUTRA = 'OUTRA';

    public function label(): string
    {
        return match ($this) {
            self::CAIXA => 'Caixa (dinheiro)',
            self::CONTA_CORRENTE => 'Conta corrente',
            self::POUPANCA => 'Poupança/investimento',
            self::OUTRA => 'Outra',
        };
    }
}

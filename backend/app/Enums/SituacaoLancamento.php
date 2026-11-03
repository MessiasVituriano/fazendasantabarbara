<?php

namespace App\Enums;

/**
 * Situação gravada no banco. "Vencido" não é gravado: é um PENDENTE com vencimento
 * anterior a hoje (ver Lancamento::situacaoExibida()), para nunca ficar desatualizado.
 */
enum SituacaoLancamento: string
{
    use Concerns;

    case PENDENTE = 'PENDENTE';
    case PAGO = 'PAGO';
    case CANCELADO = 'CANCELADO';

    public function label(): string
    {
        return match ($this) {
            self::PENDENTE => 'Pendente',
            self::PAGO => 'Pago',
            self::CANCELADO => 'Cancelado',
        };
    }

    /** Situações usadas em filtros e telas (inclui a derivada VENCIDO). */
    public static function opcoesDeFiltro(): array
    {
        return [
            ['value' => 'PENDENTE', 'label' => 'Pendente (a vencer)'],
            ['value' => 'VENCIDO', 'label' => 'Vencido'],
            ['value' => 'PAGO', 'label' => 'Pago'],
            ['value' => 'CANCELADO', 'label' => 'Cancelado'],
        ];
    }
}

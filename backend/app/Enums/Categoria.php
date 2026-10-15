<?php

namespace App\Enums;

enum Categoria: string
{
    use Concerns;

    case BEZERRO = 'BEZERRO';
    case NOVILHA = 'NOVILHA';
    case GARROTE = 'GARROTE';
    case LACTACAO = 'LACTACAO';
    case SECA = 'SECA';
    case TOURO = 'TOURO';
    case BOI_GORDO = 'BOI_GORDO';

    public function label(): string
    {
        return match ($this) {
            self::BEZERRO => 'Bezerro(a)',
            self::NOVILHA => 'Novilha',
            self::GARROTE => 'Garrote',
            self::LACTACAO => 'Em lactação',
            self::SECA => 'Seca',
            self::TOURO => 'Touro',
            self::BOI_GORDO => 'Boi gordo',
        };
    }
}

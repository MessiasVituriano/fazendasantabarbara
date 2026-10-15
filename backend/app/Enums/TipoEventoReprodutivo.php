<?php

namespace App\Enums;

enum TipoEventoReprodutivo: string
{
    use Concerns;

    case INSEMINACAO = 'INSEMINACAO';
    case COBERTURA_NATURAL = 'COBERTURA_NATURAL';
    case TOQUE_POSITIVO = 'TOQUE_POSITIVO';
    case TOQUE_NEGATIVO = 'TOQUE_NEGATIVO';
    case PARTO = 'PARTO';
    case ABORTO = 'ABORTO';

    public function label(): string
    {
        return match ($this) {
            self::INSEMINACAO => 'Inseminação',
            self::COBERTURA_NATURAL => 'Cobertura natural',
            self::TOQUE_POSITIVO => 'Toque positivo',
            self::TOQUE_NEGATIVO => 'Toque negativo',
            self::PARTO => 'Parto',
            self::ABORTO => 'Aborto',
        };
    }
}

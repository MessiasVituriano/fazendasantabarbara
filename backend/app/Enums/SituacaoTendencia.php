<?php

namespace App\Enums;

/** Quanto a tendência de peso (regressão das pesagens recentes) é confiável para projetar. */
enum SituacaoTendencia: string
{
    use Concerns;

    case CONFIAVEL = 'CONFIAVEL';
    case INSTAVEL = 'INSTAVEL';
    case IRREGULAR = 'IRREGULAR';
    case INSUFICIENTE = 'INSUFICIENTE';

    public function label(): string
    {
        return match ($this) {
            self::CONFIAVEL => 'Tendência confiável',
            self::INSTAVEL => 'Tendência instável',
            self::IRREGULAR => 'Ganho irregular',
            self::INSUFICIENTE => 'Dados insuficientes',
        };
    }

    /** Só projeta peso quando o ganho segue uma reta razoável. */
    public function projetavel(): bool
    {
        return $this === self::CONFIAVEL || $this === self::INSTAVEL;
    }
}

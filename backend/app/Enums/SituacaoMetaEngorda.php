<?php

namespace App\Enums;

/** Situação calculada de uma meta de engorda (não é gravada). */
enum SituacaoMetaEngorda: string
{
    use Concerns;

    case NO_RITMO = 'NO_RITMO';
    case ATENCAO = 'ATENCAO';
    case ATRASADA = 'ATRASADA';
    case INVIAVEL = 'INVIAVEL';
    case ATINGIDA = 'ATINGIDA';
    case VENCIDA = 'VENCIDA';
    case SEM_DADOS = 'SEM_DADOS';

    public function label(): string
    {
        return match ($this) {
            self::NO_RITMO => 'No ritmo',
            self::ATENCAO => 'Atenção',
            self::ATRASADA => 'Atrasada',
            self::INVIAVEL => 'Inviável',
            self::ATINGIDA => 'Atingida',
            self::VENCIDA => 'Vencida',
            self::SEM_DADOS => 'Sem pesagens suficientes',
        };
    }

    /** Merece aviso no painel. */
    public function alerta(): bool
    {
        return in_array($this, [self::ATRASADA, self::INVIAVEL, self::VENCIDA, self::ATINGIDA], true);
    }
}

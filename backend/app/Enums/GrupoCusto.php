<?php

namespace App\Enums;

/**
 * Agrupamento de custos da recria. AQUISICAO fica separado do custo operacional:
 * o valor pago pelos animais não se mistura com o custo de mantê-los.
 */
enum GrupoCusto: string
{
    use Concerns;

    case AQUISICAO = 'AQUISICAO';
    case ALIMENTACAO = 'ALIMENTACAO';
    case SUPLEMENTACAO = 'SUPLEMENTACAO';
    case SANIDADE = 'SANIDADE';
    case MAO_DE_OBRA = 'MAO_DE_OBRA';
    case PASTAGENS = 'PASTAGENS';
    case TRANSPORTE = 'TRANSPORTE';
    case MANUTENCAO = 'MANUTENCAO';
    case ADMINISTRATIVO = 'ADMINISTRATIVO';
    case OUTROS = 'OUTROS';

    public function label(): string
    {
        return match ($this) {
            self::AQUISICAO => 'Aquisição dos animais',
            self::ALIMENTACAO => 'Alimentação',
            self::SUPLEMENTACAO => 'Suplementação',
            self::SANIDADE => 'Sanidade',
            self::MAO_DE_OBRA => 'Mão de obra',
            self::PASTAGENS => 'Pastagens',
            self::TRANSPORTE => 'Transporte',
            self::MANUTENCAO => 'Manutenção',
            self::ADMINISTRATIVO => 'Administrativo',
            self::OUTROS => 'Outros',
        };
    }

    public function operacional(): bool
    {
        return $this !== self::AQUISICAO;
    }
}

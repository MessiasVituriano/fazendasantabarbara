<?php

namespace App\Enums;

enum UnidadeEstoque: string
{
    use Concerns;

    case UN = 'UN';
    case KG = 'KG';
    case SACO = 'SACO';
    case L = 'L';
    case ML = 'ML';
    case DOSE = 'DOSE';
    case FRASCO = 'FRASCO';
    case CAIXA = 'CAIXA';

    public function label(): string
    {
        return match ($this) {
            self::UN => 'Unidade',
            self::KG => 'Quilo (kg)',
            self::SACO => 'Saco',
            self::L => 'Litro (L)',
            self::ML => 'Mililitro (mL)',
            self::DOSE => 'Dose',
            self::FRASCO => 'Frasco',
            self::CAIXA => 'Caixa',
        };
    }

    /** Abreviação exibida ao lado das quantidades. */
    public function sigla(): string
    {
        return match ($this) {
            self::UN => 'un',
            self::KG => 'kg',
            self::SACO => 'sc',
            self::L => 'L',
            self::ML => 'mL',
            self::DOSE => 'dose(s)',
            self::FRASCO => 'fr',
            self::CAIXA => 'cx',
        };
    }
}

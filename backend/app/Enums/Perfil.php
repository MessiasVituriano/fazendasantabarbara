<?php

namespace App\Enums;

enum Perfil: string
{
    use Concerns;

    case GESTOR = 'GESTOR';
    case CASEIRO = 'CASEIRO';

    public function label(): string
    {
        return match ($this) {
            self::GESTOR => 'Gestor',
            self::CASEIRO => 'Caseiro',
        };
    }

    public function descricao(): string
    {
        return match ($this) {
            self::GESTOR => 'Acesso geral: painel, indicadores, cadastros e usuários.',
            self::CASEIRO => 'Operacional: ordenha, pesagens, eventos, vacinas e piquetes do dia a dia.',
        };
    }
}

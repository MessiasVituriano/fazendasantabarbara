<?php

namespace App\Http\Requests\Concerns;

use App\Enums\Perfil;
use Illuminate\Support\Carbon;

/**
 * O caseiro só lança/corrige dados dos últimos N dias (PECUARIA_DIAS_RETROATIVOS_CASEIRO).
 * Datas anteriores ficam com o gestor, preservando o histórico.
 */
trait LimitaDataDoCaseiro
{
    /** @return list<string> regra extra para o campo de data (vazia para o gestor) */
    protected function limiteDoCaseiro(): array
    {
        if ($this->user()?->perfil !== Perfil::CASEIRO) {
            return [];
        }

        return ['after_or_equal:'.Carbon::today()->subDays(config('pecuaria.dias_retroativos_caseiro'))->toDateString()];
    }

    protected function mensagemLimiteDoCaseiro(string $campo): array
    {
        $dias = config('pecuaria.dias_retroativos_caseiro');

        return [$campo.'.after_or_equal' => "Você só pode lançar dados dos últimos {$dias} dias. Para datas anteriores, fale com o gestor."];
    }
}

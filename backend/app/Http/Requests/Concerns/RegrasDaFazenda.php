<?php

namespace App\Http\Requests\Concerns;

use App\Services\ContextoFazenda;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Regras exists/unique restritas à fazenda ativa. As regras de validação consultam a tabela direto, sem o filtro
 * dos models: sem isto daria para apontar para a conta ou o animal de outra fazenda.
 */
trait RegrasDaFazenda
{
    protected function existeNaFazenda(string $tabela, string $coluna = 'id'): Exists
    {
        return Rule::exists($tabela, $coluna)->where('fazenda_id', app(ContextoFazenda::class)->idObrigatorio());
    }

    protected function unicoNaFazenda(string $tabela, string $coluna): Unique
    {
        return Rule::unique($tabela, $coluna)->where('fazenda_id', app(ContextoFazenda::class)->idObrigatorio());
    }
}

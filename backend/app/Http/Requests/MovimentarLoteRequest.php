<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RegrasDaFazenda;
use Illuminate\Foundation\Http\FormRequest;

/** Entrada ou saída de animais do lote. */
class MovimentarLoteRequest extends FormRequest
{
    use RegrasDaFazenda;

    public function rules(): array
    {
        $entrada = $this->routeIs('lotes.animais.entradas');

        return [
            // Na saída, sem animal_ids o lote inteiro é esvaziado.
            'animal_ids' => [$entrada ? 'required' : 'sometimes', 'array', 'min:1'],
            'animal_ids.*' => ['integer', 'distinct', $this->existeNaFazenda('animais')],
            $entrada ? 'data_entrada' : 'data_saida' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    public function attributes(): array
    {
        return ['animal_ids' => 'animais', 'data_entrada' => 'data de entrada', 'data_saida' => 'data de saída'];
    }
}

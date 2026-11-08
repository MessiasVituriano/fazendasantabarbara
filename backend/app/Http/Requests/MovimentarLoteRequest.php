<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Entrada ou saída de animais do lote. */
class MovimentarLoteRequest extends FormRequest
{
    public function rules(): array
    {
        $entrada = $this->routeIs('lotes.animais.entradas');

        return [
            // Na saída, sem animal_ids o lote inteiro é esvaziado.
            'animal_ids' => [$entrada ? 'required' : 'sometimes', 'array', 'min:1'],
            'animal_ids.*' => ['integer', 'distinct', 'exists:animais,id'],
            $entrada ? 'data_entrada' : 'data_saida' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ];
    }

    public function attributes(): array
    {
        return ['animal_ids' => 'animais', 'data_entrada' => 'data de entrada', 'data_saida' => 'data de saída'];
    }
}

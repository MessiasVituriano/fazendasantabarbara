<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RegrasDaFazenda;
use Illuminate\Foundation\Http\FormRequest;

class MovimentarPiqueteRequest extends FormRequest
{
    use RegrasDaFazenda;

    public function rules(): array
    {
        $entrada = $this->routeIs('piquetes.entradas');

        return [
            // Na saída, sem animal_ids o piquete inteiro é esvaziado.
            'animal_ids' => [$entrada ? 'required' : 'sometimes', 'array', 'min:1'],
            'animal_ids.*' => ['integer', 'distinct', $this->existeNaFazenda('animais')],
            $entrada ? 'data_entrada' : 'data_saida' => ['required', 'date', 'before_or_equal:today'],
        ];
    }
}

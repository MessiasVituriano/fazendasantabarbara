<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LimitaDataDoCaseiro;
use Illuminate\Foundation\Http\FormRequest;

class RegistrarVacinaRequest extends FormRequest
{
    use LimitaDataDoCaseiro;

    public function rules(): array
    {
        return [
            'animal_id' => ['required', 'integer', 'exists:animais,id'],
            'nome_vacina' => ['required', 'string', 'max:100'],
            'data_aplicacao' => ['required', 'date', 'before_or_equal:today', ...$this->limiteDoCaseiro()],
            'dias_carencia_leite_carne' => ['required', 'integer', 'min:0', 'max:3650'],
        ];
    }

    public function messages(): array
    {
        return $this->mensagemLimiteDoCaseiro('data_aplicacao');
    }
}

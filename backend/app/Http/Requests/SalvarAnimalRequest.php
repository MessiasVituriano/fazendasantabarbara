<?php

namespace App\Http\Requests;

use App\Enums\Aptidao;
use App\Enums\Categoria;
use App\Enums\Sexo;
use App\Enums\StatusGeral;
use App\Enums\StatusReprodutivo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalvarAnimalRequest extends FormRequest
{
    public function rules(): array
    {
        $animal = $this->route('animal');
        $obrigatorio = $animal ? 'sometimes' : 'required';

        return [
            'brinco' => [$obrigatorio, 'string', 'max:50', Rule::unique('animais', 'brinco')->ignore($animal)],
            'nome' => ['nullable', 'string', 'max:100'],
            'sexo' => [$obrigatorio, Rule::enum(Sexo::class)],
            'data_nascimento' => [$obrigatorio, 'date', 'before_or_equal:today'],
            'aptidao' => [$obrigatorio, Rule::enum(Aptidao::class)],
            'categoria' => [$obrigatorio, Rule::enum(Categoria::class)],
            'status_reprodutivo' => ['sometimes', Rule::enum(StatusReprodutivo::class)],
            'status_geral' => ['sometimes', Rule::enum(StatusGeral::class)],
            'mae_id' => [
                'nullable', 'integer',
                Rule::exists('animais', 'id')->where('sexo', Sexo::F->value),
                Rule::notIn([$animal?->id]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'mae_id.exists' => 'A mãe informada não existe ou não é fêmea.',
            'mae_id.not_in' => 'Um animal não pode ser mãe de si mesmo.',
        ];
    }
}

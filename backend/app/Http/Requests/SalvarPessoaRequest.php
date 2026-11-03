<?php

namespace App\Http\Requests;

use App\Enums\TipoPessoa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalvarPessoaRequest extends FormRequest
{
    public function rules(): array
    {
        $obrigatorio = $this->route('pessoa') ? 'sometimes' : 'required';

        return [
            'nome' => [$obrigatorio, 'string', 'max:120'],
            'tipo' => [$obrigatorio, Rule::enum(TipoPessoa::class)],
            'documento' => ['nullable', 'string', 'max:20'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }
}

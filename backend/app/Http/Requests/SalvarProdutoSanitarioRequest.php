<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalvarProdutoSanitarioRequest extends FormRequest
{
    public function rules(): array
    {
        $produto = $this->route('produto');
        $categoriaId = $this->input('categoria_sanitaria_id', $produto?->categoria_sanitaria_id);

        return [
            'categoria_sanitaria_id' => [$produto ? 'sometimes' : 'required', 'integer', 'exists:categorias_sanitarias,id'],
            'nome' => [$produto ? 'sometimes' : 'required', 'string', 'max:100',
                Rule::unique('produtos_sanitarios', 'nome')->where('categoria_sanitaria_id', is_numeric($categoriaId) ? $categoriaId : 0)->ignore($produto)],
            'dias_carencia' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['categoria_sanitaria_id' => 'categoria', 'dias_carencia' => 'carência'];
    }
}

<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RegrasDaFazenda;
use Illuminate\Foundation\Http\FormRequest;

class SalvarProdutoSanitarioRequest extends FormRequest
{
    use RegrasDaFazenda;

    public function rules(): array
    {
        $produto = $this->route('produto');
        $categoriaId = $this->input('categoria_sanitaria_id', $produto?->categoria_sanitaria_id);

        return [
            'categoria_sanitaria_id' => [$produto ? 'sometimes' : 'required', 'integer', $this->existeNaFazenda('categorias_sanitarias')],
            'nome' => [$produto ? 'sometimes' : 'required', 'string', 'max:100',
                $this->unicoNaFazenda('produtos_sanitarios', 'nome')->where('categoria_sanitaria_id', is_numeric($categoriaId) ? $categoriaId : 0)->ignore($produto)],
            'dias_carencia' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['categoria_sanitaria_id' => 'categoria', 'dias_carencia' => 'carência'];
    }
}

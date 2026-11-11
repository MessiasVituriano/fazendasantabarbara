<?php

namespace App\Http\Requests;

use App\Enums\TipoProdutoSanitario;
use App\Http\Requests\Concerns\RegrasDaFazenda;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalvarCategoriaSanitariaRequest extends FormRequest
{
    use RegrasDaFazenda;

    public function rules(): array
    {
        $categoria = $this->route('categoria');
        $tipo = $categoria?->tipo->value ?? $this->input('tipo');

        return [
            // O tipo não muda depois de criada: aplicações antigas dependem dele.
            'tipo' => $categoria ? ['prohibited'] : ['required', Rule::enum(TipoProdutoSanitario::class)],
            'nome' => [$categoria ? 'sometimes' : 'required', 'string', 'max:80',
                $this->unicoNaFazenda('categorias_sanitarias', 'nome')->where('tipo', is_string($tipo) ? $tipo : '')->ignore($categoria)],
            'dias_carencia_padrao' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'ativa' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['dias_carencia_padrao' => 'carência sugerida'];
    }
}

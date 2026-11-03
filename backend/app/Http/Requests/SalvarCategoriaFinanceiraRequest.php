<?php

namespace App\Http\Requests;

use App\Enums\GrupoCusto;
use App\Enums\TipoLancamento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalvarCategoriaFinanceiraRequest extends FormRequest
{
    public function rules(): array
    {
        $categoria = $this->route('categoria');
        $tipo = $this->input('tipo', $categoria?->tipo?->value);

        return [
            // O tipo não muda depois de criada: lançamentos antigos dependem dele.
            'tipo' => $categoria ? ['prohibited'] : ['required', Rule::enum(TipoLancamento::class)],
            'nome' => [$categoria ? 'sometimes' : 'required', 'string', 'max:80',
                Rule::unique('categorias_financeiras', 'nome')->where('tipo', $tipo)->ignore($categoria)],
            'grupo_custo' => $tipo === 'DESPESA'
                ? [$categoria ? 'sometimes' : 'required', Rule::enum(GrupoCusto::class)]
                : ['prohibited'],
            'ativa' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['grupo_custo' => 'grupo de custo'];
    }
}

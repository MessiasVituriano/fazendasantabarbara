<?php

namespace App\Http\Requests;

use App\Enums\TipoItemEstoque;
use App\Enums\UnidadeEstoque;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** O saldo não entra aqui: muda só por entradas, saídas e ajustes. */
class SalvarItemEstoqueRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $minimo = $this->input('estoque_minimo');
        // Sem mínimo = 0 (sem alerta de reposição).
        if ($this->has('estoque_minimo') && ($minimo === null || $minimo === '')) {
            $this->merge(['estoque_minimo' => 0]);
        } elseif (is_string($minimo) && str_contains($minimo, ',')) {
            $this->merge(['estoque_minimo' => str_replace(',', '.', str_replace('.', '', $minimo))]);
        }
    }

    public function rules(): array
    {
        $item = $this->route('item');
        $obrigatorio = $item ? 'sometimes' : 'required';

        return [
            'nome' => [$obrigatorio, 'string', 'max:100', Rule::unique('itens_estoque', 'nome')->ignore($item)],
            'tipo' => [$obrigatorio, Rule::enum(TipoItemEstoque::class)],
            'unidade' => [$obrigatorio, Rule::enum(UnidadeEstoque::class)],
            'estoque_minimo' => ['sometimes', 'numeric', 'decimal:0,3', 'min:0', 'max:99999999999'],
            'data_validade' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'localizacao' => ['sometimes', 'nullable', 'string', 'max:100'],
            'observacoes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['estoque_minimo' => 'estoque mínimo', 'data_validade' => 'validade', 'localizacao' => 'localização'];
    }
}

<?php

namespace App\Http\Requests;

use App\Enums\FormaPagamento;
use App\Enums\GrupoCusto;
use App\Enums\TipoLancamento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalvarLancamentoRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // Aceita "1.234,56" e "1234,56" vindos de formulário.
        foreach (['valor', 'valor_pago'] as $campo) {
            $v = $this->input($campo);
            if (is_string($v) && str_contains($v, ',')) {
                $this->merge([$campo => str_replace(',', '.', str_replace('.', '', $v))]);
            }
        }
    }

    public function rules(): array
    {
        $editando = (bool) $this->route('lancamento');
        $obrigatorio = $editando ? 'sometimes' : 'required';
        $maxParcelas = config('pecuaria.financeiro.maximo_parcelas');

        return [
            'tipo' => [$obrigatorio, Rule::enum(TipoLancamento::class)],
            'descricao' => [$obrigatorio, 'string', 'max:160'],
            'categoria_id' => [$obrigatorio, 'integer', 'exists:categorias_financeiras,id'],
            'grupo_custo' => ['nullable', Rule::enum(GrupoCusto::class)],
            'valor' => [$obrigatorio, 'numeric', 'decimal:0,2', 'gt:0', 'max:999999999999.99'],
            'data_competencia' => [$obrigatorio, 'date_format:Y-m-d'],
            'data_vencimento' => [$obrigatorio, 'date_format:Y-m-d'],
            'pessoa_id' => ['nullable', 'integer', 'exists:pessoas,id'],
            'lote_id' => ['nullable', 'integer', 'exists:lotes,id'],
            'documento' => ['nullable', 'string', 'max:60'],
            'observacoes' => ['nullable', 'string', 'max:2000'],

            // Só na criação: parcelamento ou lançamento já pago.
            'parcelas' => $editando ? ['prohibited'] : ['nullable', 'integer', 'min:1', "max:{$maxParcelas}"],
            'data_pagamento' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'valor_pago' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999999999.99'],
            'conta_financeira_id' => ['nullable', 'required_with:data_pagamento', 'integer', 'exists:contas_financeiras,id'],
            'forma_pagamento' => ['nullable', Rule::enum(FormaPagamento::class)],
        ];
    }

    public function attributes(): array
    {
        return [
            'data_competencia' => 'data de competência',
            'data_vencimento' => 'data de vencimento',
            'data_pagamento' => 'data de pagamento',
            'conta_financeira_id' => 'conta',
            'categoria_id' => 'categoria',
            'valor_pago' => 'valor pago',
        ];
    }
}

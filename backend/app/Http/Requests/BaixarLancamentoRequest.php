<?php

namespace App\Http\Requests;

use App\Enums\FormaPagamento;
use App\Http\Requests\Concerns\RegrasDaFazenda;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Baixa de um lançamento (rota /{id}/baixa) ou de vários (rota /baixa-em-lote, com `ids`). */
class BaixarLancamentoRequest extends FormRequest
{
    use RegrasDaFazenda;

    protected function prepareForValidation(): void
    {
        $v = $this->input('valor_pago');
        if (is_string($v) && str_contains($v, ',')) {
            $this->merge(['valor_pago' => str_replace(',', '.', str_replace('.', '', $v))]);
        }
    }

    public function rules(): array
    {
        $emLote = ! $this->route('lancamento');

        return [
            'ids' => $emLote ? ['required', 'array', 'min:1', 'max:200'] : ['prohibited'],
            'ids.*' => ['integer', 'distinct', $this->existeNaFazenda('lancamentos')],
            'data_pagamento' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'conta_financeira_id' => ['required', 'integer', $this->existeNaFazenda('contas_financeiras')],
            'forma_pagamento' => ['nullable', Rule::enum(FormaPagamento::class)],
            // Em lote, cada um é baixado pelo próprio valor.
            'valor_pago' => $emLote ? ['prohibited'] : ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999999999.99'],
        ];
    }

    public function attributes(): array
    {
        return ['data_pagamento' => 'data de pagamento', 'conta_financeira_id' => 'conta', 'valor_pago' => 'valor pago'];
    }
}

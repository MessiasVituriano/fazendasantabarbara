<?php

namespace App\Http\Requests;

use App\Enums\FormaPagamento;
use App\Enums\TipoMovimentoCapital;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalvarMovimentoCapitalRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // Aceita "1.234,56" e "1234,56" vindos de formulário.
        $valor = $this->input('valor');
        if (is_string($valor) && str_contains($valor, ',')) {
            $this->merge(['valor' => str_replace(',', '.', str_replace('.', '', $valor))]);
        }
    }

    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::enum(TipoMovimentoCapital::class)],
            'pessoa_id' => ['required', 'integer', 'exists:pessoas,id'],
            'valor' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999999999.99'],
            'data_movimento' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'conta_financeira_id' => ['required', 'integer', 'exists:contas_financeiras,id'],
            'forma_pagamento' => ['nullable', Rule::enum(FormaPagamento::class)],
            'documento' => ['nullable', 'string', 'max:60'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return ['pessoa_id' => 'investidor', 'conta_financeira_id' => 'conta'];
    }
}

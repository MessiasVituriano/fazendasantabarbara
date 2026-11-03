<?php

namespace App\Http\Requests;

use App\Enums\TipoContaFinanceira;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalvarContaFinanceiraRequest extends FormRequest
{
    public function rules(): array
    {
        $conta = $this->route('conta');
        $obrigatorio = $conta ? 'sometimes' : 'required';

        return [
            'nome' => [$obrigatorio, 'string', 'max:80', Rule::unique('contas_financeiras', 'nome')->ignore($conta)],
            'tipo' => [$obrigatorio, Rule::enum(TipoContaFinanceira::class)],
            'saldo_inicial' => [$obrigatorio, 'numeric', 'decimal:0,2', 'min:-999999999999.99', 'max:999999999999.99'],
            'data_saldo_inicial' => [$obrigatorio, 'date_format:Y-m-d'],
            'ativa' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['saldo_inicial' => 'saldo inicial', 'data_saldo_inicial' => 'data do saldo inicial'];
    }
}

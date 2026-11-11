<?php

namespace App\Http\Requests;

use App\Enums\TipoContaFinanceira;
use App\Http\Requests\Concerns\RegrasDaFazenda;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalvarContaFinanceiraRequest extends FormRequest
{
    use RegrasDaFazenda;

    public function rules(): array
    {
        $conta = $this->route('conta');
        $obrigatorio = $conta ? 'sometimes' : 'required';

        return [
            'nome' => [$obrigatorio, 'string', 'max:80', $this->unicoNaFazenda('contas_financeiras', 'nome')->ignore($conta)],
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

<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RegrasDaFazenda;
use Illuminate\Foundation\Http\FormRequest;

class SalvarLoteRequest extends FormRequest
{
    use RegrasDaFazenda;

    public function rules(): array
    {
        $lote = $this->route('lote');
        $obrigatorio = $lote ? 'sometimes' : 'required';

        return [
            'nome' => [$obrigatorio, 'string', 'max:80', $this->unicoNaFazenda('lotes', 'nome')->ignore($lote)],
            'data_formacao' => [$obrigatorio, 'date_format:Y-m-d'],
            'categoria' => ['nullable', 'string', 'max:60'],
            'origem' => ['nullable', 'string', 'max:120'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            'data_encerramento' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:data_formacao'],
        ];
    }

    public function attributes(): array
    {
        return ['data_formacao' => 'data de formação', 'data_encerramento' => 'data de encerramento'];
    }
}

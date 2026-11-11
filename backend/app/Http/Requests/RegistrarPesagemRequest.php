<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LimitaDataDoCaseiro;
use App\Http\Requests\Concerns\RegrasDaFazenda;

class RegistrarPesagemRequest extends RequisicaoEmLote
{
    use LimitaDataDoCaseiro, RegrasDaFazenda;

    protected function regrasDoRegistro(): array
    {
        return [
            'uuid' => ['nullable', 'uuid', 'distinct'],
            'animal_id' => ['required', 'integer', $this->existeNaFazenda('animais')],
            'peso_kg' => ['required', 'numeric', 'gt:0', 'max:9999.99'],
            'data_pesagem' => ['required', 'date', 'before_or_equal:today', ...$this->limiteDoCaseiro()],
        ];
    }

    public function messages(): array
    {
        return $this->mensagemLimiteDoCaseiro('registros.*.data_pesagem');
    }
}

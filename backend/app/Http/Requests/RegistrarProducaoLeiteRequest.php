<?php

namespace App\Http\Requests;

use App\Enums\Periodo;
use App\Enums\Sexo;
use App\Http\Requests\Concerns\LimitaDataDoCaseiro;
use App\Models\Animal;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RegistrarProducaoLeiteRequest extends RequisicaoEmLote
{
    use LimitaDataDoCaseiro;

    protected function regrasDoRegistro(): array
    {
        return [
            'uuid' => ['nullable', 'uuid', 'distinct'],
            'animal_id' => ['required', 'integer', 'exists:animais,id'],
            'data_pesagem' => ['required', 'date', 'before_or_equal:today', ...$this->limiteDoCaseiro()],
            'periodo' => ['required', Rule::enum(Periodo::class)],
            'quantidade_litros' => ['required', 'numeric', 'min:0', 'max:999.99'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $machos = Animal::whereIn('id', array_column($this->input('registros'), 'animal_id'))
                ->where('sexo', Sexo::M)->pluck('brinco', 'id');

            foreach ($this->input('registros') as $i => $registro) {
                if ($brinco = $machos[$registro['animal_id']] ?? null) {
                    $validator->errors()->add("registros.{$i}.animal_id", "O animal {$brinco} é macho e não pode ter produção de leite.");
                }
            }
        }];
    }

    public function messages(): array
    {
        return $this->mensagemLimiteDoCaseiro('registros.*.data_pesagem');
    }
}

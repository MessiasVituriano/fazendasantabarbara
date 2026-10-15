<?php

namespace App\Http\Requests;

use App\Enums\Periodo;
use App\Enums\Sexo;
use App\Http\Requests\Concerns\LimitaDataDoCaseiro;
use App\Models\Animal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Ordenha de um turno inteiro (tela de ordenha): uma data, um período e os litros de cada vaca.
 * `quantidade_litros: null` remove o registro daquela vaca no turno (campo apagado na tela).
 */
class SalvarOrdenhaRequest extends FormRequest
{
    use LimitaDataDoCaseiro;

    public function rules(): array
    {
        return [
            'data_pesagem' => ['required', 'date', 'before_or_equal:today', ...$this->limiteDoCaseiro()],
            'periodo' => ['required', Rule::enum(Periodo::class)],
            'registros' => ['required', 'array', 'list', 'min:1', 'max:1000'],
            'registros.*.animal_id' => ['required', 'integer', 'distinct', 'exists:animais,id'],
            'registros.*.quantidade_litros' => ['present', 'nullable', 'numeric', 'min:0', 'max:999.99'],
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
        return $this->mensagemLimiteDoCaseiro('data_pesagem');
    }
}

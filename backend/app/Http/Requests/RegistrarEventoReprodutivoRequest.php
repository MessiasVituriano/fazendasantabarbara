<?php

namespace App\Http\Requests;

use App\Enums\Sexo;
use App\Enums\StatusGeral;
use App\Enums\TipoEventoReprodutivo;
use App\Http\Requests\Concerns\LimitaDataDoCaseiro;
use App\Http\Requests\Concerns\RegrasDaFazenda;
use App\Models\Animal;
use App\Models\EventoReprodutivo;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RegistrarEventoReprodutivoRequest extends RequisicaoEmLote
{
    use LimitaDataDoCaseiro, RegrasDaFazenda;

    protected function regrasDoRegistro(): array
    {
        $parto = TipoEventoReprodutivo::PARTO->value;

        return [
            'uuid' => ['nullable', 'uuid', 'distinct'],
            'animal_id' => ['required', 'integer', $this->existeNaFazenda('animais')],
            'tipo_evento' => ['required', Rule::enum(TipoEventoReprodutivo::class)],
            'data_evento' => ['required', 'date', 'before_or_equal:today', ...$this->limiteDoCaseiro()],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            // Dados do bezerro: obrigatórios somente no PARTO (RN02).
            'brinco_bezerro' => ["required_if:registros.*.tipo_evento,{$parto}", "prohibited_unless:registros.*.tipo_evento,{$parto}", 'nullable', 'string', 'max:50', 'distinct'],
            'sexo_bezerro' => ["required_if:registros.*.tipo_evento,{$parto}", "prohibited_unless:registros.*.tipo_evento,{$parto}", 'nullable', Rule::enum(Sexo::class)],
            'nome_bezerro' => ["prohibited_unless:registros.*.tipo_evento,{$parto}", 'nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Eventos reprodutivos só valem para fêmeas ativas, e o brinco do bezerro precisa ser
     * novo. Registros cujo uuid já foi sincronizado são reenvios e ficam de fora das checagens.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $registros = $this->input('registros');
            $sincronizados = EventoReprodutivo::whereIn('uuid', array_filter(array_column($registros, 'uuid')))
                ->pluck('uuid')->flip();
            $animais = Animal::whereIn('id', array_column($registros, 'animal_id'))->get()->keyBy('id');
            $brincosEmUso = Animal::whereIn('brinco', array_filter(array_column($registros, 'brinco_bezerro')))
                ->pluck('brinco')->flip();

            foreach ($registros as $i => $registro) {
                if (isset($registro['uuid'], $sincronizados[$registro['uuid']])) {
                    continue;
                }
                $animal = $animais[$registro['animal_id']];
                $campo = "registros.{$i}.animal_id";

                if (isset($registro['brinco_bezerro'], $brincosEmUso[$registro['brinco_bezerro']])) {
                    $validator->errors()->add("registros.{$i}.brinco_bezerro", "O brinco {$registro['brinco_bezerro']} já está em uso por outro animal.");
                }

                if ($animal->sexo !== Sexo::F) {
                    $validator->errors()->add($campo, "O animal {$animal->brinco} é macho; eventos reprodutivos são registrados na matriz.");
                } elseif ($animal->status_geral !== StatusGeral::ATIVO) {
                    $validator->errors()->add($campo, "O animal {$animal->brinco} não está ativo ({$animal->status_geral->label()}).");
                }
            }
        }];
    }

    /** Ordem cronológica: dentro de um lote os efeitos são aplicados como aconteceram. */
    public function registros(): array
    {
        $registros = parent::registros();
        usort($registros, fn ($a, $b) => strcmp($a['data_evento'], $b['data_evento']));

        return $registros;
    }

    public function messages(): array
    {
        return $this->mensagemLimiteDoCaseiro('registros.*.data_evento');
    }
}

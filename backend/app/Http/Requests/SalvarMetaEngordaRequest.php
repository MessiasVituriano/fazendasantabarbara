<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Meta de engorda do lote: peso médio alvo numa data. */
class SalvarMetaEngordaRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['peso_alvo_kg', 'gmd_alvo'] as $campo) {
            $valor = $this->input($campo);
            if (is_string($valor) && str_contains($valor, ',')) {
                $this->merge([$campo => str_replace(',', '.', str_replace('.', '', $valor))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'peso_alvo_kg' => ['required', 'numeric', 'decimal:0,2', 'min:30', 'max:1500'],
            'data_inicio' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'data_alvo' => ['required', 'date_format:Y-m-d', 'after:today'],
            'gmd_alvo' => ['nullable', 'numeric', 'decimal:0,3', 'gt:0', 'max:3'],
            'observacoes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'peso_alvo_kg' => 'peso alvo',
            'data_inicio' => 'data de início',
            'data_alvo' => 'data alvo',
            'gmd_alvo' => 'GMD alvo',
            'observacoes' => 'observações',
        ];
    }
}

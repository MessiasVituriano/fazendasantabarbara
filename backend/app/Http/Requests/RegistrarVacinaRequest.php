<?php

namespace App\Http\Requests;

use App\Enums\TipoProdutoSanitario;
use App\Http\Requests\Concerns\LimitaDataDoCaseiro;
use App\Models\CategoriaSanitaria;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarVacinaRequest extends FormRequest
{
    use LimitaDataDoCaseiro;

    public function rules(): array
    {
        $tipo = is_string($this->input('tipo')) ? $this->input('tipo') : '';

        return [
            'uuid' => ['nullable', 'uuid'],
            'animal_id' => ['required', 'integer', 'exists:animais,id'],
            'tipo' => ['required', Rule::enum(TipoProdutoSanitario::class)],
            'categoria_sanitaria_id' => ['nullable', 'integer',
                Rule::exists('categorias_sanitarias', 'id')->where('tipo', $tipo)->where('ativa', true)],
            'produto_sanitario_id' => ['nullable', 'integer',
                Rule::exists('produtos_sanitarios', 'id')->where('ativo', true)->where(fn (Builder $q) => $q->whereIn(
                    'categoria_sanitaria_id',
                    CategoriaSanitaria::where('tipo', $tipo)->pluck('id'),
                ))],
            // Campo livre: dispensado só quando um produto cadastrado é escolhido.
            'nome_vacina' => ['required_without:produto_sanitario_id', 'nullable', 'string', 'max:100'],
            'data_aplicacao' => ['required', 'date', 'before_or_equal:today', ...$this->limiteDoCaseiro()],
            'dias_carencia_leite_carne' => ['required', 'integer', 'min:0', 'max:3650'],
        ];
    }

    public function attributes(): array
    {
        return [
            'categoria_sanitaria_id' => 'categoria',
            'produto_sanitario_id' => 'produto',
            'nome_vacina' => 'nome',
        ];
    }

    public function messages(): array
    {
        return [
            ...$this->mensagemLimiteDoCaseiro('data_aplicacao'),
            'categoria_sanitaria_id.exists' => 'Esta categoria não está ativa ou é de outro tipo.',
            'produto_sanitario_id.exists' => 'Este produto não está ativo ou é de outro tipo.',
        ];
    }
}

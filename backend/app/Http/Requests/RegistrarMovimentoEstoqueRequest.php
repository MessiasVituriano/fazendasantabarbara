<?php

namespace App\Http\Requests;

use App\Enums\Perfil;
use App\Enums\TipoMovimentoEstoque;
use App\Http\Requests\Concerns\LimitaDataDoCaseiro;
use App\Http\Requests\Concerns\RegrasDaFazenda;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Entrada e saída valem para os dois perfis; o caseiro não informa preço nem faz ajuste
 * de inventário (corrigir o saldo fica com o gestor).
 */
class RegistrarMovimentoEstoqueRequest extends FormRequest
{
    use LimitaDataDoCaseiro, RegrasDaFazenda;

    protected function prepareForValidation(): void
    {
        foreach (['quantidade', 'saldo_contado', 'valor_unitario'] as $campo) {
            $valor = $this->input($campo);
            if (is_string($valor) && str_contains($valor, ',')) {
                $this->merge([$campo => str_replace(',', '.', str_replace('.', '', $valor))]);
            }
        }
    }

    public function rules(): array
    {
        $gestor = $this->user()?->perfil === Perfil::GESTOR;
        $tipos = $gestor ? TipoMovimentoEstoque::cases() : [TipoMovimentoEstoque::ENTRADA, TipoMovimentoEstoque::SAIDA];
        $ajuste = $this->input('tipo') === TipoMovimentoEstoque::AJUSTE->value;

        return [
            'uuid' => ['nullable', 'uuid'],
            'item_estoque_id' => ['required', 'integer', $this->existeNaFazenda('itens_estoque')],
            'tipo' => ['required', Rule::in(array_column($tipos, 'value'))],
            'data_movimento' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', ...$this->limiteDoCaseiro()],
            'quantidade' => $ajuste ? ['prohibited'] : ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:99999999999'],
            'saldo_contado' => $ajuste ? ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:99999999999'] : ['prohibited'],
            'valor_unitario' => $gestor && $this->input('tipo') === TipoMovimentoEstoque::ENTRADA->value
                ? ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999']
                : ['prohibited'],
            // Destino da saída (ração do lote, por exemplo). Entradas e ajustes não têm lote.
            'lote_id' => $this->input('tipo') === TipoMovimentoEstoque::SAIDA->value
                ? ['nullable', 'integer', $this->existeNaFazenda('lotes')]
                : ['prohibited'],
            'observacao' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'item_estoque_id' => 'item',
            'lote_id' => 'lote',
            'data_movimento' => 'data',
            'saldo_contado' => 'saldo contado',
            'valor_unitario' => 'valor unitário',
            'observacao' => 'observação',
        ];
    }

    public function messages(): array
    {
        return [
            ...$this->mensagemLimiteDoCaseiro('data_movimento'),
            'tipo.in' => 'Ajuste de inventário só pode ser feito pelo gestor.',
        ];
    }
}

<?php

namespace App\Models;

use App\Casts\Data;
use App\Enums\SituacaoLancamento;
use App\Enums\TipoContaFinanceira;
use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ContaFinanceira extends Model
{
    use Auditavel, HasFactory;

    protected $table = 'contas_financeiras';

    protected $fillable = ['nome', 'tipo', 'saldo_inicial', 'data_saldo_inicial', 'ativa'];

    protected $attributes = ['ativa' => true, 'saldo_inicial' => 0];

    protected function casts(): array
    {
        return [
            'tipo' => TipoContaFinanceira::class,
            'saldo_inicial' => 'decimal:2',
            'data_saldo_inicial' => Data::class,
            'ativa' => 'boolean',
        ];
    }

    public function lancamentos(): HasMany
    {
        return $this->hasMany(Lancamento::class);
    }

    public function movimentosCapital(): HasMany
    {
        return $this->hasMany(MovimentoCapital::class);
    }

    /**
     * Saldo de cada conta até a data (inclusive): saldo inicial + recebido − pago + aportes − retiradas.
     * Só entra o que foi efetivamente pago (situação PAGO); previsões nunca alteram saldo.
     *
     * @return array<int, float> [conta_id => saldo]
     */
    public static function saldos(?string $ate = null): array
    {
        $movimento = DB::table('lancamentos')
            ->where('situacao', SituacaoLancamento::PAGO->value)
            ->when($ate, fn ($q) => $q->where('data_pagamento', '<=', $ate))
            ->groupBy('conta_financeira_id')
            ->selectRaw("conta_financeira_id, SUM(CASE WHEN tipo = 'RECEITA' THEN valor_pago ELSE -valor_pago END) AS liquido")
            ->pluck('liquido', 'conta_financeira_id');

        $capital = DB::table('movimentos_capital')
            ->whereNull('cancelado_em')
            ->when($ate, fn ($q) => $q->where('data_movimento', '<=', $ate))
            ->groupBy('conta_financeira_id')
            ->selectRaw("conta_financeira_id, SUM(CASE WHEN tipo = 'APORTE' THEN valor ELSE -valor END) AS liquido")
            ->pluck('liquido', 'conta_financeira_id');

        return self::query()
            ->when($ate, fn ($q) => $q->where('data_saldo_inicial', '<=', $ate))
            ->get(['id', 'saldo_inicial'])
            ->mapWithKeys(fn ($c) => [$c->id => round((float) $c->saldo_inicial + (float) ($movimento[$c->id] ?? 0) + (float) ($capital[$c->id] ?? 0), 2)])
            ->all();
    }
}

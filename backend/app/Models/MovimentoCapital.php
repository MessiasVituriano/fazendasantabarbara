<?php

namespace App\Models;

use App\Casts\Data;
use App\Enums\FormaPagamento;
use App\Enums\TipoMovimentoCapital;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceAFazenda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aporte ou retirada de um investidor. É dinheiro que já entrou ou saiu: altera o saldo da conta
 * e o fluxo de caixa, mas não é receita nem despesa (fora do demonstrativo e do custo dos lotes).
 * Nada é excluído nem editado no valor: cancela-se, e a trilha fica em historico_alteracoes.
 */
class MovimentoCapital extends Model
{
    use Auditavel, HasFactory, PertenceAFazenda;

    protected $table = 'movimentos_capital';

    protected $fillable = [
        'tipo', 'pessoa_id', 'valor', 'data_movimento', 'conta_financeira_id', 'forma_pagamento', 'documento', 'observacoes',
        'cancelado_em', 'motivo_cancelamento', 'criado_por', 'atualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimentoCapital::class,
            'forma_pagamento' => FormaPagamento::class,
            'valor' => 'decimal:2',
            'data_movimento' => Data::class,
            'cancelado_em' => 'datetime',
        ];
    }

    public function pessoa(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class);
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_financeira_id');
    }

    public function scopeValidos(Builder $query): void
    {
        $query->whereNull('cancelado_em');
    }
}

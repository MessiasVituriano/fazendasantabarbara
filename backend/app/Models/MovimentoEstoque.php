<?php

namespace App\Models;

use App\Casts\Data;
use App\Enums\TipoMovimentoEstoque;
use App\Models\Concerns\PertenceAFazenda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Entrada, saída ou ajuste de um item. Não é excluído: cancelar desfaz o efeito no saldo. */
class MovimentoEstoque extends Model
{
    use PertenceAFazenda;

    protected $table = 'movimentos_estoque';

    protected $fillable = ['uuid', 'item_estoque_id', 'lote_id', 'tipo', 'data_movimento', 'quantidade', 'valor_unitario', 'custo_unitario', 'observacao', 'user_id'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimentoEstoque::class,
            'data_movimento' => Data::class,
            'quantidade' => 'decimal:3',
            'valor_unitario' => 'decimal:4',
            'custo_unitario' => 'decimal:4',
            'cancelado_em' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemEstoque::class, 'item_estoque_id');
    }

    /** Lote que recebeu a saída (ração, sal...). */
    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function canceladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelado_por');
    }

    /** Quanto o movimento muda o saldo (negativo na saída). */
    public function efeitoNoSaldo(): float
    {
        return $this->tipo === TipoMovimentoEstoque::SAIDA ? -(float) $this->quantidade : (float) $this->quantidade;
    }

    public function scopeValidos(Builder $query): void
    {
        $query->whereNull('cancelado_em');
    }
}

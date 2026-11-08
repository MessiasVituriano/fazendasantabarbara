<?php

namespace App\Models;

use App\Casts\Data;
use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Meta de engorda de um lote: peso médio alvo numa data. A situação (no ritmo, atrasada,
 * atingida...) não é gravada: é calculada a cada consulta pela ProjecaoCrescimento.
 */
class MetaEngorda extends Model
{
    use Auditavel;

    protected $table = 'metas_engorda';

    protected $fillable = ['lote_id', 'peso_alvo_kg', 'data_inicio', 'data_alvo', 'gmd_alvo', 'peso_inicial_kg', 'observacoes'];

    protected function casts(): array
    {
        return [
            'peso_alvo_kg' => 'decimal:2',
            'peso_inicial_kg' => 'decimal:2',
            'gmd_alvo' => 'decimal:3',
            'data_inicio' => Data::class,
            'data_alvo' => Data::class,
            'cancelada_em' => 'datetime',
        ];
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function scopeVigentes(Builder $query): void
    {
        $query->whereNull('cancelada_em');
    }
}

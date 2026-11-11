<?php

namespace App\Models\Concerns;

use App\Models\Fazenda;
use App\Services\ContextoFazenda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de uma fazenda: toda consulta é filtrada pela fazenda ativa (ContextoFazenda) e todo registro novo
 * é gravado nela. A busca por id na URL de um registro de outra fazenda dá 404. Sem fazenda ativa, lança exceção.
 */
trait PertenceAFazenda
{
    public static function bootPertenceAFazenda(): void
    {
        static::addGlobalScope('fazenda', function (Builder $query) {
            $query->where($query->getModel()->qualifyColumn('fazenda_id'), app(ContextoFazenda::class)->idObrigatorio());
        });

        static::creating(function (self $model) {
            $model->fazenda_id ??= app(ContextoFazenda::class)->idObrigatorio();
        });
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }
}

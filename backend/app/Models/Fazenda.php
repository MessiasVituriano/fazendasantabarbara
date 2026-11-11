<?php

namespace App\Models;

use Database\Factories\FazendaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fazenda extends Model
{
    /** @use HasFactory<FazendaFactory> */
    use HasFactory;

    protected $table = 'fazendas';

    protected $fillable = ['cliente_id', 'nome', 'municipio', 'uf', 'documento', 'ativa'];

    protected function casts(): array
    {
        return ['ativa' => 'boolean'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function vinculos(): HasMany
    {
        return $this->hasMany(FazendaUsuario::class);
    }

    /** Fazendas ativas de clientes ativos: as únicas em que se entra. */
    public function scopeAcessiveis(Builder $query): void
    {
        $query->where('ativa', true)->whereHas('cliente', fn (Builder $q) => $q->where('ativo', true));
    }
}

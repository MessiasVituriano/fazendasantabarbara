<?php

namespace App\Models;

use App\Enums\Perfil;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Convite para entrar numa fazenda com um perfil. Aberto por link (token) sem login: por isso não usa o filtro
 * por fazenda ativa e toda consulta filtra a fazenda explicitamente.
 */
class Convite extends Model
{
    protected $table = 'convites';

    protected $fillable = ['fazenda_id', 'perfil', 'dono', 'nome', 'email', 'telefone', 'convidado_por', 'expira_em'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'perfil' => Perfil::class,
            'dono' => 'boolean',
            'aceito_em' => 'datetime',
            'cancelado_em' => 'datetime',
            'expira_em' => 'datetime',
        ];
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function convidadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'convidado_por');
    }

    /** Ainda pode ser aceito. */
    public function scopePendentes(Builder $query): void
    {
        $query->whereNull('aceito_em')->whereNull('cancelado_em')->where('expira_em', '>', now());
    }
}

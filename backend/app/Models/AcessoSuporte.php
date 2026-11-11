<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrada do administrador da plataforma numa fazenda de cliente, para suporte: tem motivo e prazo e fica registrada.
 * O que ele alterar nesse período entra no histórico com o usuário dele.
 */
class AcessoSuporte extends Model
{
    public const HORAS = 2;

    protected $table = 'acessos_suporte';

    protected $fillable = ['user_id', 'fazenda_id', 'motivo', 'expira_em'];

    protected function casts(): array
    {
        return ['expira_em' => 'datetime', 'encerrado_em' => 'datetime'];
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeAtivos(Builder $query): void
    {
        $query->whereNull('encerrado_em')->where('expira_em', '>', now());
    }
}

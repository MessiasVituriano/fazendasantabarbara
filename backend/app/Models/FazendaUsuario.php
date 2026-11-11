<?php

namespace App\Models;

use App\Enums\Perfil;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Acesso de um usuário a uma fazenda, com o perfil que ele tem nela. ativo = false: sem acesso a esta fazenda. */
class FazendaUsuario extends Model
{
    protected $table = 'fazenda_usuario';

    protected $fillable = ['fazenda_id', 'user_id', 'perfil', 'ativo'];

    protected function casts(): array
    {
        return ['perfil' => Perfil::class, 'ativo' => 'boolean'];
    }

    public function fazenda(): BelongsTo
    {
        return $this->belongsTo(Fazenda::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Vínculos ativos com fazendas em que se pode entrar. */
    public function scopeAcessiveis(Builder $query): void
    {
        $query->where('ativo', true)->whereHas('fazenda', fn (Builder $q) => $q->acessiveis());
    }
}

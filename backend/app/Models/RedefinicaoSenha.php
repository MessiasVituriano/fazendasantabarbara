<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Link de uso único para definir uma nova senha (pedido pelo próprio usuário ou gerado pelo gestor). */
class RedefinicaoSenha extends Model
{
    protected $table = 'redefinicoes_senha';

    protected $fillable = ['user_id', 'token_hash', 'gerado_por', 'expira_em'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expira_em' => 'datetime', 'usado_em' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

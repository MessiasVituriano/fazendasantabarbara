<?php

namespace App\Models;

use Database\Factories\ClienteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Produtor que assina o sistema; tem uma ou mais fazendas. */
class Cliente extends Model
{
    /** @use HasFactory<ClienteFactory> */
    use HasFactory;

    protected $table = 'clientes';

    protected $fillable = ['nome', 'documento', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function fazendas(): HasMany
    {
        return $this->hasMany(Fazenda::class);
    }

    /** Quem gerencia o cliente: cadastra fazendas, convida usuários e exporta os dados. */
    public function donos(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'cliente_usuario')->withPivot('papel')->withTimestamps();
    }
}

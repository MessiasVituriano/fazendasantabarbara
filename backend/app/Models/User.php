<?php

namespace App\Models;

use App\Enums\Perfil;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'usuario', 'email', 'telefone', 'perfil', 'ativo', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'perfil' => Perfil::class,
            'ativo' => 'boolean',
        ];
    }

    /** Usuário de login sempre em minúsculas e sem espaços nas pontas. */
    public function setUsuarioAttribute(?string $valor): void
    {
        $this->attributes['usuario'] = $valor === null ? null : mb_strtolower(trim($valor));
    }

    /** Telefone guardado só com dígitos (e "+" inicial); a formatação fica com o front. */
    public function setTelefoneAttribute(?string $valor): void
    {
        $limpo = $valor === null ? '' : preg_replace('/(?!^\+)\D/', '', trim($valor));
        $this->attributes['telefone'] = $limpo === '' ? null : $limpo;
    }

    public function ehGestor(): bool
    {
        return $this->perfil === Perfil::GESTOR;
    }
}

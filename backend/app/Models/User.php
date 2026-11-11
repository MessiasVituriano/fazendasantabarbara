<?php

namespace App\Models;

use App\Enums\Perfil;
use App\Services\ContextoFazenda;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Login global; o perfil vale por fazenda (FazendaUsuario). `ativo` é o bloqueio geral do login,
 * feito pela plataforma; o gestor desativa o acesso à fazenda dele, no vínculo.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'usuario', 'email', 'telefone', 'ativo', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'ativo' => 'boolean',
            'admin_plataforma' => 'boolean',
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

    public function vinculos(): HasMany
    {
        return $this->hasMany(FazendaUsuario::class);
    }

    /** Clientes que o usuário gerencia (papel DONO). */
    public function clientesGerenciados(): BelongsToMany
    {
        return $this->belongsToMany(Cliente::class, 'cliente_usuario')->withPivot('papel')->withTimestamps();
    }

    public function ehDonoDe(Cliente|int|null $cliente): bool
    {
        $id = $cliente instanceof Cliente ? $cliente->id : $cliente;

        return $id !== null && $this->clientesGerenciados()->whereKey($id)->exists();
    }

    /** Tem acesso a fazenda de outro cliente (ex.: veterinário que atende vários produtores)? */
    public function acessaOutroCliente(int $clienteId): bool
    {
        return $this->vinculos()->whereHas('fazenda', fn ($q) => $q->where('cliente_id', '!=', $clienteId))->exists();
    }

    public function acessosSuporte(): HasMany
    {
        return $this->hasMany(AcessoSuporte::class);
    }

    /** Vínculo com a fazenda ativa (null fora de uma fazenda ou sem acesso a ela). */
    public function vinculoNaFazendaAtual(): ?FazendaUsuario
    {
        $contexto = app(ContextoFazenda::class);
        $fazendaId = $contexto->id();
        if ($fazendaId === null) {
            return null;
        }
        if ($this->relationLoaded('vinculos')) {
            return $this->vinculos->firstWhere('fazenda_id', $fazendaId);
        }
        if ($contexto->vinculo()?->user_id === $this->id) {
            return $contexto->vinculo();
        }

        return $this->vinculos()->where('fazenda_id', $fazendaId)->first();
    }

    /** Perfil na fazenda ativa. Sem cache: a mesma instância pode atravessar trocas de fazenda. */
    protected function perfil(): Attribute
    {
        return Attribute::get(fn (): ?Perfil => $this->vinculoNaFazendaAtual()?->perfil)->withoutObjectCaching();
    }

    public function ehGestor(): bool
    {
        return $this->perfil === Perfil::GESTOR;
    }

    /** Dá (ou atualiza) o acesso à fazenda com o perfil informado. */
    public function vincular(Fazenda|int $fazenda, Perfil $perfil, bool $ativo = true): FazendaUsuario
    {
        $vinculo = $this->vinculos()->updateOrCreate(
            ['fazenda_id' => $fazenda instanceof Fazenda ? $fazenda->id : $fazenda],
            ['perfil' => $perfil, 'ativo' => $ativo],
        );
        $this->unsetRelation('vinculos');

        return $vinculo;
    }
}

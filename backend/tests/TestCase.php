<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected ?User $usuarioLogado = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Por padrão os testes rodam como gestor; use entrarComo() para outro perfil.
        if (in_array(RefreshDatabase::class, class_uses_recursive($this))) {
            $this->entrarComo(User::factory()->gestor()->create());
        }
    }

    protected function entrarComo(User $usuario): User
    {
        Sanctum::actingAs($usuario, [$usuario->perfil->value]);

        return $this->usuarioLogado = $usuario;
    }
}

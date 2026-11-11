<?php

namespace Tests;

use App\Models\Fazenda;
use App\Models\User;
use App\Services\ContextoFazenda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected ?User $usuarioLogado = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Por padrão os testes rodam como gestor da fazenda 1 (criada pela migration); use entrarComo() para outro perfil
        // e naFazenda() para criar registros em outra fazenda.
        if (in_array(RefreshDatabase::class, class_uses_recursive($this))) {
            app(ContextoFazenda::class)->definir(Fazenda::findOrFail(1));
            $this->entrarComo(User::factory()->gestor()->create());
        }
    }

    protected function entrarComo(User $usuario): User
    {
        Sanctum::actingAs($usuario);

        return $this->usuarioLogado = $usuario;
    }

    /**
     * Executa o callback com outra fazenda ativa (para montar dados dela) e volta para a fazenda anterior.
     *
     * @template T
     *
     * @param  callable(Fazenda): T  $callback
     * @return T
     */
    protected function naFazenda(Fazenda $fazenda, callable $callback): mixed
    {
        return app(ContextoFazenda::class)->executarEm($fazenda, $callback);
    }
}

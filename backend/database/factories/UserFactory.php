<?php

namespace Database\Factories;

use App\Enums\Perfil;
use App\Models\User;
use App\Services\ContextoFazenda;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'usuario' => fake()->unique()->userName(),
            'perfil' => Perfil::GESTOR,
            'ativo' => true,
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function gestor(): static
    {
        return $this->state(['perfil' => Perfil::GESTOR]);
    }

    public function caseiro(): static
    {
        return $this->state(['perfil' => Perfil::CASEIRO, 'email' => null]);
    }

    /**
     * O perfil vale por fazenda: sai dos atributos antes de gravar e vira o vínculo com a fazenda ativa
     * (a fazenda 1 quando não há uma definida).
     */
    public function configure(): static
    {
        return $this
            ->afterMaking(function (User $usuario) {
                $perfil = $usuario->getAttributes()['perfil'] ?? Perfil::GESTOR;
                $usuario->offsetUnset('perfil');
                self::perfis()[$usuario] = $perfil instanceof Perfil ? $perfil : Perfil::from($perfil);
            })
            ->afterCreating(function (User $usuario) {
                $usuario->vincular(app(ContextoFazenda::class)->id() ?? 1, self::perfis()[$usuario] ?? Perfil::GESTOR);
            });
    }

    /** @return \WeakMap<User, Perfil> */
    private static function perfis(): \WeakMap
    {
        static $perfis = new \WeakMap;

        return $perfis;
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}

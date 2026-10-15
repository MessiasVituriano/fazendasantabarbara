<?php

namespace Database\Factories;

use App\Enums\Aptidao;
use App\Enums\Categoria;
use App\Enums\Sexo;
use App\Enums\StatusGeral;
use App\Enums\StatusReprodutivo;
use App\Models\Animal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Animal> */
class AnimalFactory extends Factory
{
    protected $model = Animal::class;

    public function definition(): array
    {
        return [
            'brinco' => 'BR-'.$this->faker->unique()->numerify('#####'),
            'nome' => $this->faker->optional()->firstNameFemale(),
            'sexo' => Sexo::F,
            'data_nascimento' => $this->faker->dateTimeBetween('-6 years', '-2 years'),
            'aptidao' => Aptidao::LEITE,
            'categoria' => Categoria::LACTACAO,
            'status_reprodutivo' => StatusReprodutivo::APTA,
            'status_geral' => StatusGeral::ATIVO,
        ];
    }

    public function corte(): static
    {
        return $this->state(['aptidao' => Aptidao::CORTE, 'categoria' => Categoria::NOVILHA]);
    }

    public function boiGordo(): static
    {
        return $this->state([
            'sexo' => Sexo::M, 'aptidao' => Aptidao::CORTE, 'categoria' => Categoria::BOI_GORDO,
            'status_reprodutivo' => StatusReprodutivo::INAPTA, 'nome' => null,
        ]);
    }

    public function bezerro(Sexo $sexo, int $idadeDias): static
    {
        return $this->state([
            'sexo' => $sexo, 'categoria' => Categoria::BEZERRO,
            'status_reprodutivo' => StatusReprodutivo::INAPTA,
            'data_nascimento' => now()->subDays($idadeDias)->toDateString(),
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\TipoItemEstoque;
use App\Enums\UnidadeEstoque;
use App\Models\ItemEstoque;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ItemEstoque> */
class ItemEstoqueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nome' => fake()->unique()->words(3, true),
            'tipo' => TipoItemEstoque::RACAO,
            'unidade' => UnidadeEstoque::KG,
            'estoque_minimo' => 0,
        ];
    }

    public function medicamento(): static
    {
        return $this->state(['tipo' => TipoItemEstoque::MEDICAMENTO, 'unidade' => UnidadeEstoque::FRASCO]);
    }
}

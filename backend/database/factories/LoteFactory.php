<?php

namespace Database\Factories;

use App\Models\Lote;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Lote> */
class LoteFactory extends Factory
{
    protected $model = Lote::class;

    public function definition(): array
    {
        return [
            'nome' => 'Lote '.$this->faker->unique()->numerify('###'),
            'data_formacao' => now()->subMonths(6)->toDateString(),
            'categoria' => 'Garrotes de recria',
        ];
    }

    public function encerrado(): static
    {
        return $this->state(['data_encerramento' => now()->subDay()->toDateString()]);
    }
}

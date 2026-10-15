<?php

namespace Database\Factories;

use App\Enums\StatusPiquete;
use App\Models\Piquete;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Piquete> */
class PiqueteFactory extends Factory
{
    protected $model = Piquete::class;

    public function definition(): array
    {
        return [
            'identificacao' => 'P-'.$this->faker->unique()->numberBetween(1, 999),
            'hectares' => $this->faker->randomFloat(2, 1, 40),
            'status' => StatusPiquete::DESCANSO,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Cliente;
use App\Models\Fazenda;
use App\Services\ServicoFazenda;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fazendas de teste. Use comCatalogo() quando o teste lançar no financeiro ou na sanidade: cria as categorias padrão.
 *
 * @extends Factory<Fazenda>
 */
class FazendaFactory extends Factory
{
    public function definition(): array
    {
        return ['cliente_id' => Cliente::factory(), 'nome' => 'Fazenda '.fake()->lastName(), 'uf' => 'MG', 'ativa' => true];
    }

    public function comCatalogo(): static
    {
        return $this->afterCreating(function (Fazenda $fazenda) {
            app(ServicoFazenda::class)->criarCatalogo($fazenda);
        });
    }
}

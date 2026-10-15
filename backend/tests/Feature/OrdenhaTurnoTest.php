<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\ProducaoLeite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrdenhaTurnoTest extends TestCase
{
    use RefreshDatabase;

    public function test_turno_cria_corrige_e_remove_registros(): void
    {
        [$a, $b, $c] = Animal::factory()->count(3)->create();
        $hoje = today()->toDateString();
        ProducaoLeite::create(['animal_id' => $b->id, 'data_pesagem' => $hoje, 'periodo' => 'MANHA', 'quantidade_litros' => 10]);
        ProducaoLeite::create(['animal_id' => $c->id, 'data_pesagem' => $hoje, 'periodo' => 'MANHA', 'quantidade_litros' => 7]);
        ProducaoLeite::create(['animal_id' => $c->id, 'data_pesagem' => $hoje, 'periodo' => 'TARDE', 'quantidade_litros' => 6]);

        $this->putJson('/api/producoes-leite/turno', [
            'data_pesagem' => $hoje, 'periodo' => 'MANHA',
            'registros' => [
                ['animal_id' => $a->id, 'quantidade_litros' => 12.5],  // novo
                ['animal_id' => $b->id, 'quantidade_litros' => 11],    // correção
                ['animal_id' => $c->id, 'quantidade_litros' => null],  // apagado
            ],
        ])->assertOk()
            ->assertJsonPath('meta.gravados', 2)
            ->assertJsonPath('meta.removidos', 1)
            ->assertJsonPath('meta.total_litros', 23.5)
            ->assertJsonCount(2, 'data');

        $this->assertEquals(11, ProducaoLeite::where(['animal_id' => $b->id, 'periodo' => 'MANHA'])->value('quantidade_litros'));
        $this->assertSame(1, ProducaoLeite::where('animal_id', $c->id)->count()); // a tarde não foi tocada
    }

    public function test_turno_exige_periodo_e_rejeita_macho(): void
    {
        $boi = Animal::factory()->boiGordo()->create(['brinco' => 'BOI']);

        $this->putJson('/api/producoes-leite/turno', ['data_pesagem' => today()->toDateString(), 'registros' => [['animal_id' => $boi->id, 'quantidade_litros' => 1]]])
            ->assertStatus(422)->assertJsonPath('errors.0.source.pointer', '/periodo');

        $this->putJson('/api/producoes-leite/turno', ['data_pesagem' => today()->toDateString(), 'periodo' => 'TARDE', 'registros' => [['animal_id' => $boi->id, 'quantidade_litros' => 1]]])
            ->assertStatus(422)->assertJsonPath('errors.0.detail', 'O animal BOI é macho e não pode ter produção de leite.');
    }

    public function test_listagem_filtra_por_periodo(): void
    {
        $vaca = Animal::factory()->create();
        ProducaoLeite::create(['animal_id' => $vaca->id, 'data_pesagem' => today(), 'periodo' => 'MANHA', 'quantidade_litros' => 10]);
        ProducaoLeite::create(['animal_id' => $vaca->id, 'data_pesagem' => today(), 'periodo' => 'TARDE', 'quantidade_litros' => 8]);

        $this->getJson('/api/producoes-leite?periodo=TARDE')->assertJsonCount(1, 'data')->assertJsonPath('data.0.periodo', 'TARDE');
    }

    public function test_comparativo_por_intervalo_livre(): void
    {
        $a = Animal::factory()->create(['brinco' => 'A']);
        foreach ([[10, 20], [5, 10], [4, 30]] as [$diasAtras, $litros]) {
            ProducaoLeite::create(['animal_id' => $a->id, 'data_pesagem' => today()->subDays($diasAtras), 'periodo' => 'MANHA', 'quantidade_litros' => $litros]);
        }
        $q = http_build_query(['inicio' => today()->subDays(5)->toDateString(), 'fim' => today()->toDateString()]);

        $this->getJson("/api/painel/leite/comparativo?{$q}")
            ->assertOk()
            ->assertJsonPath('data.periodo.dias', 6)
            ->assertJsonPath('data.total_litros', 40)
            ->assertJsonPath('data.ranking.0.media_diaria', 20)
            ->assertJsonPath('data.ranking.0.variacao_pct', 0); // anterior: 20 L em 1 dia

        $this->getJson('/api/painel/leite/comparativo?inicio=2026-01-10&fim=2026-01-01')
            ->assertStatus(422)->assertJsonPath('errors.0.detail', 'A data inicial deve ser anterior ou igual à final.');
    }
}

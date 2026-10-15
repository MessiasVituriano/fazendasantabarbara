<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\ProducaoLeite;
use App\Models\VacinaSanidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PainelLeiteTest extends TestCase
{
    use RefreshDatabase;

    private function ordenha(Animal $vaca, int $diasAtras, string $periodo, float $litros): void
    {
        ProducaoLeite::create(['animal_id' => $vaca->id, 'data_pesagem' => today()->subDays($diasAtras), 'periodo' => $periodo, 'quantidade_litros' => $litros]);
    }

    public function test_resume_serie_diaria_ranking_e_comparacao(): void
    {
        $a = Animal::factory()->create(['brinco' => 'A']);
        $b = Animal::factory()->create(['brinco' => 'B']);
        // período atual (7 dias): hoje e ontem
        $this->ordenha($a, 0, 'MANHA', 12);
        $this->ordenha($a, 0, 'TARDE', 8);
        $this->ordenha($b, 0, 'MANHA', 10);
        $this->ordenha($a, 1, 'MANHA', 10);
        // período anterior: 8 dias atrás
        $this->ordenha($a, 8, 'MANHA', 25);

        $r = $this->getJson('/api/painel/leite?dias=7')->assertOk()->json('data');

        $this->assertCount(7, $r['serie']);
        $this->assertSame(['data' => today()->toDateString(), 'manha' => 22, 'tarde' => 8, 'total' => 30, 'vacas' => 2, 'em_carencia' => 0],
            $r['serie'][6]);
        $this->assertEquals(0, $r['serie'][0]['total']); // dias sem ordenha aparecem zerados
        $this->assertEquals(40, $r['resumo']['total_litros']);
        $this->assertEquals(round(40 / 7, 2), $r['resumo']['media_diaria']);
        $this->assertEquals(round(40 / 3, 2), $r['resumo']['media_por_vaca_dia']); // 3 vaca-dias
        $this->assertEquals(60, $r['resumo']['variacao_pct']); // 40 vs 25
        $this->assertSame(['A', 'B'], array_column($r['ranking'], 'brinco'));
        $this->assertEquals(15, $r['ranking'][0]['media_diaria']); // 30 L em 2 dias
        $this->assertEquals(-40, $r['ranking'][0]['variacao_pct']); // 15 vs 25
    }

    public function test_leite_em_carencia_conta_apenas_dias_dentro_da_carencia(): void
    {
        $vaca = Animal::factory()->create();
        VacinaSanidade::create(['animal_id' => $vaca->id, 'nome_vacina' => 'Antibiótico', 'data_aplicacao' => today()->subDays(3), 'dias_carencia_leite_carne' => 2]);
        $this->ordenha($vaca, 4, 'MANHA', 10); // antes da aplicação
        $this->ordenha($vaca, 2, 'MANHA', 11); // em carência
        $this->ordenha($vaca, 1, 'MANHA', 12); // último dia de carência
        $this->ordenha($vaca, 0, 'MANHA', 13); // liberada

        $this->getJson('/api/painel/leite?dias=7')->assertJsonPath('data.resumo.litros_em_carencia', 23);
    }

    public function test_aceita_intervalo_personalizado(): void
    {
        $vaca = Animal::factory()->create();
        $this->ordenha($vaca, 10, 'MANHA', 8);   // fora do intervalo
        $this->ordenha($vaca, 3, 'MANHA', 12);
        $this->ordenha($vaca, 2, 'TARDE', 9);
        $q = http_build_query(['inicio' => today()->subDays(4)->toDateString(), 'fim' => today()->subDays(1)->toDateString()]);

        $r = $this->getJson("/api/painel/leite?{$q}")->assertOk()->json('data');

        $this->assertSame(4, $r['periodo']['dias']);
        $this->assertCount(4, $r['serie']);
        $this->assertSame(today()->subDays(1)->toDateString(), $r['periodo']['fim']);
        $this->assertEquals(21, $r['resumo']['total_litros']);

        $this->getJson('/api/painel/leite?inicio=2026-01-01')->assertStatus(422)->assertJsonFragment(['pointer' => '/fim']);
        $this->getJson('/api/painel/leite?inicio=2024-01-01&fim=2026-01-01')->assertStatus(422)->assertJsonPath('errors.0.code', 'INTERVALO_LONGO');
    }

    public function test_periodo_invalido_retorna_422(): void
    {
        $this->getJson('/api/painel/leite?dias=5')->assertStatus(422)->assertJsonPath('errors.0.source.pointer', '/dias');
    }
}

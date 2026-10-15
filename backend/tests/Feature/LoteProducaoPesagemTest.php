<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Pesagem;
use App\Models\ProducaoLeite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoteProducaoPesagemTest extends TestCase
{
    use RefreshDatabase;

    public function test_producao_aceita_array_json_em_lote(): void
    {
        $vacas = Animal::factory()->count(2)->create();

        $this->postJson('/api/producoes-leite', [
            ['animal_id' => $vacas[0]->id, 'data_pesagem' => '2026-01-01', 'periodo' => 'MANHA', 'quantidade_litros' => 12.5],
            ['animal_id' => $vacas[0]->id, 'data_pesagem' => '2026-01-01', 'periodo' => 'TARDE', 'quantidade_litros' => 9.25],
            ['animal_id' => $vacas[1]->id, 'data_pesagem' => '2026-01-01', 'periodo' => 'MANHA', 'quantidade_litros' => 15],
        ])->assertCreated()->assertJsonCount(3, 'data')->assertJsonPath('meta.criados', 3);

        $this->assertSame(3, ProducaoLeite::count());
    }

    public function test_producao_aceita_registro_unico(): void
    {
        $vaca = Animal::factory()->create();

        $this->postJson('/api/producoes-leite', [
            'animal_id' => $vaca->id, 'data_pesagem' => '2026-01-01', 'periodo' => 'MANHA', 'quantidade_litros' => 10,
        ])->assertCreated();
    }

    public function test_macho_nao_tem_producao_de_leite(): void
    {
        $boi = Animal::factory()->boiGordo()->create(['brinco' => 'BOI-1']);

        $this->postJson('/api/producoes-leite', [
            'animal_id' => $boi->id, 'data_pesagem' => '2026-01-01', 'periodo' => 'MANHA', 'quantidade_litros' => 10,
        ])->assertStatus(422)->assertJsonPath('errors.0.detail', 'O animal BOI-1 é macho e não pode ter produção de leite.');
    }

    public function test_duplicidade_retorna_409_legivel(): void
    {
        $vaca = Animal::factory()->create();
        $registro = ['animal_id' => $vaca->id, 'data_pesagem' => '2026-01-01', 'periodo' => 'MANHA', 'quantidade_litros' => 10];
        $this->postJson('/api/producoes-leite', $registro)->assertCreated();

        $this->postJson('/api/producoes-leite', $registro)
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/vnd.api+json')
            ->assertJsonPath('errors.0.code', 'REGISTRO_DUPLICADO');
    }

    public function test_pesagem_em_lote_idempotente_por_uuid(): void
    {
        $boi = Animal::factory()->boiGordo()->create();
        $lote = ['registros' => [
            ['uuid' => (string) Str::uuid(), 'animal_id' => $boi->id, 'peso_kg' => 400, 'data_pesagem' => '2026-01-01'],
            ['uuid' => (string) Str::uuid(), 'animal_id' => $boi->id, 'peso_kg' => 430, 'data_pesagem' => '2026-01-31'],
        ]];

        $this->postJson('/api/pesagens', $lote)->assertCreated()->assertJsonPath('data.1.gmd_kg_dia', 1);
        $this->postJson('/api/pesagens', $lote)->assertOk()->assertJsonPath('meta.ja_sincronizados', 2);

        $this->assertSame(2, Pesagem::count());
    }

    public function test_gmd_calculado_entre_pesagem_atual_e_anterior(): void
    {
        $boi = Animal::factory()->boiGordo()->create();
        foreach ([['2026-01-01', 300], ['2026-01-21', 316], ['2026-02-20', 340]] as [$data, $peso]) {
            Pesagem::create(['animal_id' => $boi->id, 'data_pesagem' => $data, 'peso_kg' => $peso]);
        }

        $this->getJson("/api/pesagens?animal_id={$boi->id}")
            ->assertOk()
            ->assertJsonPath('data.0.gmd_kg_dia', 0.8)    // (340-316)/30
            ->assertJsonPath('data.1.gmd_kg_dia', 0.8)    // (316-300)/20
            ->assertJsonPath('data.2.gmd_kg_dia', null);  // primeira pesagem

        $ultima = Pesagem::where('data_pesagem', '2026-02-20')->first();
        $this->assertSame(0.8, $ultima->gmd());
        $this->assertSame(1.5, Pesagem::calcularGmd(345, '2026-01-11', 330, '2026-01-01'));
        $this->assertNull(Pesagem::calcularGmd(345, '2026-01-01', 330, '2026-01-01'));
    }
}

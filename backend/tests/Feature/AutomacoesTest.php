<?php

namespace Tests\Feature;

use App\Enums\Sexo;
use App\Models\Animal;
use App\Models\Piquete;
use App\Models\VacinaSanidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomacoesTest extends TestCase
{
    use RefreshDatabase;

    public function test_desmame_migra_bezerros_pela_idade_da_config(): void
    {
        config(['pecuaria.dias_desmame_bezerro' => 210]);
        $femea = Animal::factory()->bezerro(Sexo::F, 210)->create();
        $macho = Animal::factory()->bezerro(Sexo::M, 300)->create();
        $novo = Animal::factory()->bezerro(Sexo::M, 209)->create();
        $vendido = Animal::factory()->bezerro(Sexo::M, 400)->create(['status_geral' => 'VENDIDO']);

        $this->artisan('pecuaria:desmame')->assertSuccessful();

        $this->assertSame('NOVILHA', $femea->refresh()->categoria->value);
        $this->assertSame('GARROTE', $macho->refresh()->categoria->value);
        $this->assertSame('BEZERRO', $novo->refresh()->categoria->value);
        $this->assertSame('BEZERRO', $vendido->refresh()->categoria->value);
    }

    public function test_desmame_esta_agendado_diariamente(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('pecuaria:desmame')->assertSuccessful();
    }

    public function test_ordenha_marca_alerta_critico_para_animal_em_carencia(): void
    {
        $tratada = Animal::factory()->create(['brinco' => 'TRATADA']);
        $liberada = Animal::factory()->create(['brinco' => 'LIBERADA']);
        Animal::factory()->create(['categoria' => 'SECA']);
        VacinaSanidade::create(['animal_id' => $tratada->id, 'nome_vacina' => 'Antibiótico', 'data_aplicacao' => today()->subDays(3), 'dias_carencia_leite_carne' => 3]);
        VacinaSanidade::create(['animal_id' => $liberada->id, 'nome_vacina' => 'Aftosa', 'data_aplicacao' => today()->subDays(10), 'dias_carencia_leite_carne' => 9]);

        $this->getJson('/api/manejo/ordenha')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.em_carencia', 1)
            ->assertJsonPath('data.0.brinco', 'TRATADA')
            ->assertJsonPath('data.0.alerta.nivel', 'CRITICO')
            ->assertJsonPath('data.0.carencia_ate', today()->toDateString())
            ->assertJsonPath('data.1.brinco', 'LIBERADA')
            ->assertJsonPath('data.1.alerta', null);

        $this->getJson('/api/manejo/ordenha?ocultar_em_carencia=1')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.brinco', 'LIBERADA');
    }

    public function test_abate_respeita_carencia(): void
    {
        $boi = Animal::factory()->boiGordo()->create();
        Animal::factory()->boiGordo()->create();
        VacinaSanidade::create(['animal_id' => $boi->id, 'nome_vacina' => 'Ivermectina', 'data_aplicacao' => today(), 'dias_carencia_leite_carne' => 35]);

        $this->getJson('/api/manejo/abate')->assertJsonCount(2, 'data')->assertJsonPath('data.0.em_carencia', true);
        $this->getJson('/api/manejo/abate?ocultar_em_carencia=1')->assertJsonCount(1, 'data');
    }

    public function test_piquetes_contam_animais_alocados_e_movimentam(): void
    {
        $p1 = Piquete::factory()->create(['identificacao' => 'P-1']);
        $p2 = Piquete::factory()->create(['identificacao' => 'P-2']);
        $animais = Animal::factory()->count(3)->create();

        $this->postJson("/api/piquetes/{$p1->id}/entradas", ['animal_ids' => $animais->pluck('id'), 'data_entrada' => today()->toDateString()])
            ->assertOk()->assertJsonPath('data.animais_alocados', 3)->assertJsonPath('data.status', 'OCUPADO');

        // Mover um animal fecha a ocupação anterior.
        $this->postJson("/api/piquetes/{$p2->id}/entradas", ['animal_ids' => [$animais[0]->id], 'data_entrada' => today()->toDateString()]);

        $this->getJson('/api/piquetes')
            ->assertJsonPath('data.0.animais_alocados', 2)
            ->assertJsonPath('data.1.animais_alocados', 1);

        // Esvaziar coloca o piquete em descanso.
        $this->postJson("/api/piquetes/{$p1->id}/saidas", ['data_saida' => today()->toDateString()])
            ->assertJsonPath('data.animais_alocados', 0)
            ->assertJsonPath('data.status', 'DESCANSO');
    }

    public function test_piquete_degradado_nao_recebe_animais(): void
    {
        $piquete = Piquete::factory()->create(['status' => 'DEGRADADO']);
        $animal = Animal::factory()->create();

        $this->postJson("/api/piquetes/{$piquete->id}/entradas", ['animal_ids' => [$animal->id], 'data_entrada' => today()->toDateString()])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'PIQUETE_DEGRADADO');
    }

    public function test_timeline_agrega_eventos_em_ordem_cronologica(): void
    {
        $vaca = Animal::factory()->create(['data_nascimento' => '2022-01-01']);
        $this->postJson('/api/eventos-reprodutivos', ['animal_id' => $vaca->id, 'tipo_evento' => 'INSEMINACAO', 'data_evento' => '2026-01-10']);
        $this->postJson('/api/pesagens', ['animal_id' => $vaca->id, 'peso_kg' => 500, 'data_pesagem' => '2026-02-01']);
        $this->postJson('/api/producoes-leite', ['animal_id' => $vaca->id, 'data_pesagem' => '2026-01-20', 'periodo' => 'MANHA', 'quantidade_litros' => 10]);
        $this->postJson('/api/producoes-leite', ['animal_id' => $vaca->id, 'data_pesagem' => '2026-01-20', 'periodo' => 'TARDE', 'quantidade_litros' => 8]);

        $this->getJson("/api/animais/{$vaca->id}/timeline")
            ->assertOk()
            ->assertJsonPath('data.0.tipo', 'PESAGEM')
            ->assertJsonPath('data.1.tipo', 'PRODUCAO_LEITE')
            ->assertJsonPath('data.1.titulo', 'Produção de leite: 18,00 L')
            ->assertJsonPath('data.2.tipo', 'EVENTO_REPRODUTIVO')
            ->assertJsonPath('data.3.tipo', 'NASCIMENTO');
    }

    public function test_erros_seguem_padrao_json_api(): void
    {
        $this->getJson('/api/animais/999')
            ->assertNotFound()
            ->assertJsonPath('errors.0.status', '404')
            ->assertJsonPath('errors.0.code', 'NAO_ENCONTRADO');

        $this->postJson('/api/animais', [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => [['status', 'code', 'title', 'detail', 'source' => ['pointer']]]])
            ->assertJsonFragment(['detail' => 'O campo brinco é obrigatório.']);
    }
}

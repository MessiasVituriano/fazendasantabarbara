<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\EventoReprodutivo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class EventoReprodutivoTest extends TestCase
{
    use RefreshDatabase;

    private function evento(Animal $vaca, string $tipo, string $data, array $extra = [])
    {
        return $this->postJson('/api/eventos-reprodutivos', [
            'animal_id' => $vaca->id, 'tipo_evento' => $tipo, 'data_evento' => $data, ...$extra,
        ]);
    }

    public function test_inseminacao_marca_prenhe_a_confirmar_e_projeta_toque(): void
    {
        config(['pecuaria.dias_para_toque' => 40]);
        $vaca = Animal::factory()->create();

        $this->evento($vaca, 'INSEMINACAO', '2026-01-10')->assertCreated();

        $vaca->refresh();
        $this->assertSame('PRENHE_A_CONFIRMAR', $vaca->status_reprodutivo->value);
        $this->assertSame('2026-02-19', $vaca->data_prevista_toque->toDateString());
    }

    public function test_toque_positivo_projeta_parto_e_secagem_pela_config(): void
    {
        config(['pecuaria.dias_gestacao' => 283, 'pecuaria.dias_ante_parto_secagem' => 60]);
        $vaca = Animal::factory()->create();

        $this->evento($vaca, 'INSEMINACAO', '2026-01-10')->assertCreated();
        $this->evento($vaca, 'TOQUE_POSITIVO', '2026-02-14')->assertCreated();

        $vaca->refresh();
        $this->assertSame('GESTANTE', $vaca->status_reprodutivo->value);
        $this->assertSame('2026-10-20', $vaca->data_provavel_parto->toDateString()); // 10/01 + 283
        $this->assertSame('2026-08-21', $vaca->data_prevista_secagem->toDateString()); // parto - 60
        $this->assertNull($vaca->data_prevista_toque);
    }

    public function test_toque_positivo_sem_inseminacao_usa_data_do_toque(): void
    {
        config(['pecuaria.dias_gestacao' => 100, 'pecuaria.dias_ante_parto_secagem' => 10]);
        $vaca = Animal::factory()->create();

        $this->evento($vaca, 'TOQUE_POSITIVO', '2026-01-01')->assertCreated();

        $vaca->refresh();
        $this->assertSame('2026-04-11', $vaca->data_provavel_parto->toDateString());
        $this->assertSame('2026-04-01', $vaca->data_prevista_secagem->toDateString());
    }

    public function test_parto_de_vaca_leiteira_coloca_em_lactacao_e_cria_bezerro(): void
    {
        $vaca = Animal::factory()->create(['categoria' => 'SECA', 'status_reprodutivo' => 'GESTANTE']);

        $this->evento($vaca, 'PARTO', '2026-03-01', ['brinco_bezerro' => 'BZ-1', 'sexo_bezerro' => 'M'])
            ->assertCreated()
            ->assertJsonPath('data.0.bezerro.brinco', 'BZ-1');

        $vaca->refresh();
        $this->assertSame('PARIDA', $vaca->status_reprodutivo->value);
        $this->assertSame('LACTACAO', $vaca->categoria->value);
        $this->assertNull($vaca->data_provavel_parto);

        $bezerro = Animal::firstWhere('brinco', 'BZ-1');
        $this->assertSame('BEZERRO', $bezerro->categoria->value);
        $this->assertSame($vaca->id, $bezerro->mae_id);
        $this->assertSame('2026-03-01', $bezerro->data_nascimento->toDateString());
        $this->assertSame('LEITE', $bezerro->aptidao->value);
    }

    public function test_parto_de_vaca_de_corte_nao_muda_categoria(): void
    {
        $vaca = Animal::factory()->corte()->create();

        $this->evento($vaca, 'PARTO', '2026-03-01', ['brinco_bezerro' => 'BZ-2', 'sexo_bezerro' => 'F'])->assertCreated();

        $this->assertSame('NOVILHA', $vaca->refresh()->categoria->value);
        $this->assertSame('PARIDA', $vaca->status_reprodutivo->value);
    }

    public function test_parto_exige_dados_do_bezerro(): void
    {
        $vaca = Animal::factory()->create();

        $this->evento($vaca, 'PARTO', '2026-03-01')
            ->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/registros/0/brinco_bezerro')
            ->assertJsonPath('errors.0.detail', 'O campo brinco do bezerro é obrigatório quando tipo de evento for PARTO.');
    }

    public function test_dados_do_bezerro_sao_proibidos_fora_do_parto(): void
    {
        $vaca = Animal::factory()->create();

        $this->evento($vaca, 'INSEMINACAO', '2026-03-01', ['brinco_bezerro' => 'X'])->assertStatus(422);
    }

    public function test_parto_falho_desfaz_tudo(): void
    {
        $vaca = Animal::factory()->create(['categoria' => 'SECA', 'status_reprodutivo' => 'GESTANTE']);
        Animal::creating(fn () => throw new RuntimeException('falha simulada'));

        $this->evento($vaca, 'PARTO', '2026-03-01', ['brinco_bezerro' => 'BZ-3', 'sexo_bezerro' => 'F'])
            ->assertStatus(500)
            ->assertJsonPath('errors.0.code', 'ERRO_INTERNO');

        $this->assertSame('GESTANTE', $vaca->refresh()->status_reprodutivo->value);
        $this->assertSame('SECA', $vaca->categoria->value);
        $this->assertSame(0, EventoReprodutivo::count());
    }

    public function test_lote_e_atomico_e_aponta_o_registro_invalido(): void
    {
        $vaca = Animal::factory()->create();
        $boi = Animal::factory()->boiGordo()->create();

        $this->postJson('/api/eventos-reprodutivos', [
            ['animal_id' => $vaca->id, 'tipo_evento' => 'INSEMINACAO', 'data_evento' => '2026-01-01'],
            ['animal_id' => $boi->id, 'tipo_evento' => 'INSEMINACAO', 'data_evento' => '2026-01-01'],
        ])->assertStatus(422)->assertJsonPath('errors.0.source.pointer', '/registros/1/animal_id');

        $this->assertSame(0, EventoReprodutivo::count());
        $this->assertSame('APTA', $vaca->refresh()->status_reprodutivo->value);
    }

    public function test_lote_fora_de_ordem_e_processado_cronologicamente(): void
    {
        $vaca = Animal::factory()->create();

        $this->postJson('/api/eventos-reprodutivos', ['registros' => [
            ['animal_id' => $vaca->id, 'tipo_evento' => 'TOQUE_POSITIVO', 'data_evento' => '2026-02-10'],
            ['animal_id' => $vaca->id, 'tipo_evento' => 'INSEMINACAO', 'data_evento' => '2026-01-05'],
        ]])->assertCreated()->assertJsonPath('meta.criados', 2);

        $this->assertSame('GESTANTE', $vaca->refresh()->status_reprodutivo->value);
    }

    public function test_evento_antigo_sincronizado_depois_nao_regride_status(): void
    {
        $vaca = Animal::factory()->create();
        $this->evento($vaca, 'INSEMINACAO', '2026-01-05');
        $this->evento($vaca, 'TOQUE_POSITIVO', '2026-02-10');

        $this->evento($vaca, 'TOQUE_NEGATIVO', '2025-12-01')->assertCreated();

        $this->assertSame('GESTANTE', $vaca->refresh()->status_reprodutivo->value);
        $this->assertSame(3, $vaca->eventosReprodutivos()->count());
    }

    public function test_reenvio_com_mesmo_uuid_nao_duplica_parto(): void
    {
        $vaca = Animal::factory()->create();
        $uuid = (string) Str::uuid();
        $payload = [['uuid' => $uuid, 'animal_id' => $vaca->id, 'tipo_evento' => 'PARTO',
            'data_evento' => '2026-03-01', 'brinco_bezerro' => 'BZ-9', 'sexo_bezerro' => 'F']];

        $this->postJson('/api/eventos-reprodutivos', $payload)->assertCreated();
        $this->postJson('/api/eventos-reprodutivos', $payload)
            ->assertOk()
            ->assertJsonPath('meta.ja_sincronizados', 1);

        $this->assertSame(1, EventoReprodutivo::count());
        $this->assertSame(1, Animal::where('brinco', 'BZ-9')->count());
    }

    public function test_brinco_do_bezerro_duplicado_e_rejeitado(): void
    {
        Animal::factory()->create(['brinco' => 'JA-EXISTE']);
        $vaca = Animal::factory()->create();

        $this->evento($vaca, 'PARTO', '2026-03-01', ['brinco_bezerro' => 'JA-EXISTE', 'sexo_bezerro' => 'F'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/registros/0/brinco_bezerro');
    }
}

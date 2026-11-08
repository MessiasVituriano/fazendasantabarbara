<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\AnimalLote;
use App\Models\ItemEstoque;
use App\Models\Lote;
use App\Models\MetaEngorda;
use App\Models\Pesagem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EngordaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-01');
    }

    /** Pesagens N dias atrás: [diasAtras => peso]. */
    private function pesar(Animal $animal, array $pesos): void
    {
        foreach ($pesos as $diasAtras => $peso) {
            Pesagem::create(['animal_id' => $animal->id, 'data_pesagem' => now()->subDays($diasAtras)->toDateString(), 'peso_kg' => $peso]);
        }
    }

    private function colocar(Lote $lote, array $animais, string $data = '2026-03-01'): TestResponse
    {
        return $this->postJson("/api/lotes/{$lote->id}/animais", ['animal_ids' => array_map(fn ($a) => $a->id, $animais), 'data_entrada' => $data]);
    }

    /** Lote com dois bois: médias 400 → 422,5 → 445 kg (0,75 kg/dia). */
    private function loteComBois(): array
    {
        $lote = Lote::factory()->create();
        [$a, $b] = Animal::factory()->boiGordo()->count(2)->create();
        $this->pesar($a, [60 => 390, 30 => 412, 0 => 435]);
        $this->pesar($b, [60 => 410, 30 => 433, 0 => 455]);
        $this->colocar($lote, [$a, $b])->assertOk();

        return [$lote, $a, $b];
    }

    public function test_entrada_no_lote_encerra_a_passagem_pelo_lote_anterior(): void
    {
        [$origem, $destino] = Lote::factory()->count(2)->create();
        $boi = Animal::factory()->boiGordo()->create();
        $this->colocar($origem, [$boi], '2026-02-01')->assertOk();

        $this->colocar($destino, [$boi], '2026-04-10')->assertOk()->assertJsonPath('data.resumo.animais', 1);
        $this->colocar($destino, [$boi], '2026-05-01')->assertOk();

        $this->assertSame('2026-04-10', AnimalLote::where('lote_id', $origem->id)->first()->data_saida->toDateString());
        $this->assertSame(1, AnimalLote::where('lote_id', $destino->id)->count());
        $this->assertSame($destino->id, $boi->loteAtual->lote_id);
    }

    public function test_vaca_de_leite_nao_entra_em_lote_de_engorda(): void
    {
        $lote = Lote::factory()->create();
        $vaca = Animal::factory()->create(['brinco' => 'L-001']);

        $this->colocar($lote, [$vaca])->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'ANIMAL_FORA_DA_ENGORDA')
            ->assertJsonPath('errors.0.detail', 'Só animais ativos de corte ou mista entram em lote de engorda: L-001.');
        $this->assertSame(0, AnimalLote::count());
    }

    public function test_lote_encerrado_nao_recebe_animais(): void
    {
        $lote = Lote::factory()->encerrado()->create();

        $this->colocar($lote, [Animal::factory()->boiGordo()->create()])->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'LOTE_ENCERRADO');
    }

    public function test_saida_sem_animais_esvazia_o_lote_e_nao_pode_ser_antes_da_entrada(): void
    {
        [$lote] = $this->loteComBois();

        $this->postJson("/api/lotes/{$lote->id}/saidas", ['data_saida' => '2026-02-01'])->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'DATA_INVALIDA');
        $this->postJson("/api/lotes/{$lote->id}/saidas", ['data_saida' => '2026-05-20'])->assertOk()
            ->assertJsonPath('data.resumo.animais', 0);

        $this->assertSame(0, $lote->ocupacoesAtuais()->count());
    }

    public function test_encerrar_o_lote_tira_os_animais_na_data_do_encerramento(): void
    {
        [$lote] = $this->loteComBois();

        $this->patchJson("/api/lotes/{$lote->id}", ['data_encerramento' => '2026-05-30'])->assertOk();

        $this->assertSame(['2026-05-30'], $lote->historicoAnimais()->get()->map(fn ($o) => $o->data_saida->toDateString())->unique()->values()->all());
    }

    public function test_painel_do_lote_traz_medias_projecao_e_serie(): void
    {
        [$lote] = $this->loteComBois();

        $this->getJson("/api/lotes/{$lote->id}/engorda")->assertOk()
            ->assertJsonPath('data.resumo.animais', 2)
            ->assertJsonPath('data.resumo.gmd_medio', 0.75)
            ->assertJsonPath('data.resumo.peso_medio', 445)
            ->assertJsonPath('data.resumo.projecoes.0.peso', 467.5)
            ->assertJsonPath('data.serie', [
                ['data' => '2026-04-02', 'peso_medio' => 400, 'animais' => 2],
                ['data' => '2026-05-02', 'peso_medio' => 422.5, 'animais' => 2],
                ['data' => '2026-06-01', 'peso_medio' => 445, 'animais' => 2],
            ]);
    }

    public function test_nova_meta_guarda_o_peso_inicial_e_substitui_a_anterior(): void
    {
        [$lote] = $this->loteComBois();
        $this->postJson("/api/lotes/{$lote->id}/metas", ['peso_alvo_kg' => '500', 'data_alvo' => '2026-08-01'])->assertCreated();

        $this->postJson("/api/lotes/{$lote->id}/metas", ['peso_alvo_kg' => '505,5', 'data_alvo' => '2026-08-20'])->assertCreated();

        $this->assertSame(1, $lote->metas()->vigentes()->count());
        $this->assertSame('Substituída por nova meta', $lote->metas()->whereNotNull('cancelada_em')->value('motivo_cancelamento'));
        $this->getJson("/api/lotes/{$lote->id}/engorda")->assertOk()
            ->assertJsonPath('data.meta.peso_alvo_kg', 505.5)
            ->assertJsonPath('data.meta.peso_inicial_kg', 445)
            ->assertJsonPath('data.meta.avaliacao.situacao', 'NO_RITMO')
            ->assertJsonPath('data.meta.avaliacao.gmd_necessario', 0.756);
    }

    public function test_meta_com_data_alvo_no_passado_e_recusada(): void
    {
        $lote = Lote::factory()->create();

        $this->postJson("/api/lotes/{$lote->id}/metas", ['peso_alvo_kg' => 500, 'data_alvo' => '2026-05-01'])->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/data_alvo');
        $this->assertSame(0, MetaEngorda::count());
    }

    public function test_cancelar_meta_tira_a_avaliacao_do_lote(): void
    {
        [$lote] = $this->loteComBois();
        $meta = $this->postJson("/api/lotes/{$lote->id}/metas", ['peso_alvo_kg' => 500, 'data_alvo' => '2026-08-01'])->json('data.id');

        $this->postJson("/api/lotes/{$lote->id}/metas/{$meta}/cancelamento", ['motivo' => 'Venda antecipada'])->assertOk();

        $this->getJson("/api/lotes/{$lote->id}/engorda")->assertOk()->assertJsonPath('data.meta', null);
    }

    public function test_lista_de_lotes_resume_a_engorda(): void
    {
        [$lote] = $this->loteComBois();
        MetaEngorda::create(['lote_id' => $lote->id, 'peso_alvo_kg' => 600, 'data_inicio' => '2026-03-01', 'data_alvo' => '2026-07-01']);

        $this->getJson('/api/lotes')->assertOk()
            ->assertJsonPath('data.0.animais', 2)
            ->assertJsonPath('data.0.engorda.peso_medio', 445)
            ->assertJsonPath('data.0.engorda.meta.situacao', 'INVIAVEL');
    }

    public function test_saida_de_racao_para_o_lote_grava_o_custo_do_dia_e_entra_no_consumo(): void
    {
        [$lote] = $this->loteComBois();
        $racao = ItemEstoque::factory()->create(['nome' => 'Ração engorda']);
        $mov = fn (array $d) => $this->postJson('/api/estoque/movimentos', ['item_estoque_id' => $racao->id, 'data_movimento' => '2026-05-25', ...$d]);
        $mov(['tipo' => 'ENTRADA', 'quantidade' => 1000, 'valor_unitario' => '2,00'])->assertCreated();

        $mov(['tipo' => 'SAIDA', 'quantidade' => 300, 'lote_id' => $lote->id])->assertCreated()
            ->assertJsonPath('data.lote.nome', $lote->nome)
            ->assertJsonPath('data.custo_total', 600);
        $mov(['tipo' => 'ENTRADA', 'quantidade' => 100, 'valor_unitario' => 3])->assertCreated();

        $this->getJson("/api/lotes/{$lote->id}/engorda")->assertOk()
            ->assertJsonPath('data.racao.custo_30_dias', 600)
            ->assertJsonPath('data.racao.custo_cabeca_dia', 10)
            ->assertJsonPath('data.racao.custo_kg_ganho', 13.33)
            ->assertJsonPath('data.racao.itens.0.quantidade', 300);
        $this->getJson("/api/estoque/movimentos?lote_id={$lote->id}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_lote_so_vale_na_saida_e_lote_encerrado_nao_recebe_estoque(): void
    {
        $racao = ItemEstoque::factory()->create();
        $encerrado = Lote::factory()->encerrado()->create();
        $mov = fn (array $d) => $this->postJson('/api/estoque/movimentos', ['item_estoque_id' => $racao->id, 'data_movimento' => '2026-05-25', ...$d]);

        $mov(['tipo' => 'ENTRADA', 'quantidade' => 10, 'lote_id' => $encerrado->id])->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/lote_id');
        $mov(['tipo' => 'ENTRADA', 'quantidade' => 10])->assertCreated();
        $mov(['tipo' => 'SAIDA', 'quantidade' => 5, 'lote_id' => $encerrado->id])->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'LOTE_ENCERRADO');

        $this->assertSame(10.0, (float) $racao->fresh()->saldo);
    }

    public function test_caseiro_ve_os_lotes_ativos_para_a_saida_mas_nao_o_painel_do_lote(): void
    {
        [$lote] = $this->loteComBois();
        Lote::factory()->encerrado()->create();
        $this->entrarComo(User::factory()->caseiro()->create());

        $this->getJson('/api/manejo/lotes')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0', ['id' => $lote->id, 'nome' => $lote->nome, 'categoria' => $lote->categoria, 'animais' => 2]);
        $this->getJson("/api/lotes/{$lote->id}/engorda")->assertForbidden();
        $this->colocar($lote, [Animal::factory()->boiGordo()->create()])->assertForbidden();
    }

    public function test_ficha_do_animal_marca_pesagem_suspeita_e_preve_o_peso_da_meta_do_lote(): void
    {
        [$lote, $boi] = $this->loteComBois();
        Pesagem::create(['animal_id' => $boi->id, 'data_pesagem' => '2026-05-17', 'peso_kg' => 4230]);
        MetaEngorda::create(['lote_id' => $lote->id, 'peso_alvo_kg' => 480, 'data_inicio' => '2026-03-01', 'data_alvo' => '2026-08-01']);

        $resposta = $this->getJson("/api/animais/{$boi->id}/crescimento")->assertOk()
            ->assertJsonPath('data.tendencia.gmd', 0.75)
            ->assertJsonPath('data.lote.id', $lote->id)
            ->assertJsonPath('data.alvo.origem', 'META_LOTE')
            ->assertJsonPath('data.alvo.previsao', ['dias' => 61, 'data' => '2026-08-01']);

        $this->assertSame([false, false, true, false], array_column($resposta->json('data.pesagens'), 'suspeita'));
    }

    public function test_lista_de_abate_traz_a_previsao_para_o_peso_de_abate(): void
    {
        config(['pecuaria.engorda.peso_abate_kg' => 480]);
        $boi = Animal::factory()->boiGordo()->create();
        $this->pesar($boi, [60 => 390, 30 => 412, 0 => 435]);

        $this->getJson('/api/manejo/abate')->assertOk()
            ->assertJsonPath('data.0.crescimento.alvo_origem', 'PESO_ABATE_PADRAO')
            ->assertJsonPath('data.0.crescimento.previsao_alvo.dias', 61);
    }

    public function test_painel_avisa_meta_atrasada_e_animal_perdendo_peso(): void
    {
        [$lote] = $this->loteComBois();
        MetaEngorda::create(['lote_id' => $lote->id, 'peso_alvo_kg' => 540, 'data_inicio' => '2026-03-01', 'data_alvo' => '2026-09-01']);
        $magro = Animal::factory()->boiGordo()->create(['brinco' => 'C-099']);
        $this->pesar($magro, [60 => 430, 30 => 421, 0 => 412]);

        $this->getJson('/api/painel/engorda')->assertOk()
            ->assertJsonPath('data.0.tipo', 'META_ATRASADA')
            ->assertJsonPath('data.0.lote_id', $lote->id)
            ->assertJsonPath('data.1.tipo', 'PERDENDO_PESO')
            ->assertJsonPath('data.1.mensagem', 'C-099 está perdendo peso (-0,30 kg/dia).');
    }
}

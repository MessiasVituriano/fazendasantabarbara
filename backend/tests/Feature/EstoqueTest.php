<?php

namespace Tests\Feature;

use App\Models\ItemEstoque;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EstoqueTest extends TestCase
{
    use RefreshDatabase;

    private function movimentar(ItemEstoque $item, string $tipo, array $dados = []): TestResponse
    {
        return $this->postJson('/api/estoque/movimentos', [
            'item_estoque_id' => $item->id,
            'tipo' => $tipo,
            'data_movimento' => today()->toDateString(),
            ...$dados,
        ]);
    }

    public function test_gestor_cadastra_item_sem_saldo_e_nome_e_unico(): void
    {
        $this->postJson('/api/estoque/itens', [
            'nome' => 'Ração lactação 22%', 'tipo' => 'RACAO', 'unidade' => 'SACO', 'estoque_minimo' => '10', 'saldo' => 500,
        ])->assertCreated()
            ->assertJsonPath('data.saldo', 0)
            ->assertJsonPath('data.estoque_minimo', 10)
            ->assertJsonPath('data.unidade_sigla', 'sc')
            ->assertJsonPath('data.abaixo_minimo', true);

        $this->postJson('/api/estoque/itens', ['nome' => 'Ração lactação 22%', 'tipo' => 'RACAO', 'unidade' => 'KG'])
            ->assertStatus(422);
    }

    public function test_entrada_e_saida_atualizam_saldo_e_custo(): void
    {
        $item = ItemEstoque::factory()->create(['estoque_minimo' => 20]);

        $this->movimentar($item, 'ENTRADA', ['quantidade' => '50,5', 'valor_unitario' => '3,20'])->assertCreated();
        $this->movimentar($item, 'SAIDA', ['quantidade' => 40.25, 'observacao' => 'Lote recria'])->assertCreated()
            ->assertJsonPath('data.efeito_no_saldo', -40.25);

        $this->getJson("/api/estoque/itens/{$item->id}")->assertOk()
            ->assertJsonPath('data.saldo', 10.25)
            ->assertJsonPath('data.custo_unitario', 3.2)
            ->assertJsonPath('data.valor_estoque', 32.8)
            ->assertJsonPath('data.abaixo_minimo', true);
    }

    public function test_saida_maior_que_o_saldo_e_recusada(): void
    {
        $item = ItemEstoque::factory()->create();
        $this->movimentar($item, 'ENTRADA', ['quantidade' => 5])->assertCreated();

        $this->movimentar($item, 'SAIDA', ['quantidade' => 5.001])->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'ESTOQUE_INSUFICIENTE')
            ->assertJsonPath('errors.0.source.pointer', '/quantidade');
        $this->assertSame(5.0, (float) $item->fresh()->saldo);
    }

    public function test_ajuste_grava_a_diferenca_para_o_saldo_contado(): void
    {
        $item = ItemEstoque::factory()->create();
        $this->movimentar($item, 'ENTRADA', ['quantidade' => 100])->assertCreated();

        $this->movimentar($item, 'AJUSTE', ['saldo_contado' => 92.5])->assertCreated()
            ->assertJsonPath('data.quantidade', -7.5);
        $this->movimentar($item, 'AJUSTE', ['saldo_contado' => 92.5])->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'AJUSTE_SEM_DIFERENCA');

        $this->assertSame(92.5, (float) $item->fresh()->saldo);
    }

    public function test_reenvio_com_o_mesmo_uuid_nao_duplica(): void
    {
        $item = ItemEstoque::factory()->create();
        $uuid = (string) Str::uuid();

        $this->movimentar($item, 'ENTRADA', ['quantidade' => 3, 'uuid' => $uuid])->assertCreated();
        $this->movimentar($item, 'ENTRADA', ['quantidade' => 3, 'uuid' => $uuid])->assertOk();

        $this->assertSame(3.0, (float) $item->fresh()->saldo);
        $this->assertSame(1, $item->movimentos()->count());
    }

    public function test_cancelamento_desfaz_o_saldo_e_nao_deixa_negativo(): void
    {
        $item = ItemEstoque::factory()->create();
        $entradaAntiga = $this->movimentar($item, 'ENTRADA', ['quantidade' => 10, 'valor_unitario' => 2])->json('data.id');
        $entradaNova = $this->movimentar($item, 'ENTRADA', ['quantidade' => 10, 'valor_unitario' => 3])->json('data.id');
        $this->movimentar($item, 'SAIDA', ['quantidade' => 15])->assertCreated();

        $this->postJson("/api/estoque/movimentos/{$entradaNova}/cancelamento", ['motivo' => 'Nota errada'])->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'ESTOQUE_INSUFICIENTE');

        $saida = $item->movimentos()->where('tipo', 'SAIDA')->value('id');
        $this->postJson("/api/estoque/movimentos/{$saida}/cancelamento", ['motivo' => 'Lançado em dobro'])->assertOk()
            ->assertJsonPath('data.cancelado', true);
        $this->postJson("/api/estoque/movimentos/{$entradaNova}/cancelamento", ['motivo' => 'Nota errada'])->assertOk();
        $this->postJson("/api/estoque/movimentos/{$entradaNova}/cancelamento", ['motivo' => 'De novo'])->assertStatus(422);

        $item->refresh();
        $this->assertSame(10.0, (float) $item->saldo);
        $this->assertSame(2.0, (float) $item->custo_unitario);
        $this->assertNotNull($entradaAntiga);
    }

    public function test_item_desativado_nao_movimenta_e_sai_da_lista(): void
    {
        $item = ItemEstoque::factory()->create(['ativo' => false]);

        $this->movimentar($item, 'ENTRADA', ['quantidade' => 1])->assertStatus(422)->assertJsonPath('errors.0.code', 'ITEM_INATIVO');
        $this->getJson('/api/estoque/itens')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/estoque/itens?inativos=1')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_lista_filtra_alertas_de_reposicao_e_validade(): void
    {
        ItemEstoque::factory()->create(['nome' => 'Sal mineral', 'estoque_minimo' => 5]);
        ItemEstoque::factory()->medicamento()->create(['nome' => 'Ivermectina', 'data_validade' => today()->subDay()->toDateString()]);
        ItemEstoque::factory()->medicamento()->create(['nome' => 'Ocitocina', 'data_validade' => today()->addDays(10)->toDateString()]);
        ItemEstoque::factory()->create(['nome' => 'Milho', 'data_validade' => today()->addYear()->toDateString()]);

        $this->getJson('/api/estoque/itens?alerta=REPOR')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'Sal mineral');
        $this->getJson('/api/estoque/itens?alerta=VALIDADE')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.situacao_validade', 'VENCIDO')
            ->assertJsonPath('data.1.situacao_validade', 'VENCE_EM_BREVE')
            ->assertJsonPath('meta.resumo', ['itens' => 4, 'repor' => 1, 'vencidos' => 1, 'vencendo' => 1, 'valor_total' => 0]);
    }

    public function test_caseiro_registra_entrada_e_saida_sem_ver_custos(): void
    {
        $item = ItemEstoque::factory()->create();
        $this->entrarComo(User::factory()->caseiro()->create());

        $this->movimentar($item, 'ENTRADA', ['quantidade' => 8])->assertCreated()->assertJsonMissingPath('data.valor_unitario');
        $this->movimentar($item, 'SAIDA', ['quantidade' => 2])->assertCreated();
        $this->movimentar($item, 'ENTRADA', ['quantidade' => 1, 'valor_unitario' => 10])->assertStatus(422);
        $this->movimentar($item, 'AJUSTE', ['saldo_contado' => 1])->assertStatus(422);
        $this->movimentar($item, 'SAIDA', ['quantidade' => 1, 'data_movimento' => today()->subDays(10)->toDateString()])->assertStatus(422);

        $this->getJson("/api/estoque/itens/{$item->id}")->assertOk()
            ->assertJsonPath('data.saldo', 6)
            ->assertJsonMissingPath('data.custo_unitario');
        $this->getJson('/api/estoque/itens')->assertOk()->assertJsonMissingPath('meta.resumo.valor_total');

        $movimento = $item->movimentos()->value('id');
        $this->postJson('/api/estoque/itens', ['nome' => 'X', 'tipo' => 'RACAO', 'unidade' => 'KG'])->assertForbidden();
        $this->patchJson("/api/estoque/itens/{$item->id}", ['estoque_minimo' => 1])->assertForbidden();
        $this->postJson("/api/estoque/movimentos/{$movimento}/cancelamento", ['motivo' => 'x'])->assertForbidden();
    }
}

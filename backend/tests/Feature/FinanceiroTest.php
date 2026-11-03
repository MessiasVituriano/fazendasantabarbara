<?php

namespace Tests\Feature;

use App\Models\CategoriaFinanceira;
use App\Models\ContaFinanceira;
use App\Models\Lancamento;
use App\Models\Lote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinanceiroTest extends TestCase
{
    use RefreshDatabase;

    private ContaFinanceira $caixa;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 10:00:00');
        $this->caixa = ContaFinanceira::create([
            'nome' => 'Banco', 'tipo' => 'CONTA_CORRENTE', 'saldo_inicial' => 10000, 'data_saldo_inicial' => '2026-01-01',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cat(string $chave): int
    {
        return CategoriaFinanceira::porChave($chave)->id;
    }

    private function lancar(array $dados = []): array
    {
        return $this->postJson('/api/financeiro/lancamentos', [
            'tipo' => 'DESPESA', 'descricao' => 'Sal mineral', 'categoria_id' => $this->cat('suplementacao_mineral'),
            'valor' => '1000.00', 'data_competencia' => '2026-11-01', 'data_vencimento' => '2026-11-10',
            ...$dados,
        ])->assertCreated()->json('data');
    }

    public function test_categorias_padrao_existem_sem_seed(): void
    {
        $this->assertSame('AQUISICAO', CategoriaFinanceira::porChave('compra_animais')->grupo_custo->value);
        $this->assertSame('RECEITA', CategoriaFinanceira::porChave('venda_bovinos')->tipo->value);
        $this->assertGreaterThanOrEqual(19, CategoriaFinanceira::count());
    }

    public function test_caseiro_nao_acessa_financeiro(): void
    {
        $this->entrarComo(User::factory()->caseiro()->create());
        foreach (['/api/financeiro/resumo', '/api/financeiro/lancamentos', '/api/financeiro/contas', '/api/lotes', '/api/pessoas'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }

    public function test_despesa_pendente_nao_altera_saldo_e_baixa_altera(): void
    {
        $l = $this->lancar()[0];
        $this->assertSame('PENDENTE', $l['situacao']);
        $this->assertSame(10000.0, ContaFinanceira::saldos()[$this->caixa->id]);

        $this->postJson("/api/financeiro/lancamentos/{$l['id']}/baixa", [
            'data_pagamento' => '2026-11-03', 'conta_financeira_id' => $this->caixa->id, 'forma_pagamento' => 'PIX', 'valor_pago' => '1.020,50',
        ])->assertOk()->assertJsonPath('data.situacao', 'PAGO')->assertJsonPath('data.valor_pago', '1020.50');

        $this->assertSame(8979.5, ContaFinanceira::saldos()[$this->caixa->id]);
        $this->postJson("/api/financeiro/lancamentos/{$l['id']}/baixa", ['data_pagamento' => '2026-11-03', 'conta_financeira_id' => $this->caixa->id])
            ->assertStatus(409);
    }

    public function test_vencido_e_derivado_da_data(): void
    {
        $l = $this->lancar(['data_vencimento' => '2026-10-20'])[0];
        $this->assertSame('VENCIDO', $l['situacao']);
        $this->assertDatabaseHas('lancamentos', ['id' => $l['id'], 'situacao' => 'PENDENTE']);

        $this->getJson('/api/financeiro/lancamentos?situacao[]=VENCIDO')->assertJsonCount(1, 'data');
        $this->getJson('/api/financeiro/lancamentos?situacao[]=PENDENTE')->assertJsonCount(0, 'data');
    }

    public function test_parcelamento_divide_centavos_e_mantem_competencia(): void
    {
        $parcelas = $this->lancar([
            'descricao' => 'Compra de garrotes', 'categoria_id' => $this->cat('compra_animais'),
            'valor' => '100000.00', 'data_vencimento' => '2026-01-31', 'parcelas' => 3,
        ]);

        $this->assertCount(3, $parcelas);
        $this->assertSame(['33333.34', '33333.33', '33333.33'], array_column($parcelas, 'valor'));
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], array_column($parcelas, 'data_vencimento'));
        $this->assertSame(['2026-11-01'], array_values(array_unique(array_column($parcelas, 'data_competencia'))));
        $this->assertSame('Compra de garrotes (2/3)', $parcelas[1]['descricao']);
        $this->assertSame('AQUISICAO', $parcelas[0]['grupo_custo']);
        $this->assertEquals(100000, Lancamento::sum('valor'));
    }

    public function test_lancamento_ja_pago_na_criacao(): void
    {
        $l = $this->lancar(['data_pagamento' => '2026-11-02', 'conta_financeira_id' => $this->caixa->id, 'forma_pagamento' => 'DINHEIRO'])[0];
        $this->assertSame('PAGO', $l['situacao']);
        $this->assertSame('1000.00', $l['valor_pago']);
        $this->assertSame(9000.0, ContaFinanceira::saldos()[$this->caixa->id]);

        // Sem conta: não é aceito.
        $this->postJson('/api/financeiro/lancamentos', [
            'tipo' => 'DESPESA', 'descricao' => 'x', 'categoria_id' => $this->cat('agua'), 'valor' => 10,
            'data_competencia' => '2026-11-01', 'data_vencimento' => '2026-11-01', 'data_pagamento' => '2026-11-01',
        ])->assertStatus(422)->assertJsonPath('errors.0.source.pointer', '/conta_financeira_id');
    }

    public function test_regras_de_validacao(): void
    {
        // Categoria de receita numa despesa.
        $this->postJson('/api/financeiro/lancamentos', [
            'tipo' => 'DESPESA', 'descricao' => 'x', 'categoria_id' => $this->cat('venda_bovinos'), 'valor' => 10,
            'data_competencia' => '2026-11-01', 'data_vencimento' => '2026-11-01',
        ])->assertStatus(422)->assertJsonPath('errors.0.code', 'CATEGORIA_INCOMPATIVEL');

        // Pagamento antes do saldo inicial da conta.
        $l = $this->lancar()[0];
        $this->postJson("/api/financeiro/lancamentos/{$l['id']}/baixa", ['data_pagamento' => '2025-12-31', 'conta_financeira_id' => $this->caixa->id])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'PAGAMENTO_ANTES_DO_SALDO_INICIAL');

        // Pagamento no futuro.
        $this->postJson("/api/financeiro/lancamentos/{$l['id']}/baixa", ['data_pagamento' => '2026-11-04', 'conta_financeira_id' => $this->caixa->id])
            ->assertStatus(422);

        // Valor com mais de 2 casas.
        $this->postJson('/api/financeiro/lancamentos', [
            'tipo' => 'DESPESA', 'descricao' => 'x', 'categoria_id' => $this->cat('agua'), 'valor' => '10.005',
            'data_competencia' => '2026-11-01', 'data_vencimento' => '2026-11-01',
        ])->assertStatus(422);
    }

    public function test_pago_nao_muda_valor_sem_estorno_e_estorno_gera_historico(): void
    {
        $l = $this->lancar(['data_pagamento' => '2026-11-02', 'conta_financeira_id' => $this->caixa->id])[0];

        $this->patchJson("/api/financeiro/lancamentos/{$l['id']}", ['valor' => 2000])->assertStatus(409)->assertJsonPath('errors.0.code', 'LANCAMENTO_PAGO');
        $this->patchJson("/api/financeiro/lancamentos/{$l['id']}", ['valor' => '1000.00', 'descricao' => 'Sal mineral 80'])->assertOk()
            ->assertJsonPath('data.descricao', 'Sal mineral 80');
        $this->postJson("/api/financeiro/lancamentos/{$l['id']}/cancelamento", ['motivo' => 'x'])->assertStatus(409);

        $this->postJson("/api/financeiro/lancamentos/{$l['id']}/estorno", ['motivo' => 'Pago na conta errada'])->assertOk()
            ->assertJsonPath('data.situacao', 'PENDENTE')->assertJsonPath('data.data_pagamento', null);
        $this->assertSame(10000.0, ContaFinanceira::saldos()[$this->caixa->id]);

        $acoes = array_column($this->getJson("/api/financeiro/lancamentos/{$l['id']}/historico")->json('data'), 'acao');
        $this->assertSame(['ESTORNO', 'ALTERADO', 'CRIADO'], $acoes);
        $estorno = $this->getJson("/api/financeiro/lancamentos/{$l['id']}/historico")->json('data.0');
        $this->assertSame('2026-11-02', $estorno['antes']['data_pagamento']);
        $this->assertSame('Pago na conta errada', $estorno['depois']['motivo']);
    }

    public function test_cancelamento_tira_dos_totais_e_cancela_parcelas_seguintes(): void
    {
        $p = $this->lancar(['valor' => 300, 'parcelas' => 3]);
        $this->postJson("/api/financeiro/lancamentos/{$p[1]['id']}/cancelamento", ['motivo' => 'Renegociado', 'parcelas_seguintes' => true])
            ->assertOk()->assertJsonCount(2, 'data');

        $this->getJson('/api/financeiro/lancamentos')->assertJsonCount(1, 'data')->assertJsonPath('meta.totais.despesas', 100);
        $this->getJson('/api/financeiro/lancamentos?situacao[]=CANCELADO')->assertJsonCount(2, 'data');
        $this->patchJson("/api/financeiro/lancamentos/{$p[2]['id']}", ['descricao' => 'y'])->assertStatus(409);
        $this->deleteJson("/api/financeiro/lancamentos/{$p[0]['id']}")->assertStatus(405);
    }

    public function test_baixa_em_lote_e_atomica(): void
    {
        $a = $this->lancar(['valor' => 100])[0];
        $b = $this->lancar(['valor' => 200])[0];
        $this->postJson("/api/financeiro/lancamentos/{$b['id']}/baixa", ['data_pagamento' => '2026-11-03', 'conta_financeira_id' => $this->caixa->id]);

        // b já pago: nada é baixado.
        $this->postJson('/api/financeiro/lancamentos/baixa-em-lote', ['ids' => [$a['id'], $b['id']], 'data_pagamento' => '2026-11-03', 'conta_financeira_id' => $this->caixa->id])
            ->assertStatus(409);
        $this->assertDatabaseHas('lancamentos', ['id' => $a['id'], 'situacao' => 'PENDENTE']);

        $c = $this->lancar(['valor' => 50])[0];
        $this->postJson('/api/financeiro/lancamentos/baixa-em-lote', ['ids' => [$a['id'], $c['id']], 'data_pagamento' => '2026-11-03', 'conta_financeira_id' => $this->caixa->id])
            ->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(9650.0, ContaFinanceira::saldos()[$this->caixa->id]);
    }

    public function test_fluxo_de_caixa_separa_realizado_e_previsto(): void
    {
        $pago = fn (string $tipo, string $cat, $valor, string $data) => $this->lancar([
            'tipo' => $tipo, 'categoria_id' => $this->cat($cat), 'valor' => $valor,
            'data_competencia' => $data, 'data_vencimento' => $data, 'data_pagamento' => $data, 'conta_financeira_id' => $this->caixa->id,
        ]);
        $pago('DESPESA', 'agua', 500, '2026-08-15');          // antes do período
        $pago('RECEITA', 'venda_bovinos', 20000, '2026-09-10');
        $pago('DESPESA', 'racoes', 3000, '2026-10-05');
        $this->lancar(['valor' => 1000, 'data_vencimento' => '2026-10-25']);  // vencido -> previsto hoje (nov)
        $this->lancar(['valor' => 2000, 'data_vencimento' => '2026-12-10']);  // previsto dez
        $this->lancar(['tipo' => 'RECEITA', 'categoria_id' => $this->cat('venda_bovinos'), 'valor' => 5000, 'data_vencimento' => '2027-01-15']); // fora

        $f = $this->getJson('/api/financeiro/fluxo-caixa?inicio=2026-09-01&fim=2026-12-31')->assertOk()->json('data');

        $this->assertEquals(9500, $f['saldo_inicial']);
        $this->assertEquals(26500, $f['saldo_final']);
        $this->assertSame(['2026-09', '2026-10', '2026-11', '2026-12'], array_column($f['periodos'], 'periodo'));
        $this->assertEquals([20000, 0, 0, 0], array_column($f['periodos'], 'entradas'));
        $this->assertEquals([0, 3000, 0, 0], array_column($f['periodos'], 'saidas'));
        $this->assertEquals([0, 0, 1000, 2000], array_column($f['periodos'], 'saidas_previstas'));
        $this->assertEquals([29500, 26500, 26500, 26500], array_column($f['periodos'], 'saldo_final'));
        $this->assertEquals(23500, $f['saldo_projetado']);
        $this->assertEquals(1000, $f['previsto_inclui_vencidos']['despesas']);
        // comparativo com os 4 meses anteriores (maio–ago): só a água.
        $this->assertEquals(500, $f['comparativo']['saidas']);

        // Saldo do fluxo bate com o saldo da conta.
        $this->assertEquals(ContaFinanceira::saldos()[$this->caixa->id], $f['saldo_final']);
    }

    public function test_conta_aberta_no_meio_do_periodo_entra_como_abertura(): void
    {
        ContaFinanceira::create(['nome' => 'Caixa', 'tipo' => 'CAIXA', 'saldo_inicial' => 700, 'data_saldo_inicial' => '2026-10-10']);
        $f = $this->getJson('/api/financeiro/fluxo-caixa?inicio=2026-09-01&fim=2026-11-30')->json('data');
        $this->assertEquals(10000, $f['saldo_inicial']);
        $this->assertEquals([0, 700, 0], array_column($f['periodos'], 'abertura_contas'));
        $this->assertEquals(10700, $f['saldo_final']);
    }

    public function test_custos_do_lote_separam_aquisicao_do_operacional(): void
    {
        $lote = $this->postJson('/api/lotes', ['nome' => 'Lote 01 Nelore', 'data_formacao' => '2026-09-01'])->assertCreated()->json('data');
        $this->lancar(['categoria_id' => $this->cat('compra_animais'), 'valor' => 150000, 'lote_id' => $lote['id'], 'data_competencia' => '2026-09-01',
            'data_pagamento' => '2026-09-01', 'conta_financeira_id' => $this->caixa->id]);
        $this->lancar(['categoria_id' => $this->cat('despesas_aquisicao'), 'valor' => 4500, 'lote_id' => $lote['id'], 'data_competencia' => '2026-09-01']);
        $this->lancar(['categoria_id' => $this->cat('proteinados'), 'valor' => 6000, 'lote_id' => $lote['id'], 'data_competencia' => '2026-10-01']);
        $this->lancar(['categoria_id' => $this->cat('medicamentos_vacinas'), 'valor' => 1200, 'lote_id' => $lote['id'], 'data_competencia' => '2026-10-15']);
        $cancelado = $this->lancar(['valor' => 999, 'lote_id' => $lote['id']])[0];
        $this->postJson("/api/financeiro/lancamentos/{$cancelado['id']}/cancelamento", ['motivo' => 'duplicado']);
        $this->lancar(['valor' => 777]); // sem lote

        $c = $this->getJson("/api/lotes/{$lote['id']}")->assertOk()->json('data.custos');
        $this->assertEquals(154500, $c['aquisicao']);
        $this->assertEquals(7200, $c['operacional']);
        $this->assertEquals(161700, $c['total']);
        $this->assertEquals(150000, $c['pago']);
        $this->assertSame(['AQUISICAO', 'SUPLEMENTACAO', 'SANIDADE'], array_column($c['grupos'], 'grupo'));
        $this->assertEquals([154500, 161700], array_column($c['mensal'], 'acumulado'));

        $this->getJson('/api/lotes')->assertJsonPath('data.0.despesas', 161700);
    }

    public function test_resumo_responde_saldo_mes_e_agenda(): void
    {
        $this->lancar(['valor' => 100, 'data_vencimento' => '2026-10-01']);      // vencido
        $this->lancar(['valor' => 200, 'data_vencimento' => '2026-11-03']);      // hoje
        $this->lancar(['valor' => 300, 'data_vencimento' => '2026-11-08']);      // 7 dias
        $this->lancar(['tipo' => 'RECEITA', 'categoria_id' => $this->cat('venda_bovinos'), 'valor' => 9000, 'data_vencimento' => '2026-11-20']);
        $this->lancar(['valor' => 400, 'data_competencia' => '2026-11-02', 'data_vencimento' => '2026-11-02',
            'data_pagamento' => '2026-11-02', 'conta_financeira_id' => $this->caixa->id]);

        $r = $this->getJson('/api/financeiro/resumo')->assertOk()->json('data');
        $this->assertEquals(9600, $r['saldo']['total']);
        $this->assertEquals(400, $r['mes_caixa']['despesas']);
        $this->assertEquals(1000, $r['mes_competencia']['despesas']);  // competência: tudo de novembro, pago ou não
        $this->assertEquals(9000, $r['mes_competencia']['receitas']);
        $this->assertEquals(['quantidade' => 1, 'valor' => 100], $r['a_pagar']['vencido']);
        $this->assertEquals(200, $r['a_pagar']['hoje']['valor']);
        $this->assertEquals(300, $r['a_pagar']['proximos_7_dias']['valor']);
        $this->assertEquals(600, $r['a_pagar']['total']['valor']);
        $this->assertEquals(9000, $r['a_receber']['proximos_30_dias']['valor']);
        $this->assertSame('VENCIDO', $r['proximos_vencimentos'][0]['situacao']);
        $this->assertCount(12, $r['serie_mensal']);
        $this->assertEquals(400, end($r['serie_mensal'])['despesas']);
    }

    public function test_demonstrativo_por_competencia(): void
    {
        $this->lancar(['tipo' => 'RECEITA', 'categoria_id' => $this->cat('venda_bovinos'), 'valor' => 10000, 'data_competencia' => '2026-10-10']);
        $this->lancar(['valor' => 2500, 'data_competencia' => '2026-10-10', 'data_pagamento' => '2026-10-10', 'conta_financeira_id' => $this->caixa->id]);
        $this->lancar(['valor' => 500, 'data_competencia' => '2026-09-30']);

        $d = $this->getJson('/api/financeiro/demonstrativo?inicio=2026-10-01&fim=2026-10-31')->assertOk()->json('data');
        $this->assertEquals(10000, $d['receitas']['total']);
        $this->assertEquals(2500, $d['despesas']['total']);
        $this->assertEquals(7500, $d['resultado']);
        $this->assertEquals(75, $d['margem']);
        $this->assertEquals(0, $d['receitas']['categorias'][0]['pago']);
        $this->assertEquals(10000, $d['receitas']['categorias'][0]['em_aberto']);
    }

    public function test_anexo_privado_com_historico(): void
    {
        Storage::fake('local');
        $l = $this->lancar()[0];

        $anexo = $this->post("/api/financeiro/lancamentos/{$l['id']}/anexos", ['arquivo' => UploadedFile::fake()->create('nota.pdf', 200, 'application/pdf')])
            ->assertCreated()->json('data');
        $this->assertArrayNotHasKey('caminho', $anexo);
        $this->get("/api/financeiro/anexos/{$anexo['id']}")->assertOk()->assertDownload('nota.pdf');
        $this->getJson("/api/financeiro/lancamentos/{$l['id']}")->assertJsonCount(1, 'data.anexos');

        $this->deleteJson("/api/financeiro/anexos/{$anexo['id']}")->assertNoContent();
        $this->assertSame([], Storage::disk('local')->allFiles());
        $acoes = array_column($this->getJson("/api/financeiro/lancamentos/{$l['id']}/historico")->json('data'), 'acao');
        $this->assertSame(['ANEXO_REMOVIDO', 'ANEXO_INCLUIDO', 'CRIADO'], $acoes);

        $this->post("/api/financeiro/lancamentos/{$l['id']}/anexos", ['arquivo' => UploadedFile::fake()->create('virus.exe', 10)], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_filtros_e_totais_da_listagem(): void
    {
        $lote = Lote::create(['nome' => 'L1', 'data_formacao' => '2026-01-01']);
        $this->lancar(['valor' => 100, 'lote_id' => $lote->id, 'data_competencia' => '2026-10-01']);
        $this->lancar(['valor' => 200, 'data_competencia' => '2026-11-01']);
        $this->lancar(['tipo' => 'RECEITA', 'categoria_id' => $this->cat('receitas_diversas'), 'valor' => 50, 'descricao' => 'Venda de esterco']);

        $this->getJson("/api/financeiro/lancamentos?lote_id={$lote->id}")->assertJsonCount(1, 'data');
        $this->getJson('/api/financeiro/lancamentos?lote_id=sem')->assertJsonCount(2, 'data');
        $this->getJson('/api/financeiro/lancamentos?base=competencia&inicio=2026-11-01&fim=2026-11-30')
            ->assertJsonPath('meta.totais.despesas', 200)->assertJsonPath('meta.totais.receitas', 50)->assertJsonPath('meta.totais.saldo', -150);
        $this->getJson('/api/financeiro/lancamentos?busca=esterco')->assertJsonCount(1, 'data');
        $this->getJson('/api/financeiro/lancamentos?tipo=DESPESA&categoria_id[]='.$this->cat('suplementacao_mineral'))->assertJsonCount(2, 'data');
    }

    public function test_conta_nao_pode_ter_saldo_inicial_depois_de_pagamentos(): void
    {
        $this->lancar(['data_pagamento' => '2026-03-01', 'conta_financeira_id' => $this->caixa->id]);
        $this->patchJson("/api/financeiro/contas/{$this->caixa->id}", ['data_saldo_inicial' => '2026-06-01'])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'SALDO_INICIAL_POSTERIOR');
        $this->getJson('/api/financeiro/contas')->assertJsonPath('data.0.saldo_atual', 9000);
    }

    public function test_categoria_de_despesa_exige_grupo_e_nao_muda_historico(): void
    {
        $this->postJson('/api/financeiro/categorias', ['tipo' => 'DESPESA', 'nome' => 'Silagem'])->assertStatus(422);
        $cat = $this->postJson('/api/financeiro/categorias', ['tipo' => 'DESPESA', 'nome' => 'Silagem', 'grupo_custo' => 'ALIMENTACAO'])
            ->assertCreated()->json('data');
        $l = $this->lancar(['categoria_id' => $cat['id']])[0];
        $this->assertSame('ALIMENTACAO', $l['grupo_custo']);

        $this->patchJson("/api/financeiro/categorias/{$cat['id']}", ['grupo_custo' => 'OUTROS'])->assertOk();
        $this->assertSame('ALIMENTACAO', Lancamento::find($l['id'])->grupo_custo->value);
    }
}

<?php

namespace Tests\Feature;

use App\Models\ContaFinanceira;
use App\Models\Lote;
use App\Models\MovimentoCapital;
use App\Models\Pessoa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AportesRetiradasTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/financeiro/aportes-retiradas';

    private ContaFinanceira $conta;

    private Pessoa $investidor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->conta = ContaFinanceira::create([
            'nome' => 'Banco', 'tipo' => 'CONTA_CORRENTE', 'saldo_inicial' => 1000, 'data_saldo_inicial' => '2026-01-01',
        ]);
        $this->investidor = Pessoa::create(['nome' => 'Helena Prado', 'tipo' => 'INVESTIDOR']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function dados(array $dados = []): array
    {
        return [
            'tipo' => 'APORTE', 'pessoa_id' => $this->investidor->id, 'valor' => '5000.00',
            'data_movimento' => '2026-11-02', 'conta_financeira_id' => $this->conta->id, ...$dados,
        ];
    }

    private function registrar(array $dados = []): array
    {
        return $this->postJson(self::URL, $this->dados($dados))->assertCreated()->json('data');
    }

    private function saldo(): float
    {
        return ContaFinanceira::saldos()[$this->conta->id];
    }

    public function test_caseiro_nao_acessa_aportes_e_retiradas(): void
    {
        $this->entrarComo(User::factory()->caseiro()->create());

        $this->getJson(self::URL)->assertForbidden();
        $this->postJson(self::URL, $this->dados())->assertForbidden();
    }

    public function test_aporte_soma_e_retirada_subtrai_do_saldo_da_conta(): void
    {
        $aporte = $this->registrar(['valor' => '1.500,50']);

        $this->assertSame('Helena Prado', $aporte['investidor']);
        $this->assertSame(1500.5, $aporte['valor']);
        $this->assertSame('Banco', $aporte['conta']);
        $this->assertSame(2500.5, $this->saldo());

        $this->registrar(['tipo' => 'RETIRADA', 'valor' => '500.50']);
        $this->assertSame(2000.0, $this->saldo());
    }

    public function test_saldo_em_uma_data_ignora_movimentos_posteriores(): void
    {
        $this->registrar(['data_movimento' => '2026-11-02']);

        $this->assertSame(1000.0, ContaFinanceira::saldos('2026-11-01')[$this->conta->id]);
        $this->assertSame(6000.0, ContaFinanceira::saldos('2026-11-02')[$this->conta->id]);
    }

    public function test_cancelar_tira_do_saldo_e_mantem_registro_com_motivo_e_historico(): void
    {
        $id = $this->registrar()['id'];

        $this->postJson(self::URL."/{$id}/cancelamento", ['motivo' => 'Lançado em duplicidade'])
            ->assertOk()
            ->assertJsonPath('data.cancelado', true)
            ->assertJsonPath('data.motivo_cancelamento', 'Lançado em duplicidade');

        $this->assertSame(1000.0, $this->saldo());
        $this->assertSame('CANCELAMENTO', MovimentoCapital::findOrFail($id)->historicoAlteracoes()->first()->acao);
        $this->getJson(self::URL)
            ->assertJsonPath('data.0.cancelado', true)
            ->assertJsonPath('meta.totais.aportes', 0)
            ->assertJsonCount(0, 'meta.investidores');

        $this->postJson(self::URL."/{$id}/cancelamento", ['motivo' => 'De novo'])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.detail', 'Este movimento já está cancelado.');
    }

    public function test_cancelamento_exige_motivo(): void
    {
        $id = $this->registrar()['id'];

        $this->postJson(self::URL."/{$id}/cancelamento")->assertUnprocessable();
        $this->assertSame(6000.0, $this->saldo());
    }

    public function test_so_aceita_investidor_ativo(): void
    {
        $fornecedor = Pessoa::create(['nome' => 'Casa Agropecuária', 'tipo' => 'FORNECEDOR']);
        $this->postJson(self::URL, $this->dados(['pessoa_id' => $fornecedor->id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.detail', '"Casa Agropecuária" não está cadastrado como investidor.');

        $this->investidor->update(['ativo' => false]);
        $this->postJson(self::URL, $this->dados())
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.detail', 'O investidor "Helena Prado" está inativo.');

        $this->assertSame(0, MovimentoCapital::count());
    }

    public function test_valida_campos_obrigatorios_e_data(): void
    {
        $this->postJson(self::URL, [])->assertUnprocessable();
        $this->postJson(self::URL, $this->dados(['data_movimento' => '2026-11-06']))->assertUnprocessable();
        $this->postJson(self::URL, $this->dados(['valor' => '0']))->assertUnprocessable();

        $this->postJson(self::URL, $this->dados(['data_movimento' => '2025-12-31']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'PAGAMENTO_ANTES_DO_SALDO_INICIAL')
            ->assertJsonPath('errors.0.source.pointer', '/data_movimento');

        $this->conta->update(['ativa' => false]);
        $this->postJson(self::URL, $this->dados())->assertUnprocessable();

        $this->assertSame(0, MovimentoCapital::count());
    }

    public function test_listagem_filtra_e_posicao_por_investidor_e_acumulada(): void
    {
        $outro = Pessoa::create(['nome' => 'Carlos Mendes', 'tipo' => 'INVESTIDOR']);
        $this->registrar(['valor' => '5000.00', 'data_movimento' => '2026-10-01']);
        $this->registrar(['tipo' => 'RETIRADA', 'valor' => '1200.00', 'data_movimento' => '2026-11-02']);
        $this->registrar(['pessoa_id' => $outro->id, 'valor' => '9000.00', 'data_movimento' => '2026-11-03']);

        $resposta = $this->getJson(self::URL.'?'.http_build_query(['pessoa_id' => $this->investidor->id, 'inicio' => '2026-11-01']))->assertOk();

        $resposta->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tipo', 'RETIRADA')
            ->assertJsonPath('meta.totais.aportes', 0)
            ->assertJsonPath('meta.totais.retiradas', 1200)
            ->assertJsonPath('meta.totais.liquido', -1200);

        $this->assertSame([
            ['investidor' => 'Carlos Mendes', 'aportes' => 9000, 'retiradas' => 0, 'liquido' => 9000],
            ['investidor' => 'Helena Prado', 'aportes' => 5000, 'retiradas' => 1200, 'liquido' => 3800],
        ], array_map(
            fn (array $linha) => array_intersect_key($linha, array_flip(['investidor', 'aportes', 'retiradas', 'liquido'])),
            $resposta->json('meta.investidores'),
        ));
    }

    public function test_fluxo_de_caixa_separa_capital_da_operacao_e_fecha_o_saldo(): void
    {
        $this->registrar(['valor' => '300.00', 'data_movimento' => '2026-09-15']);
        $this->registrar(['valor' => '5000.00', 'data_movimento' => '2026-10-10']);
        $this->registrar(['tipo' => 'RETIRADA', 'valor' => '800.00', 'data_movimento' => '2026-10-20']);
        $this->registrar(['valor' => '999.00', 'data_movimento' => '2026-10-21', 'conta_financeira_id' => ContaFinanceira::create([
            'nome' => 'Caixa', 'tipo' => 'CAIXA', 'saldo_inicial' => 0, 'data_saldo_inicial' => '2026-01-01',
        ])->id]);

        $fluxo = $this->getJson('/api/financeiro/fluxo-caixa?'.http_build_query([
            'inicio' => '2026-10-01', 'fim' => '2026-10-31', 'conta_financeira_id' => $this->conta->id,
        ]))->assertOk()->json('data');

        $this->assertSame(1300, $fluxo['saldo_inicial']);
        $this->assertSame(5500, $fluxo['saldo_final']);
        $this->assertSame(5000, $fluxo['totais']['aportes']);
        $this->assertSame(800, $fluxo['totais']['retiradas']);
        $this->assertSame(0, $fluxo['totais']['entradas']);
        $this->assertSame(0, $fluxo['totais']['saidas']);
        $this->assertSame(0, $fluxo['totais']['resultado']);
        $this->assertSame(5000, $fluxo['periodos'][0]['aportes']);
    }

    public function test_capital_nao_entra_em_resultado_nem_em_custo_do_lote(): void
    {
        $lote = Lote::create(['nome' => 'Lote 1', 'data_formacao' => '2026-01-01']);
        $this->registrar();
        $this->registrar(['tipo' => 'RETIRADA', 'valor' => '200.00']);

        $resumo = $this->getJson('/api/financeiro/resumo?referencia=2026-11-05')->assertOk()->json('data');
        $this->assertSame(5800, $resumo['saldo']['total']);
        $this->assertSame(0, $resumo['mes_caixa']['resultado']);
        $this->assertSame(0, $resumo['acumulado']['receitas']);
        $this->assertSame(0, $resumo['acumulado']['despesas']);

        $this->getJson('/api/financeiro/demonstrativo?inicio=2026-11-01&fim=2026-11-30')
            ->assertJsonPath('data.resultado', 0);
        $this->getJson("/api/lotes/{$lote->id}")
            ->assertJsonPath('data.custos.total', 0);
    }

    public function test_conta_nao_pode_ter_saldo_inicial_depois_de_um_aporte(): void
    {
        $this->registrar(['data_movimento' => '2026-03-01']);

        $this->patchJson("/api/financeiro/contas/{$this->conta->id}", ['data_saldo_inicial' => '2026-04-01'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.source.pointer', '/data_saldo_inicial');
    }

    public function test_investidor_e_cadastrado_como_pessoa(): void
    {
        $this->postJson('/api/pessoas', ['nome' => 'Carlos Mendes', 'tipo' => 'INVESTIDOR'])->assertCreated();

        $this->getJson('/api/pessoas?busca=Carlos')
            ->assertJsonPath('data.0.tipo', 'INVESTIDOR')
            ->assertJsonPath('data.0.tipo_label', 'Investidor/sócio');
    }
}

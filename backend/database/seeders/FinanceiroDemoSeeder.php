<?php

namespace Database\Seeders;

use App\Models\CategoriaFinanceira;
use App\Models\ContaFinanceira;
use App\Models\Lancamento;
use App\Models\Lote;
use App\Models\Pessoa;
use App\Services\ServicoFinanceiro;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Financeiro de demonstração (somente desenvolvimento): 12 meses de uma recria a pasto,
 * com compras parceladas, custeio mensal, uma venda recebida, contas vencidas e a vencer.
 */
class FinanceiroDemoSeeder extends Seeder
{
    private ServicoFinanceiro $fin;

    private ContaFinanceira $banco;

    private ContaFinanceira $caixa;

    public function run(ServicoFinanceiro $fin): void
    {
        $this->fin = $fin;
        $hoje = Carbon::today();
        $inicio = $hoje->copy()->subMonthsNoOverflow(12)->startOfMonth();

        $this->banco = ContaFinanceira::create(['nome' => 'Banco (conta corrente)', 'tipo' => 'CONTA_CORRENTE', 'saldo_inicial' => 260000, 'data_saldo_inicial' => $inicio]);
        $this->caixa = ContaFinanceira::create(['nome' => 'Caixa da fazenda', 'tipo' => 'CAIXA', 'saldo_inicial' => 2500, 'data_saldo_inicial' => $inicio]);

        $p = fn ($nome, $tipo) => Pessoa::create(['nome' => $nome, 'tipo' => $tipo]);
        $agro = $p('Casa Agropecuária Boa Vista', 'FORNECEDOR');
        $racao = $p('Nutrição Animal Cerrado', 'FORNECEDOR');
        $vendedor = $p('Fazenda Água Limpa (garrotes)', 'FORNECEDOR');
        $frete = $p('Transportes Rio Claro', 'FORNECEDOR');
        $frigo = $p('Frigorífico Vale Verde', 'CLIENTE');
        $vaqueiro = $p('Antônio Pereira (vaqueiro)', 'FORNECEDOR');
        $arrendador = $p('Sítio São José (arrendamento)', 'FORNECEDOR');

        $l1 = Lote::create(['nome' => 'Lote 01 · Garrotes Nelore', 'data_formacao' => $hoje->copy()->subMonthsNoOverflow(8)->startOfMonth()->addDays(9),
            'categoria' => 'Garrotes de recria', 'origem' => 'Fazenda Água Limpa', 'observacoes' => '60 garrotes, ~210 kg na entrada']);
        $l2 = Lote::create(['nome' => 'Lote 02 · Bezerros desmama', 'data_formacao' => $hoje->copy()->subMonthsNoOverflow(3)->startOfMonth()->addDays(4),
            'categoria' => 'Bezerros desmamados', 'origem' => 'Leilão regional']);
        $l0 = Lote::create(['nome' => 'Lote 00 · Bois (vendido)', 'data_formacao' => $inicio, 'categoria' => 'Bois de recria',
            'data_encerramento' => $hoje->copy()->subMonthsNoOverflow(5)->startOfMonth()->addDays(14)]);

        // Lote 01: compra em 3 parcelas + frete e comissão à vista.
        $c1 = $l1->data_formacao;
        $this->lancar('DESPESA', 'compra_animais', 'Compra de 60 garrotes Nelore', 168000, $c1, $c1, $vendedor, $l1, parcelas: 3);
        $this->lancar('DESPESA', 'despesas_aquisicao', 'Frete da compra (60 cab.)', 3200, $c1, $c1, $frete, $l1, pagarEm: $c1);
        $this->lancar('DESPESA', 'despesas_aquisicao', 'Comissão do corretor (2%)', 3360, $c1, $c1, null, $l1, pagarEm: $c1);

        // Lote 02: compra à vista.
        $c2 = $l2->data_formacao;
        $this->lancar('DESPESA', 'compra_animais', 'Compra de 45 bezerros desmamados', 103500, $c2, $c2, null, $l2, pagarEm: $c2);
        $this->lancar('DESPESA', 'despesas_aquisicao', 'Frete do leilão', 2100, $c2, $c2, $frete, $l2, pagarEm: $c2->copy()->addDays(2));

        // Lote 00: venda recebida em 2 parcelas (uma já recebida).
        $venda = $l0->data_encerramento;
        $this->lancar('RECEITA', 'venda_bovinos', 'Venda de 38 bois (17,5 @ média)', 196000, $venda, $venda, $frigo, $l0, parcelas: 2);
        $this->lancar('DESPESA', 'despesas_vendas', 'Frete da venda', 2800, $venda, $venda, $frete, $l0, pagarEm: $venda);

        // Venda já contratada de parte do Lote 01, a receber em 2 parcelas.
        $this->lancar('RECEITA', 'venda_bovinos', 'Venda de 20 garrotes (contrato)', 92000, $hoje->copy()->subDays(3), $hoje->copy()->addDays(12), $frigo, $l1, parcelas: 2);

        // Custeio mensal dos últimos 12 meses + próximo mês (em aberto).
        for ($m = $inicio->copy(); $m->lte($hoje->copy()->addMonthNoOverflow()->startOfMonth()); $m->addMonthNoOverflow()) {
            $d = fn (int $dia) => $m->copy()->day(min($dia, $m->daysInMonth));
            $seco = in_array($m->month, [5, 6, 7, 8, 9, 10]);
            $this->lancar('DESPESA', 'mao_de_obra', 'Salário do vaqueiro', 2900, $d(1), $d(5), $vaqueiro);
            $this->lancar('DESPESA', 'energia_eletrica', 'Conta de energia', 340 + ($m->month % 4) * 25, $d(1), $d(12));
            $this->lancar('DESPESA', 'combustivel', 'Diesel (trator e moto)', 520 + ($m->month % 3) * 90, $d(8), $d(8), null, null, conta: 'caixa');
            if ($m->month % 3 === 1) {
                $this->lancar('DESPESA', 'arrendamento', 'Arrendamento do pasto (trimestre)', 7500, $d(1), $d(10), $arrendador);
            }
            foreach ([[$l0, 38], [$l1, 60], [$l2, 45]] as [$lote, $cab]) {
                $ativo = $m->gte($lote->data_formacao->copy()->startOfMonth()) && (! $lote->data_encerramento || $m->lt($lote->data_encerramento));
                if (! $ativo) {
                    continue;
                }
                $this->lancar('DESPESA', 'suplementacao_mineral', "Sal mineral · {$lote->nome}", round($cab * 2.1 * 30 * 0.09, 2), $d(3), $d(20), $agro, $lote);
                if ($seco) {
                    $this->lancar('DESPESA', 'proteinados', "Proteinado de seca · {$lote->nome}", round($cab * 0.5 * 30 * 2.35, 2), $d(3), $d(25), $racao, $lote);
                }
            }
            if (in_array($m->month, [5, 11])) {
                $this->lancar('DESPESA', 'medicamentos_vacinas', 'Vacina aftosa/clostridiose + vermífugo', 1850, $d(10), $d(10), $agro, $l1->data_formacao->lte($m) ? $l1 : null);
            }
            if ($m->month === 8) {
                $this->lancar('DESPESA', 'manutencao_cercas', 'Arame e mourões — reforma de cerca', 4300, $d(15), $d(15), $agro);
            }
        }
        $this->lancar('RECEITA', 'receitas_diversas', 'Venda de esterco curtido', 1200, $hoje->copy()->subDays(20), $hoje->copy()->subDays(20), null, null, conta: 'caixa');

        // Algumas contas ficam vencidas para a demonstração (não pagas).
        Lancamento::where('situacao', 'PAGO')->where('tipo', 'DESPESA')
            ->whereBetween('data_vencimento', [$hoje->copy()->subDays(25)->toDateString(), $hoje->copy()->subDay()->toDateString()])
            ->whereIn('categoria_id', CategoriaFinanceira::whereIn('chave', ['energia_eletrica', 'suplementacao_mineral'])->pluck('id'))
            ->get()->each(fn ($l) => $this->fin->estornar($l, 'Demonstração: conta em atraso'));
    }

    /** Cria e, se o vencimento já passou (ou pagarEm for informado), dá baixa. */
    private function lancar(string $tipo, string $cat, string $desc, float $valor, Carbon $comp, Carbon $venc, ?Pessoa $pessoa = null,
        ?Lote $lote = null, int $parcelas = 1, ?Carbon $pagarEm = null, string $conta = 'banco'): void
    {
        $criados = $this->fin->criar([
            'tipo' => $tipo, 'categoria_id' => CategoriaFinanceira::porChave($cat)->id, 'descricao' => $desc,
            'valor' => number_format($valor, 2, '.', ''), 'data_competencia' => $comp->toDateString(), 'data_vencimento' => $venc->toDateString(),
            'pessoa_id' => $pessoa?->id, 'lote_id' => $lote?->id, 'parcelas' => $parcelas,
        ]);
        foreach ($criados as $l) {
            $data = $pagarEm ?? $l->data_vencimento;
            if ($data->lte(Carbon::today())) {
                $this->fin->baixar($l, [
                    'data_pagamento' => $data->toDateString(),
                    'conta_financeira_id' => $conta === 'caixa' ? $this->caixa->id : $this->banco->id,
                    'forma_pagamento' => $conta === 'caixa' ? 'DINHEIRO' : 'PIX',
                ]);
            }
        }
    }
}

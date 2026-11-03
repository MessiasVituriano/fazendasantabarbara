<?php

namespace App\Services;

use App\Enums\GrupoCusto;
use App\Enums\TipoLancamento;
use App\Http\Resources\LancamentoResource;
use App\Models\CategoriaFinanceira;
use App\Models\ContaFinanceira;
use App\Models\Lancamento;
use App\Models\Lote;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Leituras do financeiro. Convenções:
 *  - REALIZADO = situação PAGO, pela data de pagamento e pelo valor pago (é o que mexe no saldo);
 *  - PREVISTO  = situação PENDENTE, pela data de vencimento e pelo valor (nunca mexe no saldo);
 *  - COMPETÊNCIA = tudo que não foi cancelado, pela data de competência (resultado econômico e custos).
 * Valores somados no banco e arredondados só na saída (2 casas).
 */
class RelatorioFinanceiro
{
    public function resumo(Carbon $referencia): array
    {
        $hoje = Carbon::today();
        $inicioMes = $referencia->copy()->startOfMonth()->toDateString();
        $fimMes = $referencia->copy()->endOfMonth()->toDateString();

        $saldos = ContaFinanceira::saldos();
        $contas = ContaFinanceira::orderBy('nome')->get()
            ->filter(fn ($c) => $c->ativa || abs($saldos[$c->id] ?? 0) >= 0.01)
            ->map(fn ($c) => ['id' => $c->id, 'nome' => $c->nome, 'tipo' => $c->tipo, 'ativa' => $c->ativa, 'saldo' => $saldos[$c->id] ?? 0])
            ->values();

        $caixaMes = $this->somaPorTipo(Lancamento::pagos()->whereBetween('data_pagamento', [$inicioMes, $fimMes]), 'valor_pago');
        $competenciaMes = $this->somaPorTipo(Lancamento::validos()->whereBetween('data_competencia', [$inicioMes, $fimMes]), 'valor');

        return [
            'referencia' => ['inicio' => $inicioMes, 'fim' => $fimMes],
            'saldo' => ['total' => round(array_sum($saldos), 2), 'contas' => $contas],
            'mes_caixa' => [
                'receitas' => $caixaMes['RECEITA'], 'despesas' => $caixaMes['DESPESA'],
                'resultado' => round($caixaMes['RECEITA'] - $caixaMes['DESPESA'], 2),
            ],
            'mes_competencia' => [
                'receitas' => $competenciaMes['RECEITA'], 'despesas' => $competenciaMes['DESPESA'],
                'resultado' => round($competenciaMes['RECEITA'] - $competenciaMes['DESPESA'], 2),
            ],
            'a_pagar' => $this->agendaEmAberto(TipoLancamento::DESPESA, $hoje),
            'a_receber' => $this->agendaEmAberto(TipoLancamento::RECEITA, $hoje),
            'proximos_vencimentos' => LancamentoResource::collection(Lancamento::emAberto()->with(['categoria:id,nome', 'pessoa:id,nome', 'lote:id,nome'])
                ->orderBy('data_vencimento')->orderBy('id')->limit(8)->get())->resolve(),
            'despesas_por_categoria' => $this->porCategoria(Lancamento::validos()->where('tipo', 'DESPESA')
                ->whereBetween('data_competencia', [$inicioMes, $fimMes])),
            'acumulado' => $this->acumuladoPago(),
            'serie_mensal' => $this->serieMensal($referencia->copy()->subMonthsNoOverflow(11)->startOfMonth(), $referencia->copy()->endOfMonth()),
        ];
    }

    /**
     * Fluxo de caixa: saldo inicial, entradas, saídas e saldo final por mês (ou dia),
     * com a projeção das contas em aberto ao lado, nunca misturada ao realizado.
     */
    public function fluxoCaixa(Carbon $inicio, Carbon $fim, ?int $contaId = null, string $agrupamento = 'mes'): array
    {
        $hoje = Carbon::today();
        $de = $inicio->toDateString();
        $ate = $fim->toDateString();
        $chave = fn (string $data) => $agrupamento === 'dia' ? $data : substr($data, 0, 7);

        // Saldo inicial: contas abertas antes do período + tudo que foi pago antes dele.
        $contas = ContaFinanceira::query()->when($contaId, fn ($q) => $q->whereKey($contaId));
        $saldoInicial = (float) (clone $contas)->where('data_saldo_inicial', '<', $de)->sum('saldo_inicial')
            + $this->liquidoPago(fn ($q) => $q->where('data_pagamento', '<', $de), $contaId);

        $periodos = [];
        for ($d = $inicio->copy(); $d->lte($fim); $agrupamento === 'dia' ? $d->addDay() : $d->addMonthNoOverflow()->startOfMonth()) {
            $k = $chave($d->toDateString());
            $periodos[$k] ??= ['periodo' => $k, 'abertura_contas' => 0.0, 'entradas' => 0.0, 'saidas' => 0.0, 'entradas_previstas' => 0.0, 'saidas_previstas' => 0.0];
        }

        foreach ((clone $contas)->whereBetween('data_saldo_inicial', [$de, $ate])->get() as $c) {
            $periodos[$chave($c->data_saldo_inicial->toDateString())]['abertura_contas'] += (float) $c->saldo_inicial;
        }

        $realizado = Lancamento::pagos()->whereBetween('data_pagamento', [$de, $ate])
            ->when($contaId, fn ($q) => $q->where('conta_financeira_id', $contaId))
            ->groupBy('data_pagamento', 'tipo')
            ->selectRaw('data_pagamento, tipo, SUM(valor_pago) AS total')->toBase()->get();
        foreach ($realizado as $r) {
            $periodos[$chave(substr($r->data_pagamento, 0, 10))][$r->tipo === 'RECEITA' ? 'entradas' : 'saidas'] += (float) $r->total;
        }

        // Previsto: em aberto, com vencidos trazidos para hoje (o que já passou é só realizado).
        // Contas em aberto ainda não têm conta definida, então não há previsão no filtro por conta.
        $previstoVencido = ['RECEITA' => 0.0, 'DESPESA' => 0.0];
        if (! $contaId && $fim->gte($hoje)) {
            $previsto = Lancamento::emAberto()->where('data_vencimento', '<=', $ate)
                ->groupBy('data_vencimento', 'tipo')
                ->selectRaw('data_vencimento, tipo, SUM(valor) AS total')->toBase()->get();
            foreach ($previsto as $p) {
                $data = max(substr($p->data_vencimento, 0, 10), $hoje->toDateString(), $de);
                $periodos[$chave($data)][$p->tipo === 'RECEITA' ? 'entradas_previstas' : 'saidas_previstas'] += (float) $p->total;
                if (substr($p->data_vencimento, 0, 10) < $hoje->toDateString()) {
                    $previstoVencido[$p->tipo] += (float) $p->total;
                }
            }
        }

        $saldo = $saldoInicial;
        $projetado = $saldoInicial;
        $linhas = [];
        foreach ($periodos as $p) {
            $p['saldo_inicial'] = round($saldo, 2);
            $saldo += $p['abertura_contas'] + $p['entradas'] - $p['saidas'];
            $projetado += $p['abertura_contas'] + $p['entradas'] - $p['saidas'] + $p['entradas_previstas'] - $p['saidas_previstas'];
            $linhas[] = array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, [
                ...$p,
                'resultado' => $p['entradas'] - $p['saidas'],
                'saldo_final' => $saldo,
                'saldo_projetado' => $projetado,
            ]);
        }

        $totais = $this->totaisFluxo($linhas);
        $dias = $inicio->diffInDays($fim) + 1;
        $antFim = $inicio->copy()->subDay();
        $antInicio = $antFim->copy()->subDays($dias - 1);
        $anterior = $this->somaPorTipo(Lancamento::pagos()->whereBetween('data_pagamento', [$antInicio->toDateString(), $antFim->toDateString()])
            ->when($contaId, fn ($q) => $q->where('conta_financeira_id', $contaId)), 'valor_pago');
        $variacao = fn (float $atual, float $antes) => $antes == 0.0 ? null : round(($atual - $antes) / abs($antes) * 100, 1);

        return [
            'inicio' => $de, 'fim' => $ate, 'agrupamento' => $agrupamento, 'conta_id' => $contaId,
            'saldo_inicial' => round($saldoInicial, 2),
            'saldo_final' => round($saldo, 2),
            'saldo_projetado' => round($projetado, 2),
            'totais' => $totais,
            'previsto_inclui_vencidos' => ['receitas' => round($previstoVencido['RECEITA'], 2), 'despesas' => round($previstoVencido['DESPESA'], 2)],
            'periodos' => $linhas,
            'comparativo' => [
                'inicio' => $antInicio->toDateString(), 'fim' => $antFim->toDateString(),
                'entradas' => $anterior['RECEITA'], 'saidas' => $anterior['DESPESA'],
                'resultado' => round($anterior['RECEITA'] - $anterior['DESPESA'], 2),
                'variacao_entradas' => $variacao($totais['entradas'], $anterior['RECEITA']),
                'variacao_saidas' => $variacao($totais['saidas'], $anterior['DESPESA']),
            ],
            'por_categoria' => [
                'entradas' => $this->porCategoria(Lancamento::pagos()->where('tipo', 'RECEITA')->whereBetween('data_pagamento', [$de, $ate])
                    ->when($contaId, fn ($q) => $q->where('conta_financeira_id', $contaId)), 'valor_pago'),
                'saidas' => $this->porCategoria(Lancamento::pagos()->where('tipo', 'DESPESA')->whereBetween('data_pagamento', [$de, $ate])
                    ->when($contaId, fn ($q) => $q->where('conta_financeira_id', $contaId)), 'valor_pago'),
            ],
        ];
    }

    /** Demonstrativo de resultado por competência (o que foi gerado no período, pago ou não). */
    public function demonstrativo(Carbon $inicio, Carbon $fim, ?int $loteId = null): array
    {
        $base = fn () => Lancamento::validos()->whereBetween('data_competencia', [$inicio->toDateString(), $fim->toDateString()])
            ->when($loteId, fn ($q) => $q->where('lote_id', $loteId));

        $receitas = $this->porCategoria($base()->where('tipo', 'RECEITA'), 'valor', true);
        $despesas = $this->porCategoria($base()->where('tipo', 'DESPESA'), 'valor', true);
        $totalR = round(array_sum(array_column($receitas, 'total')), 2);
        $totalD = round(array_sum(array_column($despesas, 'total')), 2);

        return [
            'inicio' => $inicio->toDateString(), 'fim' => $fim->toDateString(), 'lote_id' => $loteId,
            'receitas' => ['total' => $totalR, 'categorias' => $receitas],
            'despesas' => ['total' => $totalD, 'categorias' => $despesas, 'grupos' => $this->porGrupo($base()->where('tipo', 'DESPESA'))],
            'resultado' => round($totalR - $totalD, 2),
            'margem' => $totalR > 0 ? round(($totalR - $totalD) / $totalR * 100, 1) : null,
        ];
    }

    /**
     * Custos e receitas vinculados ao lote (por competência). Aquisição e custo operacional da recria
     * são sempre apresentados separados, e o total ao lado.
     */
    public function custosDoLote(Lote $lote): array
    {
        $despesas = Lancamento::validos()->where('lote_id', $lote->id)->where('tipo', 'DESPESA');
        $grupos = $this->porGrupo(clone $despesas);
        $aquisicao = collect($grupos)->firstWhere('grupo', GrupoCusto::AQUISICAO->value);
        $total = round(array_sum(array_column($grupos, 'total')), 2);
        $receitas = $this->somaSituacao(Lancamento::validos()->where('lote_id', $lote->id)->where('tipo', 'RECEITA'));

        $mensal = (clone $despesas)->get(['data_competencia', 'grupo_custo', 'valor'])
            ->groupBy(fn ($l) => $l->data_competencia->format('Y-m'))
            ->map(fn ($itens, $mes) => [
                'mes' => $mes,
                'aquisicao' => round((float) $itens->where('grupo_custo', GrupoCusto::AQUISICAO)->sum('valor'), 2),
                'operacional' => round((float) $itens->where('grupo_custo', '!=', GrupoCusto::AQUISICAO)->sum('valor'), 2),
            ])->sortKeys()->values();
        $acumulado = 0.0;
        $mensal = $mensal->map(function ($m) use (&$acumulado) {
            $acumulado += $m['aquisicao'] + $m['operacional'];

            return [...$m, 'acumulado' => round($acumulado, 2)];
        });

        return [
            'aquisicao' => round($aquisicao['total'] ?? 0, 2),
            'operacional' => round($total - ($aquisicao['total'] ?? 0), 2),
            'total' => $total,
            'pago' => round(array_sum(array_column($grupos, 'pago')), 2),
            'em_aberto' => round(array_sum(array_column($grupos, 'em_aberto')), 2),
            'grupos' => $grupos,
            'receitas' => $receitas,
            'resultado_realizado' => round($receitas['pago'] - array_sum(array_column($grupos, 'pago')), 2),
            'resultado_competencia' => round($receitas['total'] - $total, 2),
            'mensal' => $mensal,
        ];
    }

    // ------------------------------------------------------------------

    /** @return array{RECEITA: float, DESPESA: float} */
    private function somaPorTipo(Builder $q, string $coluna): array
    {
        $somas = $q->groupBy('tipo')->selectRaw("tipo, SUM({$coluna}) AS total")->toBase()->pluck('total', 'tipo');

        return ['RECEITA' => round((float) ($somas['RECEITA'] ?? 0), 2), 'DESPESA' => round((float) ($somas['DESPESA'] ?? 0), 2)];
    }

    private function somaSituacao(Builder $q): array
    {
        $s = $q->groupBy('situacao')->selectRaw('situacao, SUM(valor) AS total')->toBase()->pluck('total', 'situacao');
        $pago = round((float) ($s['PAGO'] ?? 0), 2);
        $aberto = round((float) ($s['PENDENTE'] ?? 0), 2);

        return ['pago' => $pago, 'em_aberto' => $aberto, 'total' => round($pago + $aberto, 2)];
    }

    private function liquidoPago(callable $filtro, ?int $contaId): float
    {
        $q = Lancamento::pagos()->when($contaId, fn ($q) => $q->where('conta_financeira_id', $contaId));
        $filtro($q);
        $s = $this->somaPorTipo($q, 'valor_pago');

        return $s['RECEITA'] - $s['DESPESA'];
    }

    /** Em aberto agrupado por faixa de vencimento: vencido, hoje, próximos 7 dias, 8–30 dias, depois. */
    private function agendaEmAberto(TipoLancamento $tipo, Carbon $hoje): array
    {
        $h = $hoje->toDateString();
        $d7 = $hoje->copy()->addDays(7)->toDateString();
        $d30 = $hoje->copy()->addDays(30)->toDateString();
        $faixa = "CASE WHEN data_vencimento < '{$h}' THEN 'vencido' WHEN data_vencimento = '{$h}' THEN 'hoje'
            WHEN data_vencimento <= '{$d7}' THEN 'proximos_7_dias' WHEN data_vencimento <= '{$d30}' THEN 'proximos_30_dias' ELSE 'depois' END";

        $linhas = Lancamento::emAberto()->where('tipo', $tipo)
            ->selectRaw("{$faixa} AS faixa, COUNT(*) AS quantidade, SUM(valor) AS valor")
            ->groupByRaw($faixa)->toBase()->get()->keyBy('faixa');

        $saida = [];
        foreach (['vencido', 'hoje', 'proximos_7_dias', 'proximos_30_dias', 'depois'] as $f) {
            $saida[$f] = ['quantidade' => (int) ($linhas[$f]->quantidade ?? 0), 'valor' => round((float) ($linhas[$f]->valor ?? 0), 2)];
        }
        $saida['total'] = [
            'quantidade' => array_sum(array_column($saida, 'quantidade')),
            'valor' => round(array_sum(array_column($saida, 'valor')), 2),
        ];

        return $saida;
    }

    /** Por categoria, do maior para o menor. Com $situacao, separa pago e em aberto. */
    private function porCategoria(Builder $q, string $coluna = 'valor', bool $situacao = false): array
    {
        $linhas = $q->groupBy('categoria_id')
            ->selectRaw("categoria_id, SUM({$coluna}) AS total")
            ->when($situacao, fn ($q) => $q->selectRaw("SUM(CASE WHEN situacao = 'PAGO' THEN valor ELSE 0 END) AS pago"))
            ->toBase()->get();
        $nomes = CategoriaFinanceira::whereKey($linhas->pluck('categoria_id'))->get()->keyBy('id');

        return $linhas->map(fn ($l) => [
            'categoria_id' => $l->categoria_id,
            'categoria' => $nomes[$l->categoria_id]->nome ?? '—',
            'grupo_custo' => $nomes[$l->categoria_id]?->grupo_custo?->value,
            'total' => round((float) $l->total, 2),
            ...($situacao ? [
                'pago' => round((float) $l->pago, 2),
                'em_aberto' => round((float) $l->total - (float) $l->pago, 2),
            ] : []),
        ])->sortByDesc('total')->values()->all();
    }

    /** Despesas por grupo de custo, sempre na ordem do enum (aquisição primeiro), com pago/em aberto. */
    private function porGrupo(Builder $q): array
    {
        $s = $q->groupBy('grupo_custo')
            ->selectRaw("grupo_custo, SUM(valor) AS total, SUM(CASE WHEN situacao = 'PAGO' THEN valor ELSE 0 END) AS pago")
            ->toBase()->get()->keyBy(fn ($l) => $l->grupo_custo ?? GrupoCusto::OUTROS->value);

        return collect(GrupoCusto::cases())->filter(fn ($g) => isset($s[$g->value]))->map(fn ($g) => [
            'grupo' => $g->value,
            'rotulo' => $g->label(),
            'operacional' => $g->operacional(),
            'total' => round((float) $s[$g->value]->total, 2),
            'pago' => round((float) $s[$g->value]->pago, 2),
            'em_aberto' => round((float) $s[$g->value]->total - (float) $s[$g->value]->pago, 2),
        ])->values()->all();
    }

    /** Quanto já foi efetivamente desembolsado/recebido desde o início (base: pagamento). */
    private function acumuladoPago(): array
    {
        $s = Lancamento::pagos()->groupBy('tipo', 'grupo_custo')
            ->selectRaw('tipo, grupo_custo, SUM(valor_pago) AS total')->toBase()->get();
        $aquisicao = (float) $s->where('tipo', 'DESPESA')->where('grupo_custo', GrupoCusto::AQUISICAO->value)->sum('total');
        $despesas = (float) $s->where('tipo', 'DESPESA')->sum('total');

        return [
            'aquisicao_animais' => round($aquisicao, 2),
            'custo_operacional' => round($despesas - $aquisicao, 2),
            'despesas' => round($despesas, 2),
            'receitas' => round((float) $s->where('tipo', 'RECEITA')->sum('total'), 2),
        ];
    }

    private function serieMensal(Carbon $inicio, Carbon $fim): array
    {
        $linhas = Lancamento::pagos()->whereBetween('data_pagamento', [$inicio->toDateString(), $fim->toDateString()])
            ->get(['tipo', 'data_pagamento', 'valor_pago'])
            ->groupBy(fn ($l) => $l->data_pagamento->format('Y-m'));

        $serie = [];
        for ($d = $inicio->copy(); $d->lte($fim); $d->addMonthNoOverflow()) {
            $k = $d->format('Y-m');
            $itens = $linhas[$k] ?? collect();
            $r = round((float) $itens->where('tipo', TipoLancamento::RECEITA)->sum('valor_pago'), 2);
            $dsp = round((float) $itens->where('tipo', TipoLancamento::DESPESA)->sum('valor_pago'), 2);
            $serie[] = ['mes' => $k, 'receitas' => $r, 'despesas' => $dsp, 'resultado' => round($r - $dsp, 2)];
        }

        return $serie;
    }

    private function totaisFluxo(array $linhas): array
    {
        $soma = fn (string $c) => round(array_sum(array_column($linhas, $c)), 2);

        return [
            'abertura_contas' => $soma('abertura_contas'),
            'entradas' => $soma('entradas'),
            'saidas' => $soma('saidas'),
            'resultado' => round($soma('entradas') - $soma('saidas'), 2),
            'entradas_previstas' => $soma('entradas_previstas'),
            'saidas_previstas' => $soma('saidas_previstas'),
        ];
    }
}

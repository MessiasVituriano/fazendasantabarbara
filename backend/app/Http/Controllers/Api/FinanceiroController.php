<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RelatorioFinanceiro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Leituras consolidadas do financeiro: visão geral, fluxo de caixa e demonstrativo de resultado. */
class FinanceiroController extends Controller
{
    public function __construct(private readonly RelatorioFinanceiro $relatorio) {}

    public function resumo(Request $request): JsonResponse
    {
        $ref = $request->validate(['referencia' => ['nullable', 'date_format:Y-m-d']])['referencia'] ?? null;

        return response()->json(['data' => $this->relatorio->resumo($ref ? Carbon::parse($ref) : Carbon::today())]);
    }

    public function fluxoCaixa(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'inicio' => ['required', 'date_format:Y-m-d'],
            'fim' => ['required', 'date_format:Y-m-d', 'after_or_equal:inicio'],
            'conta_financeira_id' => ['nullable', 'integer', 'exists:contas_financeiras,id'],
            'agrupamento' => ['nullable', Rule::in(['mes', 'dia'])],
        ]);
        $inicio = Carbon::parse($dados['inicio']);
        $fim = Carbon::parse($dados['fim']);
        $agrupamento = $dados['agrupamento'] ?? 'mes';
        abort_if($inicio->diffInDays($fim) > ($agrupamento === 'dia' ? 366 : 3660), 422, 'Período longo demais para este agrupamento.');

        return response()->json(['data' => $this->relatorio->fluxoCaixa($inicio, $fim, $dados['conta_financeira_id'] ?? null, $agrupamento)]);
    }

    public function demonstrativo(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'inicio' => ['required', 'date_format:Y-m-d'],
            'fim' => ['required', 'date_format:Y-m-d', 'after_or_equal:inicio'],
            'lote_id' => ['nullable', 'integer', 'exists:lotes,id'],
        ]);

        return response()->json(['data' => $this->relatorio->demonstrativo(
            Carbon::parse($dados['inicio']), Carbon::parse($dados['fim']), $dados['lote_id'] ?? null,
        )]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalvarLoteRequest;
use App\Models\Lote;
use App\Services\RelatorioFinanceiro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lotes de recria. Nesta fase: cadastro e custos vindos do financeiro (despesas vinculadas ao lote).
 * Animais por lote, compras e vendas entram na Fase 3.
 */
class LoteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $lotes = Lote::query()
            ->when(! $request->boolean('incluir_encerrados'), fn ($q) => $q->ativos())
            ->withSum(['lancamentos as despesas' => fn ($q) => $q->validos()->where('tipo', 'DESPESA')], 'valor')
            ->withSum(['lancamentos as receitas' => fn ($q) => $q->validos()->where('tipo', 'RECEITA')], 'valor')
            ->orderByRaw('data_encerramento IS NOT NULL')->orderByDesc('data_formacao')->get();

        return response()->json(['data' => $lotes->map(fn ($l) => [
            ...$l->toArray(),
            'despesas' => round((float) $l->despesas, 2),
            'receitas' => round((float) $l->receitas, 2),
        ])]);
    }

    public function store(SalvarLoteRequest $request): JsonResponse
    {
        return response()->json(['data' => Lote::create($request->validated())], 201);
    }

    public function show(Lote $lote, RelatorioFinanceiro $relatorio): JsonResponse
    {
        return response()->json(['data' => [...$lote->toArray(), 'custos' => $relatorio->custosDoLote($lote)]]);
    }

    public function update(SalvarLoteRequest $request, Lote $lote, RelatorioFinanceiro $relatorio): JsonResponse
    {
        $lote->update($request->validated());

        return $this->show($lote, $relatorio);
    }
}

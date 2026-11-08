<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalvarLoteRequest;
use App\Models\Lote;
use App\Services\PainelEngorda;
use App\Services\RelatorioFinanceiro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lotes de recria/engorda: cadastro, custos vindos do financeiro (despesas vinculadas ao lote)
 * e um resumo da engorda (animais, peso médio, GMD e meta). O painel completo está no EngordaController.
 */
class LoteController extends Controller
{
    public function index(Request $request, PainelEngorda $engorda): JsonResponse
    {
        $lotes = Lote::query()
            ->when(! $request->boolean('incluir_encerrados'), fn ($q) => $q->ativos())
            ->withSum(['lancamentos as despesas' => fn ($q) => $q->validos()->where('tipo', 'DESPESA')], 'valor')
            ->withSum(['lancamentos as receitas' => fn ($q) => $q->validos()->where('tipo', 'RECEITA')], 'valor')
            ->withCount('ocupacoesAtuais as animais')
            ->with('metaAtual')
            ->orderByRaw('data_encerramento IS NOT NULL')->orderByDesc('data_formacao')->get();

        return response()->json(['data' => $lotes->map(function (Lote $l) use ($engorda) {
            $r = $l->animais > 0 ? $engorda->resumoLote($l) : null;

            return [
                ...$l->withoutRelations()->toArray(),
                'despesas' => round((float) $l->despesas, 2),
                'receitas' => round((float) $l->receitas, 2),
                'engorda' => $r ? [
                    'peso_medio' => $r['resumo']['peso_medio'],
                    'gmd_medio' => $r['resumo']['gmd_medio'],
                    'meta' => $r['meta'] ? [
                        'peso_alvo_kg' => $r['meta']['peso_alvo_kg'],
                        'data_alvo' => $r['meta']['data_alvo'],
                        'situacao' => $r['meta']['avaliacao']['situacao'],
                        'situacao_label' => $r['meta']['avaliacao']['situacao_label'],
                        'progresso' => $r['meta']['avaliacao']['progresso'],
                    ] : null,
                ] : null,
            ];
        })]);
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
        DB::transaction(function () use ($request, $lote) {
            $lote->update($request->validated());
            // Lote encerrado (vendido/desfeito): os animais saem dele na data do encerramento.
            if ($lote->data_encerramento) {
                $lote->ocupacoesAtuais()->update(['data_saida' => $lote->data_encerramento->toDateString()]);
            }
        });

        return $this->show($lote, $relatorio);
    }
}

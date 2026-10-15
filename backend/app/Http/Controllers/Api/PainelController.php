<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\RegraNegocioException;
use App\Http\Controllers\Controller;
use App\Services\PainelLeite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PainelController extends Controller
{
    /** Atalho `dias` (7/30/90) ou intervalo livre `inicio` + `fim` (máx. 1 ano). */
    public function leite(Request $request, PainelLeite $painel): JsonResponse
    {
        if ($request->hasAny(['inicio', 'fim'])) {
            [$inicio, $fim] = $this->intervalo($request);

            return response()->json(['data' => $painel->gerarIntervalo($inicio, $fim)]);
        }
        $dados = $request->validate(['dias' => ['sometimes', 'integer', 'in:7,30,90']]);

        return response()->json(['data' => $painel->gerar((int) ($dados['dias'] ?? 30))]);
    }

    /** Comparativo por vaca num intervalo de datas livre (máx. 1 ano). */
    public function comparativoLeite(Request $request, PainelLeite $painel): JsonResponse
    {
        [$inicio, $fim] = $this->intervalo($request);

        return response()->json(['data' => $painel->comparativo($inicio, $fim)]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function intervalo(Request $request): array
    {
        $dados = $request->validate([
            'inicio' => ['required', 'date', 'before_or_equal:fim'],
            'fim' => ['required', 'date', 'before_or_equal:today'],
        ], [
            'inicio.before_or_equal' => 'A data inicial deve ser anterior ou igual à final.',
        ]);
        $inicio = Carbon::parse($dados['inicio']);
        $fim = Carbon::parse($dados['fim']);
        if ($inicio->diffInDays($fim) > 365) {
            throw new RegraNegocioException('O intervalo máximo é de 1 ano.', 'INTERVALO_LONGO', 422, '/inicio');
        }

        return [$inicio, $fim];
    }
}

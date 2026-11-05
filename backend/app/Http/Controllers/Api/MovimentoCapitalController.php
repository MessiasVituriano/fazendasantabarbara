<?php

namespace App\Http\Controllers\Api;

use App\Enums\TipoMovimentoCapital;
use App\Http\Controllers\Controller;
use App\Http\Requests\SalvarMovimentoCapitalRequest;
use App\Http\Resources\MovimentoCapitalResource;
use App\Models\MovimentoCapital;
use App\Services\RelatorioFinanceiro;
use App\Services\ServicoFinanceiro;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Aportes e retiradas de investidores. */
class MovimentoCapitalController extends Controller
{
    public function __construct(
        private readonly ServicoFinanceiro $financeiro,
        private readonly RelatorioFinanceiro $relatorio,
    ) {}

    /**
     * Filtros: tipo, pessoa_id, inicio, fim. meta.totais soma o filtro (sem cancelados);
     * meta.investidores é a posição acumulada de cada investidor, independente do filtro.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $dados = $request->validate([
            'tipo' => ['nullable', Rule::enum(TipoMovimentoCapital::class)],
            'pessoa_id' => ['nullable', 'integer'],
            'inicio' => ['nullable', 'date_format:Y-m-d'],
            'fim' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:inicio'],
        ]);

        $movimentos = MovimentoCapital::query()
            ->with(['pessoa:id,nome', 'conta:id,nome'])
            ->when($dados['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))
            ->when($dados['pessoa_id'] ?? null, fn ($q, $v) => $q->where('pessoa_id', $v))
            ->when($dados['inicio'] ?? null, fn ($q, $v) => $q->where('data_movimento', '>=', $v))
            ->when($dados['fim'] ?? null, fn ($q, $v) => $q->where('data_movimento', '<=', $v))
            ->orderByDesc('data_movimento')->orderByDesc('id')
            ->get();

        $validos = $movimentos->whereNull('cancelado_em');
        $aportes = round((float) $validos->where('tipo', TipoMovimentoCapital::APORTE)->sum('valor'), 2);
        $retiradas = round((float) $validos->where('tipo', TipoMovimentoCapital::RETIRADA)->sum('valor'), 2);

        return MovimentoCapitalResource::collection($movimentos)->additional(['meta' => [
            'totais' => ['aportes' => $aportes, 'retiradas' => $retiradas, 'liquido' => round($aportes - $retiradas, 2)],
            'investidores' => $this->relatorio->posicaoInvestidores(),
        ]]);
    }

    public function store(SalvarMovimentoCapitalRequest $request): MovimentoCapitalResource
    {
        return new MovimentoCapitalResource(
            $this->financeiro->registrarMovimentoCapital($request->validated())->load(['pessoa:id,nome', 'conta:id,nome']),
        );
    }

    public function cancelar(Request $request, MovimentoCapital $movimento): MovimentoCapitalResource
    {
        $motivo = $request->validate(['motivo' => ['required', 'string', 'max:255']], [], ['motivo' => 'motivo do cancelamento'])['motivo'];

        return new MovimentoCapitalResource(
            $this->financeiro->cancelarMovimentoCapital($movimento, $motivo)->load(['pessoa:id,nome', 'conta:id,nome']),
        );
    }
}

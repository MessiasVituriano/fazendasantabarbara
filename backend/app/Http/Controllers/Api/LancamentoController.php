<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BaixarLancamentoRequest;
use App\Http\Requests\SalvarLancamentoRequest;
use App\Http\Resources\LancamentoResource;
use App\Models\Lancamento;
use App\Services\ServicoFinanceiro;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class LancamentoController extends Controller
{
    public function __construct(private readonly ServicoFinanceiro $financeiro) {}

    /**
     * Filtros: tipo, situacao[] (PENDENTE|VENCIDO|PAGO|CANCELADO|EM_ABERTO), categoria_id[], lote_id ("sem" = sem lote),
     * pessoa_id, conta_financeira_id, busca, inicio/fim sobre `base` (competencia|vencimento|pagamento).
     * meta.totais soma o filtro inteiro (não só a página), ignorando cancelados quando não filtrados.
     * Em meta.totais, `pago` usa o valor efetivamente pago (com juros/desconto) e `pendente` o valor original do que está em aberto.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $dados = $request->validate([
            'tipo' => ['nullable', Rule::in(['RECEITA', 'DESPESA'])],
            'situacao' => ['nullable', 'array'],
            'situacao.*' => [Rule::in(['PENDENTE', 'VENCIDO', 'PAGO', 'CANCELADO', 'EM_ABERTO'])],
            'categoria_id' => ['nullable', 'array'],
            'base' => ['nullable', Rule::in(['competencia', 'vencimento', 'pagamento'])],
            'inicio' => ['nullable', 'date_format:Y-m-d'],
            'fim' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:inicio'],
            'ordem' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $coluna = 'data_'.($dados['base'] ?? 'vencimento');

        $filtro = fn (): Builder => Lancamento::query()
            ->when($dados['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))
            ->when($dados['situacao'] ?? null, fn ($q, $v) => $q->naSituacao($v), fn ($q) => $q->validos())
            ->when($dados['categoria_id'] ?? null, fn ($q, $v) => $q->whereIn('categoria_id', $v))
            ->when($request->query('lote_id'), fn ($q, $v) => $v === 'sem' ? $q->whereNull('lote_id') : $q->where('lote_id', $v))
            ->when($request->query('pessoa_id'), fn ($q, $v) => $q->where('pessoa_id', $v))
            ->when($request->query('conta_financeira_id'), fn ($q, $v) => $q->where('conta_financeira_id', $v))
            ->when($dados['inicio'] ?? null, fn ($q, $v) => $q->where($coluna, '>=', $v))
            ->when($dados['fim'] ?? null, fn ($q, $v) => $q->where($coluna, '<=', $v))
            ->when(trim((string) $request->query('busca')), fn ($q, $busca) => $q->where(fn ($q) => $q
                ->whereLike('descricao', "%{$busca}%")
                ->orWhereLike('documento', "%{$busca}%")
                ->orWhereHas('pessoa', fn ($q) => $q->whereLike('nome', "%{$busca}%"))));

        $totais = $filtro()->toBase()->selectRaw("
            SUM(CASE WHEN tipo = 'RECEITA' THEN valor ELSE 0 END) AS receitas,
            SUM(CASE WHEN tipo = 'DESPESA' THEN valor ELSE 0 END) AS despesas,
            SUM(CASE WHEN situacao = 'PAGO' THEN valor_pago ELSE 0 END) AS pago,
            SUM(CASE WHEN situacao = 'PENDENTE' THEN valor ELSE 0 END) AS pendente,
            COUNT(*) AS quantidade")->first();

        $ordem = $dados['ordem'] ?? ($coluna === 'data_vencimento' ? 'asc' : 'desc');
        $pagina = $filtro()
            ->with(['categoria:id,nome', 'pessoa:id,nome', 'lote:id,nome', 'conta:id,nome'])
            ->withCount('anexos')
            ->orderBy($coluna === 'data_pagamento' ? 'data_pagamento' : $coluna, $ordem)
            ->orderBy('id', $ordem)
            ->paginate(min((int) $request->query('por_pagina', 50), 500));

        return LancamentoResource::collection($pagina)->additional(['meta' => ['totais' => [
            'quantidade' => (int) $totais->quantidade,
            'receitas' => round((float) $totais->receitas, 2),
            'despesas' => round((float) $totais->despesas, 2),
            'saldo' => round((float) $totais->receitas - (float) $totais->despesas, 2),
            'pago' => round((float) $totais->pago, 2),
            'pendente' => round((float) $totais->pendente, 2),
        ]]]);
    }

    /** Cria um lançamento, um lançamento já pago ou um parcelamento (`parcelas` > 1). */
    public function store(SalvarLancamentoRequest $request): JsonResponse
    {
        $criados = $this->financeiro->criar($request->validated());

        return LancamentoResource::collection($criados->each->load(['categoria:id,nome', 'pessoa:id,nome', 'lote:id,nome', 'conta:id,nome']))
            ->response()->setStatusCode(201);
    }

    public function show(Lancamento $lancamento): LancamentoResource
    {
        return new LancamentoResource($lancamento->load(['categoria:id,nome', 'pessoa:id,nome', 'lote:id,nome', 'conta:id,nome', 'anexos']));
    }

    public function update(SalvarLancamentoRequest $request, Lancamento $lancamento): LancamentoResource
    {
        $this->financeiro->atualizar($lancamento, $request->validated());

        return $this->show($lancamento);
    }

    public function baixar(BaixarLancamentoRequest $request, Lancamento $lancamento): LancamentoResource
    {
        $this->financeiro->baixar($lancamento, $request->validated());

        return $this->show($lancamento);
    }

    public function baixarEmLote(BaixarLancamentoRequest $request): AnonymousResourceCollection
    {
        $dados = $request->validated();

        return LancamentoResource::collection($this->financeiro->baixarEmLote($dados['ids'], $dados));
    }

    public function estornar(Request $request, Lancamento $lancamento): LancamentoResource
    {
        $motivo = $request->validate(['motivo' => ['nullable', 'string', 'max:255']])['motivo'] ?? null;
        $this->financeiro->estornar($lancamento, $motivo);

        return $this->show($lancamento);
    }

    public function cancelar(Request $request, Lancamento $lancamento): AnonymousResourceCollection
    {
        $dados = $request->validate([
            'motivo' => ['required', 'string', 'max:255'],
            'parcelas_seguintes' => ['sometimes', 'boolean'],
        ], [], ['motivo' => 'motivo do cancelamento']);

        return LancamentoResource::collection($this->financeiro->cancelar($lancamento, $dados['motivo'], $dados['parcelas_seguintes'] ?? false));
    }

    /** Trilha de auditoria do lançamento (mais recente primeiro). */
    public function historico(Lancamento $lancamento): JsonResponse
    {
        return response()->json(['data' => $lancamento->historicoAlteracoes()->with('usuario:id,name')->get()
            ->map(fn ($h) => [
                'id' => $h->id, 'acao' => $h->acao, 'antes' => $h->antes, 'depois' => $h->depois,
                'usuario' => $h->usuario?->name, 'data' => $h->created_at,
            ])]);
    }
}

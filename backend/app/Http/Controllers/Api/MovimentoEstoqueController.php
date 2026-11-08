<?php

namespace App\Http\Controllers\Api;

use App\Enums\TipoMovimentoEstoque;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarMovimentoEstoqueRequest;
use App\Http\Resources\MovimentoEstoqueResource;
use App\Models\MovimentoEstoque;
use App\Services\ServicoEstoque;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Entradas, saídas e ajustes de estoque. */
class MovimentoEstoqueController extends Controller
{
    private const RELACOES = ['item:id,nome,unidade', 'usuario:id,name'];

    public function __construct(private readonly ServicoEstoque $estoque) {}

    /** Filtros: item_estoque_id, tipo, inicio, fim (data do movimento). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filtros = $request->validate([
            'item_estoque_id' => ['nullable', 'integer'],
            'tipo' => ['nullable', Rule::enum(TipoMovimentoEstoque::class)],
            'inicio' => ['nullable', 'date_format:Y-m-d'],
            'fim' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:inicio'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $movimentos = MovimentoEstoque::with(self::RELACOES)
            ->when($filtros['item_estoque_id'] ?? null, fn ($q, $v) => $q->where('item_estoque_id', $v))
            ->when($filtros['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))
            ->when($filtros['inicio'] ?? null, fn ($q, $v) => $q->where('data_movimento', '>=', $v))
            ->when($filtros['fim'] ?? null, fn ($q, $v) => $q->where('data_movimento', '<=', $v))
            ->orderByDesc('data_movimento')->orderByDesc('id')
            ->paginate($filtros['por_pagina'] ?? 50);

        return MovimentoEstoqueResource::collection($movimentos);
    }

    public function store(RegistrarMovimentoEstoqueRequest $request): JsonResponse
    {
        $dados = $request->validated();

        // Reenvio da sincronização offline: devolve o movimento já gravado sem repetir o efeito no saldo.
        if (! empty($dados['uuid']) && $existente = MovimentoEstoque::firstWhere('uuid', $dados['uuid'])) {
            return (new MovimentoEstoqueResource($existente->load(self::RELACOES)))->response()->setStatusCode(200);
        }

        $movimento = $this->estoque->registrar($dados, $request->user());

        return (new MovimentoEstoqueResource($movimento->load(self::RELACOES)))->response()->setStatusCode(201);
    }

    public function cancelar(Request $request, MovimentoEstoque $movimento): MovimentoEstoqueResource
    {
        $motivo = $request->validate(['motivo' => ['required', 'string', 'max:255']], [], ['motivo' => 'motivo do cancelamento'])['motivo'];

        return new MovimentoEstoqueResource($this->estoque->cancelar($movimento, $motivo, $request->user())->load(self::RELACOES));
    }
}

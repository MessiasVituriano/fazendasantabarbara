<?php

namespace App\Http\Controllers\Api;

use App\Enums\TipoItemEstoque;
use App\Http\Controllers\Controller;
use App\Http\Requests\SalvarItemEstoqueRequest;
use App\Http\Resources\ItemEstoqueResource;
use App\Models\ItemEstoque;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Itens do estoque. Não são excluídos (os movimentos dependem deles): desativam-se. */
class ItemEstoqueController extends Controller
{
    /**
     * Filtros: tipo, busca, alerta (REPOR = no mínimo ou abaixo, VALIDADE = vencido ou vencendo),
     * inativos=1 inclui os desativados. meta.resumo conta os alertas de todos os itens ativos.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filtros = $request->validate([
            'tipo' => ['nullable', Rule::enum(TipoItemEstoque::class)],
            'busca' => ['nullable', 'string', 'max:100'],
            'alerta' => ['nullable', Rule::in(['REPOR', 'VALIDADE'])],
            'inativos' => ['nullable', 'boolean'],
        ]);

        $ativos = ItemEstoque::where('ativo', true)->get();
        $itens = ItemEstoque::query()
            ->when(! $request->boolean('inativos'), fn ($q) => $q->where('ativo', true))
            ->when($filtros['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))
            ->when($filtros['busca'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q
                ->whereLike('nome', "%{$v}%")->orWhereLike('localizacao', "%{$v}%")))
            ->orderBy('nome')
            ->get()
            ->when(($filtros['alerta'] ?? null) === 'REPOR', fn ($c) => $c->filter->abaixoDoMinimo())
            ->when(($filtros['alerta'] ?? null) === 'VALIDADE', fn ($c) => $c->filter(fn ($i) => $i->situacaoValidade() !== null))
            ->values();

        $resumo = [
            'itens' => $ativos->count(),
            'repor' => $ativos->filter->abaixoDoMinimo()->count(),
            'vencidos' => $ativos->filter(fn ($i) => $i->situacaoValidade() === 'VENCIDO')->count(),
            'vencendo' => $ativos->filter(fn ($i) => $i->situacaoValidade() === 'VENCE_EM_BREVE')->count(),
        ];
        if ($request->user()->ehGestor()) {
            $resumo['valor_total'] = round($ativos->sum(fn ($i) => (float) $i->saldo * (float) $i->custo_unitario), 2);
        }

        return ItemEstoqueResource::collection($itens)->additional(['meta' => [
            'resumo' => $resumo,
            'dias_aviso_validade' => config('pecuaria.estoque.dias_aviso_validade'),
        ]]);
    }

    public function show(ItemEstoque $item): ItemEstoqueResource
    {
        return new ItemEstoqueResource($item);
    }

    public function store(SalvarItemEstoqueRequest $request): ItemEstoqueResource
    {
        return new ItemEstoqueResource(ItemEstoque::create($request->validated()));
    }

    public function update(SalvarItemEstoqueRequest $request, ItemEstoque $item): ItemEstoqueResource
    {
        $item->update($request->validated());

        return new ItemEstoqueResource($item);
    }
}

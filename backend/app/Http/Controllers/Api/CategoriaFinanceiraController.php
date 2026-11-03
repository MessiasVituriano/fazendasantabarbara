<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalvarCategoriaFinanceiraRequest;
use App\Models\CategoriaFinanceira;
use Illuminate\Http\JsonResponse;

/** Plano de contas. Categorias não são excluídas (lançamentos dependem delas): desativam-se. */
class CategoriaFinanceiraController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => CategoriaFinanceira::withCount('lancamentos')->orderBy('tipo')->orderBy('nome')->get()
            ->map(fn ($c) => [...$c->toArray(), 'grupo_custo_label' => $c->grupo_custo?->label()])]);
    }

    public function store(SalvarCategoriaFinanceiraRequest $request): JsonResponse
    {
        return response()->json(['data' => CategoriaFinanceira::create($request->validated())], 201);
    }

    public function update(SalvarCategoriaFinanceiraRequest $request, CategoriaFinanceira $categoria): JsonResponse
    {
        // Mudar o grupo vale para lançamentos novos; os antigos guardam o grupo da época.
        $categoria->update($request->validated());

        return response()->json(['data' => $categoria]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalvarProdutoSanitarioRequest;
use App\Models\ProdutoSanitario;
use Illuminate\Http\JsonResponse;

/** Vacinas e medicamentos cadastrados. Não são excluídos (aplicações dependem deles): desativam-se. */
class ProdutoSanitarioController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => ProdutoSanitario::with('categoria:id,nome,tipo')->orderBy('nome')->get()]);
    }

    public function store(SalvarProdutoSanitarioRequest $request): JsonResponse
    {
        $produto = ProdutoSanitario::create($request->validated());

        return response()->json(['data' => $produto->load('categoria:id,nome,tipo')], 201);
    }

    public function update(SalvarProdutoSanitarioRequest $request, ProdutoSanitario $produto): JsonResponse
    {
        $produto->update($request->validated());

        return response()->json(['data' => $produto->load('categoria:id,nome,tipo')]);
    }
}

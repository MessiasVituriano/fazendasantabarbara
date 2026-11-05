<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalvarCategoriaSanitariaRequest;
use App\Models\CategoriaSanitaria;
use Illuminate\Http\JsonResponse;

/** Categorias de vacinas e medicamentos. Não são excluídas (aplicações dependem delas): desativam-se. */
class CategoriaSanitariaController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => CategoriaSanitaria::withCount('produtos')->orderBy('tipo')->orderBy('nome')->get()]);
    }

    public function store(SalvarCategoriaSanitariaRequest $request): JsonResponse
    {
        return response()->json(['data' => CategoriaSanitaria::create($request->validated())], 201);
    }

    public function update(SalvarCategoriaSanitariaRequest $request, CategoriaSanitaria $categoria): JsonResponse
    {
        // Mudar a sugestão vale para aplicações novas; as antigas guardam os dias da época.
        $categoria->update($request->validated());

        return response()->json(['data' => $categoria]);
    }
}

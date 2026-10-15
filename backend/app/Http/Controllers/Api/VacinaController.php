<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarVacinaRequest;
use App\Models\VacinaSanidade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

class VacinaController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $vacinas = VacinaSanidade::with('animal:id,brinco,nome')
            ->when($request->query('animal_id'), fn ($q, $v) => $q->where('animal_id', $v))
            ->when($request->boolean('vigentes'), fn ($q) => $q->vigentes())
            ->orderByDesc('data_aplicacao')
            ->paginate(min((int) $request->query('por_pagina', 50), 500));

        return JsonResource::collection($vacinas);
    }

    public function store(RegistrarVacinaRequest $request): JsonResource
    {
        return new JsonResource(VacinaSanidade::create($request->validated()));
    }

    public function destroy(VacinaSanidade $vacina): Response
    {
        $vacina->delete();

        return response()->noContent();
    }
}

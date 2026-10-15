<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalvarAnimalRequest;
use App\Http\Resources\AnimalResource;
use App\Models\Animal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AnimalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $animais = Animal::query()
            ->with(['mae:id,brinco,nome', 'pastoreioAtual.piquete:id,identificacao', 'fotoPrincipal'])
            ->comCarencia()
            ->when($request->query('busca'), fn ($q, $busca) => $q->where(fn ($q) => $q
                ->whereLike('brinco', "%{$busca}%")
                ->orWhereLike('nome', "%{$busca}%")))
            ->when($request->query('categoria'), fn ($q, $v) => $q->whereIn('categoria', (array) $v))
            ->when($request->query('aptidao'), fn ($q, $v) => $q->whereIn('aptidao', (array) $v))
            ->when($request->query('sexo'), fn ($q, $v) => $q->where('sexo', $v))
            ->when($request->query('status_reprodutivo'), fn ($q, $v) => $q->whereIn('status_reprodutivo', (array) $v))
            ->where('status_geral', $request->query('status_geral', 'ATIVO'))
            ->orderBy('brinco')
            ->paginate(min((int) $request->query('por_pagina', 25), 200));

        return AnimalResource::collection($animais);
    }

    public function store(SalvarAnimalRequest $request): AnimalResource
    {
        return new AnimalResource(Animal::create($request->validated())->load(['mae', 'fotoPrincipal']));
    }

    public function show(Animal $animal): AnimalResource
    {
        $animal = Animal::comCarencia()->with(['mae', 'pastoreioAtual.piquete', 'fotoPrincipal'])->findOrFail($animal->id);

        return new AnimalResource($animal);
    }

    public function update(SalvarAnimalRequest $request, Animal $animal): AnimalResource
    {
        $animal->update($request->validated());

        return $this->show($animal);
    }

    public function destroy(Animal $animal): Response
    {
        $animal->delete();

        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarVacinaRequest;
use App\Models\ProdutoSanitario;
use App\Models\VacinaSanidade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

class VacinaController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $vacinas = VacinaSanidade::with(['animal:id,brinco,nome', 'categoria:id,nome'])
            ->when($request->query('animal_id'), fn ($q, $v) => $q->where('animal_id', $v))
            ->when($request->query('tipo'), fn ($q, $v) => $q->where('tipo', $v))
            ->when($request->query('categoria_sanitaria_id'), fn ($q, $v) => $q->where('categoria_sanitaria_id', $v))
            ->when($request->boolean('vigentes'), fn ($q) => $q->vigentes())
            ->when($request->query('busca'), fn ($q, $busca) => $q->where(fn ($q) => $q
                ->whereLike('nome_vacina', "%{$busca}%")
                ->orWhereHas('animal', fn ($a) => $a->whereLike('brinco', "%{$busca}%")->orWhereLike('nome', "%{$busca}%"))))
            ->orderByDesc('data_aplicacao')
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('por_pagina', 50), 500));

        return JsonResource::collection($vacinas);
    }

    public function store(RegistrarVacinaRequest $request): JsonResource
    {
        $dados = $request->validated();

        // Reenvio da sincronização offline: devolve o registro já gravado sem duplicar.
        if (! empty($dados['uuid']) && $existente = VacinaSanidade::firstWhere('uuid', $dados['uuid'])) {
            return new JsonResource($existente->load('categoria:id,nome'));
        }

        // Nome e categoria são copiados do produto: renomeá-lo depois não reescreve o histórico.
        if ($produto = ProdutoSanitario::find($dados['produto_sanitario_id'] ?? null)) {
            $dados['nome_vacina'] = $produto->nome;
            $dados['categoria_sanitaria_id'] = $produto->categoria_sanitaria_id;
        }

        return new JsonResource(VacinaSanidade::create($dados)->load('categoria:id,nome'));
    }

    public function destroy(VacinaSanidade $vacina): Response
    {
        $vacina->delete();

        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalvarPessoaRequest;
use App\Models\Pessoa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Fornecedores e clientes. */
class PessoaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $pessoas = Pessoa::query()
            ->when($request->query('tipo'), fn ($q, $t) => $q->whereIn('tipo', [$t, 'AMBOS']))
            ->when($request->boolean('somente_ativos'), fn ($q) => $q->where('ativo', true))
            ->when(trim((string) $request->query('busca')), fn ($q, $b) => $q->where(fn ($q) => $q
                ->whereLike('nome', "%{$b}%")->orWhereLike('documento', '%'.preg_replace('/\D/', '', $b).'%')))
            ->orderBy('nome')->get();

        return response()->json(['data' => $pessoas->map(fn ($p) => [...$p->toArray(), 'tipo_label' => $p->tipo->label()])]);
    }

    public function store(SalvarPessoaRequest $request): JsonResponse
    {
        return response()->json(['data' => Pessoa::create($request->validated())], 201);
    }

    public function update(SalvarPessoaRequest $request, Pessoa $pessoa): JsonResponse
    {
        $pessoa->update($request->validated());

        return response()->json(['data' => $pessoa]);
    }
}

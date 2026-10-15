<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarEventoReprodutivoRequest;
use App\Models\EventoReprodutivo;
use App\Services\ServicoReprodutivo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class EventoReprodutivoController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $eventos = EventoReprodutivo::with(['animal:id,brinco,nome', 'bezerro:id,brinco,sexo'])
            ->when($request->query('animal_id'), fn ($q, $v) => $q->where('animal_id', $v))
            ->when($request->query('tipo_evento'), fn ($q, $v) => $q->whereIn('tipo_evento', (array) $v))
            ->orderByDesc('data_evento')->orderByDesc('id')
            ->paginate(min((int) $request->query('por_pagina', 50), 500));

        return JsonResource::collection($eventos);
    }

    /** RF01 + RF02: lote atômico, processado em ordem cronológica. */
    public function store(RegistrarEventoReprodutivoRequest $request, ServicoReprodutivo $servico): JsonResponse
    {
        $resultados = DB::transaction(fn () => array_map(
            fn (array $registro) => $servico->registrar($registro),
            $request->registros(),
        ));

        $criados = count(array_filter($resultados, fn ($r) => $r['criado']));

        return JsonResource::collection(array_column($resultados, 'evento'))
            ->additional(['meta' => ['criados' => $criados, 'ja_sincronizados' => count($resultados) - $criados]])
            ->response()
            ->setStatusCode($criados > 0 ? 201 : 200);
    }
}

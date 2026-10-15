<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarProducaoLeiteRequest;
use App\Http\Requests\SalvarOrdenhaRequest;
use App\Models\ProducaoLeite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class ProducaoLeiteController extends Controller
{
    use GravaEmLote;

    public function index(Request $request): AnonymousResourceCollection
    {
        $producoes = ProducaoLeite::with('animal:id,brinco,nome')
            ->when($request->query('animal_id'), fn ($q, $v) => $q->where('animal_id', $v))
            ->when($request->query('periodo'), fn ($q, $v) => $q->where('periodo', $v))
            ->when($request->query('data_inicio'), fn ($q, $v) => $q->where('data_pesagem', '>=', $v))
            ->when($request->query('data_fim'), fn ($q, $v) => $q->where('data_pesagem', '<=', $v))
            ->orderByDesc('data_pesagem')->orderBy('periodo')
            ->paginate(min((int) $request->query('por_pagina', 50), 500));

        return JsonResource::collection($producoes);
    }

    /**
     * Grava ou corrige a ordenha de um turno (data + período): cria, atualiza ou, com
     * `quantidade_litros: null`, remove o registro de cada vaca. Tudo ou nada.
     */
    public function salvarTurno(SalvarOrdenhaRequest $request): JsonResponse
    {
        $data = $request->validated('data_pesagem');
        $periodo = $request->validated('periodo');
        $contagem = ['gravados' => 0, 'removidos' => 0];

        DB::transaction(function () use ($request, $data, $periodo, &$contagem) {
            foreach ($request->validated('registros') as $r) {
                $chave = ['animal_id' => $r['animal_id'], 'data_pesagem' => $data, 'periodo' => $periodo];

                if ($r['quantidade_litros'] === null) {
                    $contagem['removidos'] += ProducaoLeite::where($chave)->delete();
                } else {
                    ProducaoLeite::updateOrCreate($chave, ['quantidade_litros' => $r['quantidade_litros']]);
                    $contagem['gravados']++;
                }
            }
        });

        $doTurno = ProducaoLeite::where(['data_pesagem' => $data, 'periodo' => $periodo])->orderBy('animal_id')->get();

        return JsonResource::collection($doTurno)
            ->additional(['meta' => [...$contagem, 'total_litros' => round($doTurno->sum(fn ($p) => (float) $p->quantidade_litros), 2)]])
            ->response();
    }

    /** RF01: aceita um registro ou um lote. */
    public function store(RegistrarProducaoLeiteRequest $request): JsonResponse
    {
        return $this->gravarEmLote(
            $request->registros(),
            fn (array $r) => ProducaoLeite::create($r),
            ProducaoLeite::class,
        );
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarPesagemRequest;
use App\Http\Resources\PesagemResource;
use App\Models\Pesagem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PesagemController extends Controller
{
    use GravaEmLote;

    /** Lista com GMD calculado por window function (RF03). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $pesagens = Pesagem::comGmd()
            ->when($request->query('animal_id'), fn ($q, $v) => $q->where('animal_id', $v))
            ->orderByDesc('data_pesagem')
            ->paginate(min((int) $request->query('por_pagina', 50), 500));

        return PesagemResource::collection($pesagens);
    }

    /** RF01: aceita um registro ou um lote. */
    public function store(RegistrarPesagemRequest $request): JsonResponse
    {
        return $this->gravarEmLote(
            $request->registros(),
            fn (array $r) => Pesagem::create($r),
            Pesagem::class,
            fn ($pesagens) => PesagemResource::collection($pesagens),
        );
    }
}

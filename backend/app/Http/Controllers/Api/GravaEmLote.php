<?php

namespace App\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Gravação atômica de lotes (RF01): ou todos os registros entram, ou nenhum.
 * Registros com `uuid` já gravado são reenvios da sincronização e não são duplicados.
 */
trait GravaEmLote
{
    /**
     * @param  list<array<string, mixed>>  $registros
     * @param  callable(array): Model  $criar
     * @param  class-string<Model>  $modelo
     */
    protected function gravarEmLote(array $registros, callable $criar, string $modelo, ?callable $recurso = null): JsonResponse
    {
        [$gravados, $criados] = DB::transaction(function () use ($registros, $criar, $modelo) {
            $existentes = $modelo::whereIn('uuid', array_filter(array_column($registros, 'uuid')))->get()->keyBy('uuid');
            $gravados = [];
            $criados = 0;

            foreach ($registros as $registro) {
                if (! empty($registro['uuid']) && $existentes->has($registro['uuid'])) {
                    $gravados[] = $existentes[$registro['uuid']];

                    continue;
                }
                $gravados[] = $criar($registro);
                $criados++;
            }

            return [$gravados, $criados];
        });

        $colecao = $recurso ? $recurso($gravados) : JsonResource::collection($gravados);

        return $colecao
            ->additional(['meta' => ['criados' => $criados, 'ja_sincronizados' => count($gravados) - $criados]])
            ->response()
            ->setStatusCode($criados > 0 ? 201 : 200);
    }
}

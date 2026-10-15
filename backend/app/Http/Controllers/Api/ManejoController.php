<?php

namespace App\Http\Controllers\Api;

use App\Enums\Aptidao;
use App\Enums\Categoria;
use App\Http\Controllers\Controller;
use App\Http\Resources\AnimalResource;
use App\Models\Animal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * RF04 — listas de ordenha e abate com controle de carência sanitária.
 * Por padrão os animais em carência vêm marcados com `alerta.nivel = CRITICO`
 * (e no topo da lista); com `?ocultar_em_carencia=1` eles são omitidos.
 */
class ManejoController extends Controller
{
    public function ordenha(Request $request): AnonymousResourceCollection
    {
        return $this->listar($request, fn (Builder $q) => $q->where('categoria', Categoria::LACTACAO));
    }

    public function abate(Request $request): AnonymousResourceCollection
    {
        return $this->listar($request, fn (Builder $q) => $q
            ->whereIn('aptidao', [Aptidao::CORTE, Aptidao::MISTA])
            ->whereIn('categoria', (array) $request->query('categoria', [Categoria::BOI_GORDO->value])));
    }

    private function listar(Request $request, callable $filtro): AnonymousResourceCollection
    {
        $animais = Animal::ativos()
            ->tap($filtro)
            ->with(['pastoreioAtual.piquete', 'fotoPrincipal'])
            ->comCarencia()
            ->when($request->boolean('ocultar_em_carencia'), fn ($q) => $q->emCarencia(false))
            ->orderByDesc('em_carencia')
            ->orderBy('brinco')
            ->get();

        return AnimalResource::collection($animais)->additional([
            'meta' => [
                'total' => $animais->count(),
                'em_carencia' => $animais->where('em_carencia', true)->count(),
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EnviarFotoRequest;
use App\Models\Animal;
use App\Models\FotoAnimal;
use App\Services\ProcessadorFoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class FotoAnimalController extends Controller
{
    public function index(Animal $animal): AnonymousResourceCollection
    {
        return JsonResource::collection($animal->fotos()->get());
    }

    /** Upload multipart (campo `foto`). A primeira foto do animal vira a principal. */
    public function store(EnviarFotoRequest $request, Animal $animal, ProcessadorFoto $processador): JsonResource
    {
        $arquivos = $processador->salvar($request->file('foto'), "fazendas/{$animal->fazenda_id}/animais/{$animal->id}");

        try {
            $foto = DB::transaction(function () use ($request, $animal, $arquivos) {
                $principal = $request->boolean('principal') || ! $animal->fotos()->exists();
                if ($principal) {
                    $animal->fotos()->update(['principal' => false]);
                }

                return $animal->fotos()->create([
                    ...$arquivos,
                    'legenda' => $request->validated('legenda'),
                    'data_registro' => $request->validated('data_registro') ?? today(),
                    'principal' => $principal,
                ]);
            });
        } catch (\Throwable $e) {
            FotoAnimal::disco()->delete([$arquivos['caminho'], $arquivos['caminho_miniatura']]);
            throw $e;
        }

        return new JsonResource($foto);
    }

    /** Altera legenda e/ou define como foto principal. */
    public function update(Request $request, Animal $animal, FotoAnimal $foto): JsonResource
    {
        $dados = $request->validate([
            'legenda' => ['sometimes', 'nullable', 'string', 'max:255'],
            'principal' => ['sometimes', 'accepted'],
        ]);

        DB::transaction(function () use ($animal, $foto, $dados) {
            if (isset($dados['principal'])) {
                $animal->fotos()->whereKeyNot($foto->id)->update(['principal' => false]);
            }
            $foto->update($dados);
        });

        return new JsonResource($foto->refresh());
    }

    /** Exclui a foto; se era a principal, a mais recente restante assume. */
    public function destroy(Animal $animal, FotoAnimal $foto): Response
    {
        DB::transaction(function () use ($animal, $foto) {
            $foto->delete();
            if ($foto->principal) {
                $animal->fotos()->first()?->update(['principal' => true]);
            }
        });

        return response()->noContent();
    }
}

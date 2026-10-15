<?php

namespace App\Http\Controllers\Api;

use App\Enums\StatusPiquete;
use App\Exceptions\RegraNegocioException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MovimentarPiqueteRequest;
use App\Http\Requests\SalvarPiqueteRequest;
use App\Models\HistoricoPastoreio;
use App\Models\Piquete;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PiqueteController extends Controller
{
    /** RN03: cards com status e quantidade de animais alocados agora. */
    public function index(): AnonymousResourceCollection
    {
        return JsonResource::collection(
            Piquete::withCount('ocupacoesAtuais as animais_alocados')->orderBy('identificacao')->get()
        );
    }

    public function store(SalvarPiqueteRequest $request): JsonResource
    {
        return new JsonResource(Piquete::create($request->validated()));
    }

    public function show(Piquete $piquete): JsonResource
    {
        $piquete->loadCount('ocupacoesAtuais as animais_alocados')
            ->load(['ocupacoesAtuais.animal:id,brinco,nome,categoria']);

        return new JsonResource($piquete);
    }

    public function update(SalvarPiqueteRequest $request, Piquete $piquete): JsonResource
    {
        $dados = $request->validated();

        if (($dados['status'] ?? null) && $dados['status'] !== StatusPiquete::OCUPADO->value
            && $piquete->ocupacoesAtuais()->exists()) {
            throw new RegraNegocioException(
                'Retire os animais do piquete antes de colocá-lo em descanso ou marcá-lo como degradado.',
                'PIQUETE_OCUPADO', 422, '/status',
            );
        }
        $piquete->update($dados);

        return $this->show($piquete);
    }

    public function destroy(Piquete $piquete): Response
    {
        if ($piquete->ocupacoesAtuais()->exists()) {
            throw new RegraNegocioException('Não é possível excluir um piquete com animais alocados.', 'PIQUETE_OCUPADO', 409);
        }
        $piquete->delete();

        return response()->noContent();
    }

    /** Move animais para o piquete, encerrando a ocupação anterior de cada um. */
    public function entradas(MovimentarPiqueteRequest $request, Piquete $piquete): JsonResource
    {
        if ($piquete->status === StatusPiquete::DEGRADADO) {
            throw new RegraNegocioException("O piquete {$piquete->identificacao} está degradado e não pode receber animais.", 'PIQUETE_DEGRADADO');
        }
        $data = $request->validated('data_entrada');
        $ids = $request->validated('animal_ids');

        DB::transaction(function () use ($piquete, $ids, $data) {
            $anteriores = HistoricoPastoreio::whereIn('animal_id', $ids)->whereNull('data_saida')->get();
            HistoricoPastoreio::whereKey($anteriores->modelKeys())->update(['data_saida' => $data]);
            $this->liberarSeVazios($anteriores->pluck('piquete_id')->unique()->diff([$piquete->id]));

            foreach ($ids as $id) {
                $piquete->historico()->create(['animal_id' => $id, 'data_entrada' => $data]);
            }
            $piquete->update(['status' => StatusPiquete::OCUPADO]);
        });

        return $this->show($piquete);
    }

    /** Retira animais do piquete (todos, se animal_ids não for enviado). */
    public function saidas(MovimentarPiqueteRequest $request, Piquete $piquete): JsonResource
    {
        DB::transaction(function () use ($request, $piquete) {
            $piquete->ocupacoesAtuais()
                ->when($request->validated('animal_ids'), fn ($q, $ids) => $q->whereIn('animal_id', $ids))
                ->update(['data_saida' => $request->validated('data_saida')]);
            $this->liberarSeVazios(collect([$piquete->id]));
        });

        return $this->show($piquete->refresh());
    }

    /** Piquete ocupado que ficou sem animais entra em descanso. */
    private function liberarSeVazios($piqueteIds): void
    {
        Piquete::whereKey($piqueteIds)
            ->where('status', StatusPiquete::OCUPADO)
            ->whereDoesntHave('ocupacoesAtuais')
            ->update(['status' => StatusPiquete::DESCANSO]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Enums\Aptidao;
use App\Enums\StatusGeral;
use App\Exceptions\RegraNegocioException;
use App\Http\Controllers\Controller;
use App\Http\Requests\MovimentarLoteRequest;
use App\Http\Requests\SalvarMetaEngordaRequest;
use App\Models\Animal;
use App\Models\AnimalLote;
use App\Models\Lote;
use App\Models\MetaEngorda;
use App\Services\PainelEngorda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Engorda: animais no lote, metas, projeção de peso e ração consumida.
 * Movimentar animais e metas é do gestor; a ficha do animal e a lista de lotes ativos valem para os dois perfis.
 */
class EngordaController extends Controller
{
    public function __construct(private readonly PainelEngorda $painel) {}

    /** Painel do lote: médias, meta, série, animais e consumo de ração. */
    public function lote(Lote $lote): JsonResponse
    {
        $lote->load('metaAtual');
        $engorda = $this->painel->resumoLote($lote);

        return response()->json(['data' => [
            ...$engorda,
            'racao' => $this->painel->consumoLote($lote, $engorda['resumo']['animais'], $engorda['resumo']['gmd_medio']),
        ]]);
    }

    /** Tendência de peso e previsão do animal (ficha). */
    public function animal(Animal $animal): JsonResponse
    {
        return response()->json(['data' => $this->painel->crescimentoAnimal($animal)]);
    }

    /** Lotes ativos para escolher o destino de uma saída de estoque (sem dados financeiros). */
    public function lotesAtivos(): JsonResponse
    {
        $lotes = Lote::ativos()->select(['id', 'nome', 'categoria'])->withCount('ocupacoesAtuais as animais')->orderBy('nome')->get();

        return response()->json(['data' => $lotes]);
    }

    public function alertas(): JsonResponse
    {
        return response()->json(['data' => $this->painel->alertas()]);
    }

    /** Coloca animais no lote, encerrando a passagem anterior de cada um por outro lote. */
    public function entradas(MovimentarLoteRequest $request, Lote $lote): JsonResponse
    {
        $this->exigirAtivo($lote);
        $data = $request->validated('data_entrada');
        $ids = collect($request->validated('animal_ids'));

        if ($data < $lote->data_formacao->toDateString()) {
            throw new RegraNegocioException('A entrada não pode ser anterior à formação do lote ('.$lote->data_formacao->format('d/m/Y').').', 'DATA_INVALIDA', ponteiro: '/data_entrada');
        }
        $invalidos = Animal::whereKey($ids)
            ->where(fn ($q) => $q->whereNotIn('aptidao', [Aptidao::CORTE, Aptidao::MISTA])->orWhere('status_geral', '!=', StatusGeral::ATIVO))
            ->pluck('brinco');
        if ($invalidos->isNotEmpty()) {
            throw new RegraNegocioException('Só animais ativos de corte ou mista entram em lote de engorda: '.$invalidos->join(', ').'.', 'ANIMAL_FORA_DA_ENGORDA', ponteiro: '/animal_ids');
        }

        DB::transaction(function () use ($lote, $ids, $data) {
            $atuais = AnimalLote::whereIn('animal_id', $ids)->whereNull('data_saida')->get();
            $conflito = $atuais->first(fn ($o) => $o->lote_id !== $lote->id && $o->data_entrada->toDateString() > $data);
            if ($conflito) {
                throw new RegraNegocioException('Um dos animais entrou no lote atual depois desta data ('.$conflito->data_entrada->format('d/m/Y').').', 'DATA_INVALIDA', ponteiro: '/data_entrada');
            }
            // Quem já está neste lote continua como está.
            $jaNoLote = $atuais->where('lote_id', $lote->id)->pluck('animal_id');
            AnimalLote::whereKey($atuais->where('lote_id', '!=', $lote->id)->modelKeys())->update(['data_saida' => $data]);
            foreach ($ids->diff($jaNoLote) as $id) {
                $lote->historicoAnimais()->create(['animal_id' => $id, 'data_entrada' => $data]);
            }
        });

        return $this->lote($lote);
    }

    /** Retira animais do lote (todos, se animal_ids não for enviado). */
    public function saidas(MovimentarLoteRequest $request, Lote $lote): JsonResponse
    {
        $data = $request->validated('data_saida');
        $ocupacoes = $lote->ocupacoesAtuais()
            ->when($request->validated('animal_ids'), fn ($q, $ids) => $q->whereIn('animal_id', $ids));

        if ((clone $ocupacoes)->where('data_entrada', '>', $data)->exists()) {
            throw new RegraNegocioException('A saída não pode ser anterior à entrada do animal no lote.', 'DATA_INVALIDA', ponteiro: '/data_saida');
        }
        $ocupacoes->update(['data_saida' => $data]);

        return $this->lote($lote);
    }

    /** Nova meta do lote. A meta vigente, se houver, é cancelada como substituída. */
    public function salvarMeta(SalvarMetaEngordaRequest $request, Lote $lote): JsonResponse
    {
        $this->exigirAtivo($lote);
        $dados = $request->validated();

        $meta = DB::transaction(function () use ($lote, $dados) {
            $lote->metas()->vigentes()->get()->each(function (MetaEngorda $m) {
                $m->forceFill(['cancelada_em' => now(), 'motivo_cancelamento' => 'Substituída por nova meta'])->save();
            });

            return $lote->metas()->create([
                ...$dados,
                'data_inicio' => $dados['data_inicio'] ?? Carbon::today()->toDateString(),
                'peso_inicial_kg' => $this->painel->resumoLote($lote->setRelation('metaAtual', null))['resumo']['peso_medio'],
            ]);
        });

        return response()->json(['data' => ['id' => $meta->id]], 201);
    }

    public function cancelarMeta(Request $request, Lote $lote, MetaEngorda $meta): JsonResponse
    {
        $motivo = $request->validate(['motivo' => ['nullable', 'string', 'max:255']])['motivo'] ?? null;
        abort_if($meta->lote_id !== $lote->id, 404);
        if ($meta->cancelada_em) {
            throw new RegraNegocioException('Esta meta já foi cancelada.', 'JA_CANCELADO');
        }
        $meta->forceFill(['cancelada_em' => now(), 'motivo_cancelamento' => $motivo ?: 'Cancelada pelo gestor'])->save();

        return response()->json(['data' => ['id' => $meta->id, 'cancelada' => true]]);
    }

    private function exigirAtivo(Lote $lote): void
    {
        if ($lote->data_encerramento) {
            throw new RegraNegocioException("O {$lote->nome} está encerrado.", 'LOTE_ENCERRADO');
        }
    }
}

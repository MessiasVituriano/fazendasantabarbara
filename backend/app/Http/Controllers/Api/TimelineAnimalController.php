<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Animal;
use App\Models\Pesagem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * RN01 — linha do tempo cronológica do animal: pesagens, produção de leite,
 * eventos reprodutivos, vacinas e o próprio nascimento.
 */
class TimelineAnimalController extends Controller
{
    public function __invoke(Animal $animal): JsonResponse
    {
        $itens = collect([
            [
                'tipo' => 'NASCIMENTO',
                'data' => $animal->data_nascimento->toDateString(),
                'titulo' => 'Nascimento',
                'descricao' => $animal->mae ? "Filho(a) de {$animal->mae->brinco}" : null,
                'dados' => ['mae_id' => $animal->mae_id],
            ],
        ])
            ->concat($this->pesagens($animal))
            ->concat($this->producoes($animal))
            ->concat($this->eventos($animal))
            ->concat($this->vacinas($animal))
            ->sortByDesc('data')
            ->values();

        return response()->json(['data' => $itens]);
    }

    private function pesagens(Animal $animal): Collection
    {
        return Pesagem::comGmd()->where('animal_id', $animal->id)->get()->map(function (Pesagem $p) {
            $gmd = $p->gmd();

            return [
                'tipo' => 'PESAGEM',
                'data' => $p->data_pesagem->toDateString(),
                'titulo' => 'Pesagem: '.number_format((float) $p->peso_kg, 2, ',', '.').' kg',
                'descricao' => $gmd === null ? 'Primeira pesagem' : 'GMD: '.number_format($gmd, 3, ',', '.').' kg/dia',
                'dados' => ['id' => $p->id, 'peso_kg' => (float) $p->peso_kg, 'gmd_kg_dia' => $gmd],
            ];
        });
    }

    /** Produção agrupada por dia (manhã + tarde). */
    private function producoes(Animal $animal): Collection
    {
        return $animal->producoesLeite()->orderBy('periodo')->get()
            ->groupBy(fn ($p) => $p->data_pesagem->toDateString())
            ->map(function (Collection $doDia, string $data) {
                $total = $doDia->sum(fn ($p) => (float) $p->quantidade_litros);
                $partes = $doDia->map(fn ($p) => $p->periodo->label().' '.number_format((float) $p->quantidade_litros, 2, ',', '.'));

                return [
                    'tipo' => 'PRODUCAO_LEITE',
                    'data' => $data,
                    'titulo' => 'Produção de leite: '.number_format($total, 2, ',', '.').' L',
                    'descricao' => $partes->implode(' · '),
                    'dados' => ['total_litros' => $total, 'registros' => $doDia->values()],
                ];
            })
            ->values();
    }

    private function eventos(Animal $animal): Collection
    {
        return $animal->eventosReprodutivos()->with('bezerro:id,brinco,sexo')->get()->map(fn ($e) => [
            'tipo' => 'EVENTO_REPRODUTIVO',
            'data' => $e->data_evento->toDateString(),
            'titulo' => $e->tipo_evento->label(),
            'descricao' => $e->bezerro
                ? "Bezerro(a) {$e->bezerro->brinco} ({$e->bezerro->sexo->label()})".($e->observacoes ? " — {$e->observacoes}" : '')
                : $e->observacoes,
            'dados' => ['id' => $e->id, 'tipo_evento' => $e->tipo_evento, 'bezerro_id' => $e->bezerro_id],
        ]);
    }

    private function vacinas(Animal $animal): Collection
    {
        return $animal->vacinas()->get()->map(fn ($v) => [
            'tipo' => 'VACINA',
            'data' => $v->data_aplicacao->toDateString(),
            'titulo' => ($v->tipo?->label() ?? 'Aplicação').": {$v->nome_vacina}",
            'descricao' => $v->dias_carencia_leite_carne > 0
                ? "Carência de {$v->dias_carencia_leite_carne} dias (até {$v->data_fim_carencia->format('d/m/Y')})"
                : 'Sem carência',
            'dados' => ['id' => $v->id, 'data_fim_carencia' => $v->data_fim_carencia->toDateString()],
        ]);
    }
}

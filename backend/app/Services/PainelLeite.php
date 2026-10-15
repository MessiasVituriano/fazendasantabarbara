<?php

namespace App\Services;

use App\Enums\Categoria;
use App\Models\Animal;
use App\Models\ProducaoLeite;
use App\Models\VacinaSanidade;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Indicadores de produção de leite para o painel.
 *
 * Leite "em carência" = produção de vacas que, na data da ordenha, estavam dentro do período
 * de carência de alguma vacina/medicamento (RF04): não pode ser comercializado.
 */
class PainelLeite
{
    /** @return array<string, mixed> */
    public function gerar(int $dias, ?Carbon $ate = null): array
    {
        $fim = ($ate ?? Carbon::today())->copy()->startOfDay();

        return $this->gerarIntervalo($fim->copy()->subDays($dias - 1), $fim);
    }

    /**
     * Indicadores de um intervalo livre; a comparação usa o intervalo anterior de mesmo tamanho.
     *
     * @return array<string, mixed>
     */
    public function gerarIntervalo(Carbon $inicio, Carbon $fim): array
    {
        $inicio = $inicio->copy()->startOfDay();
        $fim = $fim->copy()->startOfDay();
        $dias = (int) $inicio->diffInDays($fim) + 1;
        $inicioAnterior = $inicio->copy()->subDays($dias);

        $registros = ProducaoLeite::query()
            ->whereBetween('data_pesagem', [$inicioAnterior->toDateString(), $fim->toDateString()])
            ->get(['animal_id', 'data_pesagem', 'periodo', 'quantidade_litros']);

        [$atual, $anterior] = $registros->partition(fn ($r) => $r->data_pesagem->gte($inicio));
        $carencias = $this->carenciasPorAnimal($atual->pluck('animal_id')->unique(), $inicio, $fim);

        $serie = $this->serieDiaria($atual, $carencias, $inicio, $fim);
        $totalAtual = $atual->sum(fn ($r) => (float) $r->quantidade_litros);
        $totalAnterior = $anterior->sum(fn ($r) => (float) $r->quantidade_litros);
        $vacasDia = collect($serie)->sum('vacas');
        $ultimo = collect($serie)->reverse()->first(fn ($d) => $d['total'] > 0);

        return [
            'periodo' => ['inicio' => $inicio->toDateString(), 'fim' => $fim->toDateString(), 'dias' => $dias],
            'resumo' => [
                'total_litros' => round($totalAtual, 2),
                'media_diaria' => round($totalAtual / $dias, 2),
                'media_diaria_anterior' => $anterior->isEmpty() ? null : round($totalAnterior / $dias, 2),
                'variacao_pct' => $totalAnterior > 0 ? round(($totalAtual - $totalAnterior) / $totalAnterior * 100, 1) : null,
                'media_por_vaca_dia' => $vacasDia > 0 ? round($totalAtual / $vacasDia, 2) : null,
                'ultimo_dia' => $ultimo ? ['data' => $ultimo['data'], 'total' => $ultimo['total'], 'vacas' => $ultimo['vacas']] : null,
                'vacas_em_lactacao' => Animal::ativos()->where('categoria', Categoria::LACTACAO)->count(),
                'litros_em_carencia' => round(collect($serie)->sum('em_carencia'), 2),
            ],
            'serie' => $serie,
            'ranking' => $this->ranking($atual, $anterior),
        ];
    }

    /**
     * Comparativo entre vacas num intervalo livre: média diária, total e participação de cada uma,
     * com variação contra o intervalo anterior de mesmo tamanho.
     *
     * @return array<string, mixed>
     */
    public function comparativo(Carbon $inicio, Carbon $fim): array
    {
        $inicio = $inicio->copy()->startOfDay();
        $fim = $fim->copy()->startOfDay();
        $dias = (int) $inicio->diffInDays($fim) + 1;
        $inicioAnterior = $inicio->copy()->subDays($dias);

        $registros = ProducaoLeite::query()
            ->whereBetween('data_pesagem', [$inicioAnterior->toDateString(), $fim->toDateString()])
            ->get(['animal_id', 'data_pesagem', 'periodo', 'quantidade_litros']);
        [$atual, $anterior] = $registros->partition(fn ($r) => $r->data_pesagem->gte($inicio));

        return [
            'periodo' => ['inicio' => $inicio->toDateString(), 'fim' => $fim->toDateString(), 'dias' => $dias],
            'total_litros' => round($atual->sum(fn ($r) => (float) $r->quantidade_litros), 2),
            'ranking' => $this->ranking($atual, $anterior),
        ];
    }

    /** @return Collection<int, Collection<int, array{0: string, 1: string}>> intervalos [início, fim] por animal */
    private function carenciasPorAnimal(Collection $animalIds, Carbon $inicio, Carbon $fim): Collection
    {
        return VacinaSanidade::whereIn('animal_id', $animalIds)
            ->where('dias_carencia_leite_carne', '>', 0)
            ->where('data_aplicacao', '<=', $fim->toDateString())
            ->where('data_fim_carencia', '>=', $inicio->toDateString())
            ->get(['animal_id', 'data_aplicacao', 'data_fim_carencia'])
            ->groupBy('animal_id')
            ->map(fn ($vs) => $vs->map(fn ($v) => [$v->data_aplicacao->toDateString(), $v->data_fim_carencia->toDateString()]));
    }

    private function serieDiaria(Collection $registros, Collection $carencias, Carbon $inicio, Carbon $fim): array
    {
        $porDia = $registros->groupBy(fn ($r) => $r->data_pesagem->toDateString());
        $serie = [];

        for ($dia = $inicio->copy(); $dia->lte($fim); $dia->addDay()) {
            $data = $dia->toDateString();
            $doDia = $porDia->get($data, collect());
            $soma = fn ($rs) => round($rs->sum(fn ($r) => (float) $r->quantidade_litros), 2);
            $emCarencia = $doDia->filter(fn ($r) => $carencias->get($r->animal_id, collect())
                ->contains(fn ($c) => $data >= $c[0] && $data <= $c[1]));

            $serie[] = [
                'data' => $data,
                'manha' => $soma($doDia->where('periodo.value', 'MANHA')),
                'tarde' => $soma($doDia->where('periodo.value', 'TARDE')),
                'total' => $soma($doDia),
                'vacas' => $doDia->pluck('animal_id')->unique()->count(),
                'em_carencia' => $soma($emCarencia),
            ];
        }

        return $serie;
    }

    /** Média diária por vaca no período e variação contra o período anterior (quedas bruscas = atenção). */
    private function ranking(Collection $atual, Collection $anterior): array
    {
        $media = fn (Collection $rs) => $rs->groupBy(fn ($r) => $r->data_pesagem->toDateString())->count() > 0
            ? $rs->sum(fn ($r) => (float) $r->quantidade_litros) / $rs->groupBy(fn ($r) => $r->data_pesagem->toDateString())->count()
            : null;

        $anteriorPorVaca = $anterior->groupBy('animal_id');
        $animais = Animal::with('fotoPrincipal')->comCarencia()
            ->whereIn('id', $atual->pluck('animal_id')->unique())->get()->keyBy('id');

        return $atual->groupBy('animal_id')
            ->map(function (Collection $rs, int $animalId) use ($media, $anteriorPorVaca, $animais) {
                $animal = $animais[$animalId];
                $mediaAtual = $media($rs);
                $mediaAnterior = $anteriorPorVaca->has($animalId) ? $media($anteriorPorVaca[$animalId]) : null;

                return [
                    'animal_id' => $animalId,
                    'brinco' => $animal->brinco,
                    'nome' => $animal->nome,
                    'aptidao' => $animal->aptidao,
                    'categoria' => $animal->categoria,
                    'sexo' => $animal->sexo,
                    'foto' => $animal->fotoPrincipal ? ['miniatura_url' => $animal->fotoPrincipal->miniatura_url] : null,
                    'em_carencia' => (bool) $animal->em_carencia,
                    'media_diaria' => round($mediaAtual, 2),
                    'total_litros' => round($rs->sum(fn ($r) => (float) $r->quantidade_litros), 2),
                    'dias_ordenhados' => $rs->groupBy(fn ($r) => $r->data_pesagem->toDateString())->count(),
                    'variacao_pct' => $mediaAnterior ? round(($mediaAtual - $mediaAnterior) / $mediaAnterior * 100, 1) : null,
                ];
            })
            ->sortByDesc('media_diaria')
            ->values()
            ->all();
    }
}

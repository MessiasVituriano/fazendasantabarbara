<?php

namespace App\Services;

use App\Enums\Aptidao;
use App\Enums\TipoMovimentoEstoque;
use App\Models\Animal;
use App\Models\AnimalLote;
use App\Models\Lote;
use App\Models\MetaEngorda;
use App\Models\MovimentoEstoque;
use App\Models\Pesagem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Engorda por animal e por lote: tendência de peso, projeção, meta e ração consumida.
 * As contas ficam na ProjecaoCrescimento; aqui só se busca o que elas precisam, em poucas consultas.
 */
class PainelEngorda
{
    /** Dias de pesagens exibidos nos gráficos (a tendência usa só a janela da config). */
    private const DIAS_HISTORICO = 365;

    public function __construct(private readonly ProjecaoCrescimento $projecao) {}

    /**
     * Ficha do animal: pesagens com as suspeitas marcadas, tendência, lote atual com a meta e
     * a previsão para o peso alvo (o da meta do lote, ou o peso de abate padrão).
     *
     * @return array<string, mixed>
     */
    public function crescimentoAnimal(Animal $animal): array
    {
        $pesagens = Pesagem::where('animal_id', $animal->id)
            ->where('data_pesagem', '>=', Carbon::today()->subDays(self::DIAS_HISTORICO)->toDateString())
            ->orderBy('data_pesagem')->get();
        $tendencia = $this->projecao->tendencia($pesagens);

        $ocupacao = $animal->loteAtual()->with('lote.metaAtual')->first();
        $meta = $ocupacao?->lote->metaAtual;
        $alvo = $meta ? (float) $meta->peso_alvo_kg : (float) config('pecuaria.engorda.peso_abate_kg');

        return [
            'tendencia' => $tendencia,
            'pesagens' => $pesagens->map(fn (Pesagem $p) => [
                'id' => $p->id,
                'data' => $p->data_pesagem->toDateString(),
                'peso' => (float) $p->peso_kg,
                'suspeita' => in_array($p->id, $tendencia['suspeitas'], true),
            ])->values(),
            'lote' => $ocupacao ? ['id' => $ocupacao->lote_id, 'nome' => $ocupacao->lote->nome, 'desde' => $ocupacao->data_entrada->toDateString()] : null,
            'meta' => $meta ? $this->meta($meta) : null,
            'alvo' => [
                'peso_kg' => $alvo,
                'origem' => $meta ? 'META_LOTE' : 'PESO_ABATE_PADRAO',
                'previsao' => $this->projecao->previsaoParaPeso($tendencia, $alvo),
            ],
        ];
    }

    /**
     * Engorda do lote: médias, uniformidade, meta avaliada, série do peso médio e animais.
     *
     * @return array<string, mixed>
     */
    public function resumoLote(Lote $lote): array
    {
        $hoje = Carbon::today();
        $ocupacoes = $lote->ocupacoesAtuais()
            ->with('animal:id,brinco,nome,sexo,categoria,data_nascimento')
            ->get();
        $porAnimal = $this->pesagensPorAnimal($ocupacoes->pluck('animal_id'), $hoje->copy()->subDays(self::DIAS_HISTORICO));

        $animais = $ocupacoes->map(function (AnimalLote $o) use ($porAnimal) {
            $pesagens = $porAnimal->get($o->animal_id, collect());

            return [
                'animal' => $o->animal,
                'desde' => $o->data_entrada->toDateString(),
                'pesagens' => $pesagens,
                'tendencia' => $this->projecao->tendencia($pesagens),
            ];
        });

        $comPeso = $animais->filter(fn ($a) => $a['tendencia']['peso_atual'] !== null);
        $comTendencia = $animais->filter(fn ($a) => $a['tendencia']['projetavel']);
        $pesos = $comPeso->map(fn ($a) => $a['tendencia']['peso_atual']);
        $pesoMedio = $pesos->isEmpty() ? null : $pesos->avg();
        $gmdMedio = $comTendencia->isEmpty() ? null : round($comTendencia->avg(fn ($a) => $a['tendencia']['gmd']), 3);
        $desvio = $pesos->count() > 1 ? sqrt($pesos->map(fn ($p) => ($p - $pesoMedio) ** 2)->sum() / ($pesos->count() - 1)) : null;

        $meta = $lote->metaAtual;
        $avaliacao = $meta ? $this->projecao->avaliarMeta($meta, $pesoMedio, $gmdMedio) : null;
        $alvo = $meta ? (float) $meta->peso_alvo_kg : null;
        $ultimaPesagem = $animais->map(fn ($a) => $a['tendencia']['ultima_pesagem']['data'] ?? null)->filter()->max();
        $limiteSemPesagem = $hoje->copy()->subDays(config('pecuaria.engorda.dias_sem_pesagem'))->toDateString();

        return [
            'resumo' => [
                'animais' => $ocupacoes->count(),
                'com_peso' => $comPeso->count(),
                'com_tendencia' => $comTendencia->count(),
                'peso_medio' => $pesoMedio === null ? null : round($pesoMedio, 1),
                'gmd_medio' => $gmdMedio,
                'desvio_padrao' => $desvio === null ? null : round($desvio, 1),
                'abaixo_da_media' => $desvio === null ? 0 : $pesos->filter(fn ($p) => $p < $pesoMedio - $desvio)->count(),
                'ultima_pesagem' => $ultimaPesagem,
                'sem_pesagem_recente' => $animais->filter(fn ($a) => ($a['tendencia']['ultima_pesagem']['data'] ?? '0000') < $limiteSemPesagem)->count(),
                'projecoes' => $pesoMedio === null || $gmdMedio === null ? [] : collect([30, 60, 90])
                    ->map(fn ($d) => ['dias' => $d, 'data' => $hoje->copy()->addDays($d)->toDateString(), 'peso' => round($pesoMedio + $gmdMedio * $d, 1)])
                    ->values(),
            ],
            'meta' => $meta ? [...$this->meta($meta), 'avaliacao' => $avaliacao] : null,
            'serie' => $this->seriePesoMedio($animais),
            'animais' => $animais->map(fn ($a) => [
                'id' => $a['animal']->id,
                'brinco' => $a['animal']->brinco,
                'nome' => $a['animal']->nome,
                'categoria' => $a['animal']->categoria->value,
                'categoria_label' => $a['animal']->categoria->label(),
                'data_nascimento' => $a['animal']->data_nascimento?->toDateString(),
                'no_lote_desde' => $a['desde'],
                'peso_atual' => $a['tendencia']['peso_atual'],
                'ultima_pesagem' => $a['tendencia']['ultima_pesagem'],
                'gmd' => $a['tendencia']['gmd'],
                'gmd_ultimo' => $a['tendencia']['gmd_ultimo'],
                'situacao_tendencia' => $a['tendencia']['situacao'],
                'situacao_tendencia_label' => $a['tendencia']['situacao_label'],
                'suspeitas' => count($a['tendencia']['suspeitas']),
                'previsao_alvo' => $alvo === null ? null : $this->projecao->previsaoParaPeso($a['tendencia'], $alvo),
                // Animal que puxa a média para baixo: ganha abaixo do mínimo de "atenção" para a meta.
                'abaixo_do_ritmo' => $avaliacao && $avaliacao['gmd_necessario'] > 0 && $a['tendencia']['gmd'] !== null
                    && $a['tendencia']['gmd'] < $avaliacao['gmd_necessario'] * config('pecuaria.engorda.ritmo_atencao'),
            ])->sortBy(fn ($a) => $a['gmd'] ?? INF)->values(),
        ];
    }

    /**
     * Ração e suplementos que saíram do estoque para o lote: total desde a formação e nos últimos 30 dias,
     * por item, com custo pelo preço do item no dia da saída.
     *
     * @return array<string, mixed>
     */
    public function consumoLote(Lote $lote, ?int $animais = null, ?float $gmdMedio = null): array
    {
        $inicio30 = Carbon::today()->subDays(29)->toDateString();
        $saidas = MovimentoEstoque::validos()
            ->where('lote_id', $lote->id)
            ->where('tipo', TipoMovimentoEstoque::SAIDA)
            ->with('item:id,nome,tipo,unidade')
            ->orderBy('data_movimento')
            ->get();

        $custo = fn (Collection $s) => round($s->sum(fn ($m) => (float) $m->quantidade * (float) ($m->custo_unitario ?? 0)), 2);
        $ultimos30 = $saidas->filter(fn ($m) => $m->data_movimento->toDateString() >= $inicio30);
        $custo30 = $custo($ultimos30);
        $kgGanhos30 = $animais && $gmdMedio > 0 ? $animais * $gmdMedio * 30 : null;

        return [
            'itens' => $saidas->groupBy('item_estoque_id')->map(fn (Collection $s) => [
                'item_estoque_id' => $s->first()->item_estoque_id,
                'nome' => $s->first()->item->nome,
                'tipo' => $s->first()->item->tipo->value,
                'unidade_sigla' => $s->first()->item->unidade->sigla(),
                'quantidade' => round($s->sum(fn ($m) => (float) $m->quantidade), 3),
                'quantidade_30_dias' => round($s->filter(fn ($m) => $m->data_movimento->toDateString() >= $inicio30)->sum(fn ($m) => (float) $m->quantidade), 3),
                'custo' => $custo($s),
                'sem_custo' => $s->contains(fn ($m) => $m->custo_unitario === null),
            ])->sortByDesc('custo')->values(),
            'saidas' => $saidas->count(),
            'custo_total' => $custo($saidas),
            'custo_30_dias' => $custo30,
            'custo_cabeca_dia' => $animais ? round($custo30 / ($animais * 30), 2) : null,
            // Indicativo: custo de ração dividido pelo ganho estimado no período, pelo GMD atual.
            'custo_kg_ganho' => $kgGanhos30 ? round($custo30 / $kgGanhos30, 2) : null,
        ];
    }

    /**
     * Avisos para o painel: metas atrasadas, inviáveis, vencidas ou atingidas, lotes sem pesagem
     * recente e animais de corte perdendo peso.
     *
     * @return list<array{nivel: string, tipo: string, mensagem: string, lote_id?: int, animal_id?: int}>
     */
    public function alertas(): array
    {
        $alertas = [];
        $diasSemPesagem = config('pecuaria.engorda.dias_sem_pesagem');

        foreach (Lote::ativos()->whereHas('ocupacoesAtuais')->with('metaAtual')->orderBy('nome')->get() as $lote) {
            $r = $this->resumoLote($lote);
            $av = $r['meta']['avaliacao'] ?? null;
            if ($av && $av['alerta']) {
                $alertas[] = [
                    'nivel' => $av['situacao'] === 'ATINGIDA' ? 'INFO' : 'ATENCAO',
                    'tipo' => 'META_'.$av['situacao'],
                    'lote_id' => $lote->id,
                    'mensagem' => match ($av['situacao']) {
                        'ATINGIDA' => "{$lote->nome}: meta de {$this->kg($r['meta']['peso_alvo_kg'])} atingida. Avalie a venda.",
                        'VENCIDA' => "{$lote->nome}: a meta venceu em ".Carbon::parse($r['meta']['data_alvo'])->format('d/m/Y')." com peso médio de {$this->kg($av['peso_atual'])}.",
                        'INVIAVEL' => "{$lote->nome}: seriam precisos {$this->gmdTexto($av['gmd_necessario'])} para bater a meta. Reveja a data ou o peso alvo.",
                        default => "{$lote->nome}: meta atrasada. Ganho de {$this->gmdTexto($av['gmd_real'])}, precisa de {$this->gmdTexto($av['gmd_necessario'])}.",
                    },
                ];
            }
            if ($r['resumo']['sem_pesagem_recente'] > 0) {
                $alertas[] = [
                    'nivel' => 'ATENCAO', 'tipo' => 'SEM_PESAGEM', 'lote_id' => $lote->id,
                    'mensagem' => "{$lote->nome}: {$r['resumo']['sem_pesagem_recente']} animal(is) sem pesagem nos últimos {$diasSemPesagem} dias.",
                ];
            }
        }

        $corte = Animal::ativos()->whereIn('aptidao', [Aptidao::CORTE, Aptidao::MISTA])->get(['id', 'brinco', 'nome']);
        $pesagens = $this->pesagensPorAnimal($corte->pluck('id'), Carbon::today()->subDays(config('pecuaria.engorda.janela_dias')));
        foreach ($corte as $animal) {
            $t = $this->projecao->tendencia($pesagens->get($animal->id, collect()));
            if ($t['projetavel'] && $t['gmd'] <= 0) {
                $alertas[] = [
                    'nivel' => 'ATENCAO', 'tipo' => 'PERDENDO_PESO', 'animal_id' => $animal->id,
                    'mensagem' => ($animal->nome ?: $animal->brinco)." está perdendo peso ({$this->gmdTexto($t['gmd'])}).",
                ];
            }
        }

        return $alertas;
    }

    /** @return Collection<int, Collection<int, Pesagem>> */
    public function pesagensPorAnimal(Collection $animalIds, Carbon $desde): Collection
    {
        if ($animalIds->isEmpty()) {
            return collect();
        }

        return Pesagem::whereIn('animal_id', $animalIds->all())
            ->where('data_pesagem', '>=', $desde->toDateString())
            ->orderBy('data_pesagem')
            ->get(['id', 'animal_id', 'peso_kg', 'data_pesagem'])
            ->groupBy('animal_id');
    }

    /** @return array<string, mixed> */
    private function meta(MetaEngorda $meta): array
    {
        return [
            'id' => $meta->id,
            'peso_alvo_kg' => (float) $meta->peso_alvo_kg,
            'data_inicio' => $meta->data_inicio->toDateString(),
            'data_alvo' => $meta->data_alvo->toDateString(),
            'gmd_alvo' => $meta->gmd_alvo === null ? null : (float) $meta->gmd_alvo,
            'peso_inicial_kg' => $meta->peso_inicial_kg === null ? null : (float) $meta->peso_inicial_kg,
            'observacoes' => $meta->observacoes,
        ];
    }

    /**
     * Peso médio por dia de pesagem (sem as suspeitas), dos animais que estão no lote hoje.
     *
     * @return list<array{data: string, peso_medio: float, animais: int}>
     */
    private function seriePesoMedio(Collection $animais): array
    {
        return $animais
            ->flatMap(fn ($a) => $a['pesagens']->reject(fn ($p) => in_array($p->id, $a['tendencia']['suspeitas'], true)))
            ->groupBy(fn ($p) => $p->data_pesagem->toDateString())
            ->map(fn (Collection $dia, $data) => ['data' => $data, 'peso_medio' => round($dia->avg(fn ($p) => (float) $p->peso_kg), 1), 'animais' => $dia->count()])
            ->sortKeys()->values()->all();
    }

    private function kg(?float $v): string
    {
        return $v === null ? '—' : number_format($v, 0, ',', '.').' kg';
    }

    private function gmdTexto(?float $v): string
    {
        return $v === null ? '—' : number_format($v, 2, ',', '.').' kg/dia';
    }
}

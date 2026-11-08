<?php

namespace App\Services;

use App\Enums\SituacaoMetaEngorda;
use App\Enums\SituacaoTendencia;
use App\Models\MetaEngorda;
use App\Models\Pesagem;
use Illuminate\Support\Carbon;

/**
 * Contas da engorda, sem acesso ao banco: tendência de peso por regressão linear simples
 * (mínimos quadrados) das pesagens recentes, projeção de curto prazo e situação das metas.
 *
 * A reta só vale para a fase atual do animal: o crescimento do bovino é uma curva em S e
 * o ganho muda entre águas e seca. Por isso a janela é curta e a projeção tem horizonte limitado.
 * Parâmetros em config('pecuaria.engorda').
 */
class ProjecaoCrescimento
{
    /**
     * Marca pesagens suspeitas (provável erro de digitação ou de balança): a que gera, com as vizinhas,
     * um ganho fora da faixa plausível. No meio da série, precisa destoar das duas vizinhas, e as
     * vizinhas entre si precisam fazer sentido; nas pontas, compara com a vizinha válida mais próxima.
     *
     * @param  list<array{id: int|null, data: Carbon, peso: float}>  $pontos  em ordem de data
     * @return array<int, bool> índice => suspeita
     */
    public function suspeitas(array $pontos): array
    {
        $n = count($pontos);
        $suspeita = array_fill(0, $n, false);
        $fora = fn (int $a, int $b) => ! $this->ganhoPlausivel($pontos[$a], $pontos[$b]);

        for ($i = 1; $i < $n - 1; $i++) {
            $suspeita[$i] = $fora($i - 1, $i) && $fora($i, $i + 1) && ! $fora($i - 1, $i + 1);
        }

        $vizinha = fn (int $de, int $passo) => collect(range($de, $passo > 0 ? $n - 1 : 0))
            ->first(fn ($j) => ! $suspeita[$j]);
        // Com duas pesagens não dá para saber qual das duas está errada.
        if ($n >= 3) {
            $proxima = $vizinha(1, 1);
            $suspeita[0] = $proxima !== null && $fora(0, $proxima);
            $anterior = $vizinha($n - 2, -1);
            $suspeita[$n - 1] = $suspeita[$n - 1] || ($anterior !== null && $fora($anterior, $n - 1));
        }

        return $suspeita;
    }

    /**
     * Tendência de peso de um animal.
     *
     * @param  iterable<Pesagem|array{id?: int|null, data: Carbon|string, peso: float|string}>  $pesagens
     * @return array{
     *     situacao: string, situacao_label: string, projetavel: bool,
     *     gmd: float|null, gmd_ultimo: float|null, r2: float|null, pesagens_usadas: int,
     *     inicio_janela: string|null, ultima_pesagem: array{data: string, peso: float}|null,
     *     peso_estimado_hoje: float|null, peso_atual: float|null,
     *     projecoes: list<array{dias: int, data: string, peso: float, margem: float|null}>,
     *     reta: array{inicio: array{data: string, peso: float}, fim: array{data: string, peso: float}}|null,
     *     suspeitas: list<int>
     * }
     */
    public function tendencia(iterable $pesagens, ?Carbon $hoje = null): array
    {
        $c = config('pecuaria.engorda');
        $hoje = ($hoje ?? Carbon::today())->copy()->startOfDay();
        $pontos = $this->normalizar($pesagens);
        $marcas = $this->suspeitas($pontos);

        $validos = array_values(array_filter($pontos, fn ($p, $i) => ! $marcas[$i], ARRAY_FILTER_USE_BOTH));
        $limite = $hoje->copy()->subDays($c['janela_dias']);
        $janela = array_values(array_filter($validos, fn ($p) => $p['data']->gte($limite) && $p['data']->lte($hoje)));
        $ultima = end($validos) ?: null;
        $penultima = count($validos) >= 2 ? $validos[count($validos) - 2] : null;

        $base = [
            'situacao' => SituacaoTendencia::INSUFICIENTE->value,
            'situacao_label' => SituacaoTendencia::INSUFICIENTE->label(),
            'projetavel' => false,
            'gmd' => null,
            'gmd_ultimo' => $ultima && $penultima ? Pesagem::calcularGmd($ultima['peso'], $ultima['data'], $penultima['peso'], $penultima['data']) : null,
            'r2' => null,
            'pesagens_usadas' => count($janela),
            'inicio_janela' => $janela ? $janela[0]['data']->toDateString() : null,
            'ultima_pesagem' => $ultima ? ['data' => $ultima['data']->toDateString(), 'peso' => $ultima['peso']] : null,
            'peso_estimado_hoje' => null,
            // Sem tendência, o peso de referência é a última pesagem, se for recente.
            'peso_atual' => $ultima && $ultima['data']->gte($limite) ? $ultima['peso'] : null,
            'projecoes' => [],
            'reta' => null,
            'suspeitas' => array_values(array_filter(array_map(fn ($p, $i) => $marcas[$i] ? $p['id'] : null, $pontos, array_keys($pontos)))),
        ];

        $span = $janela ? (int) $janela[0]['data']->diffInDays(end($janela)['data']) : 0;
        if (count($janela) < $c['minimo_pesagens'] || $span < $c['minimo_intervalo_dias']) {
            return $base;
        }

        $reg = $this->regressao($janela);
        $situacao = match (true) {
            $reg['r2'] >= $c['r2_confiavel'] => SituacaoTendencia::CONFIAVEL,
            $reg['r2'] >= $c['r2_minimo'] => SituacaoTendencia::INSTAVEL,
            default => SituacaoTendencia::IRREGULAR,
        };
        $x0 = $janela[0]['data'];
        $pesoEm = fn (Carbon $d) => $reg['a'] + $reg['b'] * $x0->diffInDays($d, false);

        $resultado = [
            ...$base,
            'situacao' => $situacao->value,
            'situacao_label' => $situacao->label(),
            'projetavel' => $situacao->projetavel(),
            'gmd' => round($reg['b'], 3),
            'r2' => round($reg['r2'], 3),
        ];
        if (! $situacao->projetavel()) {
            return $resultado;
        }

        $fimJanela = end($janela)['data'];
        $hojeEstimado = $pesoEm($hoje);
        $projecoes = [];
        foreach ([30, 60, 90] as $dias) {
            if ($dias > $c['horizonte_dias']) {
                continue;
            }
            $data = $hoje->copy()->addDays($dias);
            $projecoes[] = [
                'dias' => $dias,
                'data' => $data->toDateString(),
                'peso' => round($pesoEm($data), 1),
                'margem' => $this->margem($reg, $x0->diffInDays($data, false)),
            ];
        }

        return [
            ...$resultado,
            'peso_estimado_hoje' => round($hojeEstimado, 1),
            'peso_atual' => round($hojeEstimado, 1),
            'projecoes' => $projecoes,
            'reta' => [
                'inicio' => ['data' => $x0->toDateString(), 'peso' => round($reg['a'], 1)],
                'fim' => ['data' => $fimJanela->toDateString(), 'peso' => round($pesoEm($fimJanela), 1)],
            ],
        ];
    }

    /**
     * Quando o peso alvo é atingido no ritmo atual. Null se não há tendência, se o animal
     * não ganha peso ou se levaria mais de um ano (a reta não vale tão longe).
     *
     * @param  array{projetavel: bool, gmd: float|null, peso_atual: float|null}  $tendencia
     * @return array{dias: int, data: string}|null
     */
    public function previsaoParaPeso(array $tendencia, float $pesoAlvo, ?Carbon $hoje = null): ?array
    {
        $hoje = ($hoje ?? Carbon::today())->copy()->startOfDay();
        if (! $tendencia['projetavel'] || $tendencia['peso_atual'] === null) {
            return null;
        }
        if ($tendencia['peso_atual'] >= $pesoAlvo) {
            return ['dias' => 0, 'data' => $hoje->toDateString()];
        }
        if ($tendencia['gmd'] <= 0) {
            return null;
        }
        $dias = (int) ceil(($pesoAlvo - $tendencia['peso_atual']) / $tendencia['gmd']);

        return $dias > 365 ? null : ['dias' => $dias, 'data' => $hoje->copy()->addDays($dias)->toDateString()];
    }

    /**
     * Situação da meta comparando o GMD real com o GMD necessário para chegar ao peso alvo na data.
     *
     * @return array{
     *     situacao: string, situacao_label: string, alerta: bool,
     *     peso_atual: float|null, dias_restantes: int, gmd_necessario: float|null, gmd_real: float|null,
     *     ritmo: float|null, data_projetada: string|null, diferenca_dias: int|null, progresso: float|null
     * }
     */
    public function avaliarMeta(MetaEngorda $meta, ?float $pesoAtual, ?float $gmdReal, ?Carbon $hoje = null): array
    {
        $c = config('pecuaria.engorda');
        $hoje = ($hoje ?? Carbon::today())->copy()->startOfDay();
        $alvo = (float) $meta->peso_alvo_kg;
        $diasRestantes = (int) $hoje->diffInDays($meta->data_alvo, false);
        $gmdNecessario = $pesoAtual !== null && $diasRestantes > 0 ? round(($alvo - $pesoAtual) / $diasRestantes, 3) : null;
        $ritmo = $gmdNecessario > 0 && $gmdReal !== null ? round($gmdReal / $gmdNecessario, 3) : null;

        $situacao = match (true) {
            $pesoAtual === null => SituacaoMetaEngorda::SEM_DADOS,
            $pesoAtual >= $alvo => SituacaoMetaEngorda::ATINGIDA,
            $diasRestantes <= 0 => SituacaoMetaEngorda::VENCIDA,
            $gmdNecessario > $c['gmd_maximo_plausivel'] => SituacaoMetaEngorda::INVIAVEL,
            $ritmo === null => SituacaoMetaEngorda::SEM_DADOS,
            $ritmo >= $c['ritmo_ok'] => SituacaoMetaEngorda::NO_RITMO,
            $ritmo >= $c['ritmo_atencao'] => SituacaoMetaEngorda::ATENCAO,
            default => SituacaoMetaEngorda::ATRASADA,
        };

        $dataProjetada = null;
        $diferenca = null;
        if ($situacao !== SituacaoMetaEngorda::ATINGIDA && $pesoAtual !== null && $gmdReal > 0) {
            $dias = (int) ceil(($alvo - $pesoAtual) / $gmdReal);
            if ($dias <= 365) {
                $projetada = $hoje->copy()->addDays($dias);
                $dataProjetada = $projetada->toDateString();
                $diferenca = (int) $meta->data_alvo->diffInDays($projetada, false);
            }
        }

        $inicial = $meta->peso_inicial_kg === null ? null : (float) $meta->peso_inicial_kg;
        $progresso = $pesoAtual !== null && $inicial !== null && $alvo > $inicial
            ? round(max(0, min(1, ($pesoAtual - $inicial) / ($alvo - $inicial))), 3)
            : null;

        return [
            'situacao' => $situacao->value,
            'situacao_label' => $situacao->label(),
            'alerta' => $situacao->alerta(),
            'peso_atual' => $pesoAtual === null ? null : round($pesoAtual, 1),
            'dias_restantes' => $diasRestantes,
            'gmd_necessario' => $gmdNecessario,
            'gmd_real' => $gmdReal,
            'ritmo' => $ritmo,
            'data_projetada' => $dataProjetada,
            'diferenca_dias' => $diferenca,
            'progresso' => $progresso,
        ];
    }

    /**
     * @param  iterable<Pesagem|array{id?: int|null, data: Carbon|string, peso: float|string}>  $pesagens
     * @return list<array{id: int|null, data: Carbon, peso: float}>
     */
    private function normalizar(iterable $pesagens): array
    {
        $pontos = [];
        foreach ($pesagens as $p) {
            $pontos[] = $p instanceof Pesagem
                ? ['id' => $p->id, 'data' => $p->data_pesagem->copy()->startOfDay(), 'peso' => (float) $p->peso_kg]
                : ['id' => $p['id'] ?? null, 'data' => Carbon::parse($p['data'])->startOfDay(), 'peso' => (float) $p['peso']];
        }
        usort($pontos, fn ($a, $b) => $a['data']->timestamp <=> $b['data']->timestamp);

        return $pontos;
    }

    /** @param array{data: Carbon, peso: float} $de */
    private function ganhoPlausivel(array $de, array $ate): bool
    {
        $gmd = Pesagem::calcularGmd($ate['peso'], $ate['data'], $de['peso'], $de['data']);

        return $gmd === null
            || ($gmd >= config('pecuaria.engorda.gmd_suspeito_min') && $gmd <= config('pecuaria.engorda.gmd_suspeito_max'));
    }

    /**
     * Mínimos quadrados de peso × dias desde a primeira pesagem da janela.
     *
     * @param  list<array{data: Carbon, peso: float}>  $pontos
     * @return array{a: float, b: float, r2: float, n: int, media_x: float, sxx: float, erro: float|null}
     */
    private function regressao(array $pontos): array
    {
        $x0 = $pontos[0]['data'];
        $xs = array_map(fn ($p) => (float) $x0->diffInDays($p['data']), $pontos);
        $ys = array_column($pontos, 'peso');
        $n = count($pontos);
        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;

        $sxx = $sxy = $syy = 0.0;
        foreach ($xs as $i => $x) {
            $sxx += ($x - $mx) ** 2;
            $sxy += ($x - $mx) * ($ys[$i] - $my);
            $syy += ($ys[$i] - $my) ** 2;
        }
        $b = $sxy / $sxx;
        $a = $my - $b * $mx;
        $ssRes = 0.0;
        foreach ($xs as $i => $x) {
            $ssRes += ($ys[$i] - ($a + $b * $x)) ** 2;
        }
        // Peso constante: a reta passa por todos os pontos (GMD zero, sem dispersão).
        $r2 = $syy > 0 ? max(0.0, 1 - $ssRes / $syy) : 1.0;

        return ['a' => $a, 'b' => $b, 'r2' => $r2, 'n' => $n, 'media_x' => $mx, 'sxx' => $sxx, 'erro' => $n > 2 ? sqrt($ssRes / ($n - 2)) : null];
    }

    /** Meia-largura (kg) do intervalo de previsão de ~95% num ponto x (dias desde o início da janela). */
    private function margem(array $reg, float $x): ?float
    {
        if ($reg['erro'] === null) {
            return null;
        }

        return round(1.96 * $reg['erro'] * sqrt(1 + 1 / $reg['n'] + (($x - $reg['media_x']) ** 2) / $reg['sxx']), 1);
    }
}

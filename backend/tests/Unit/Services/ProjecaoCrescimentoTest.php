<?php

namespace Tests\Unit\Services;

use App\Models\MetaEngorda;
use App\Services\ProjecaoCrescimento;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Contas da engorda sem banco: usa o app só para a config e o relógio. */
class ProjecaoCrescimentoTest extends TestCase
{
    private ProjecaoCrescimento $projecao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-01');
        $this->projecao = new ProjecaoCrescimento;
    }

    /** Pesagens N dias atrás: [diasAtras => peso]. */
    private function pesagens(array $pesos): array
    {
        return collect(array_keys($pesos))->map(fn ($diasAtras, $i) => [
            'id' => $i + 1, 'data' => now()->subDays($diasAtras)->toDateString(), 'peso' => $pesos[$diasAtras],
        ])->all();
    }

    public function test_regressao_linear_calcula_gmd_r2_peso_de_hoje_e_projecoes(): void
    {
        $t = $this->projecao->tendencia($this->pesagens([60 => 380, 30 => 402, 0 => 425]));

        $this->assertSame('CONFIAVEL', $t['situacao']);
        $this->assertSame(0.75, $t['gmd']);
        $this->assertSame(1.0, $t['r2']);
        $this->assertSame(424.8, $t['peso_estimado_hoje']);
        $this->assertSame([30, 60, 90], array_column($t['projecoes'], 'dias'));
        $this->assertSame(447.3, $t['projecoes'][0]['peso']);
        $this->assertSame('2026-08-30', $t['projecoes'][2]['data']);
        $this->assertSame(['dias' => 74, 'data' => '2026-08-14'], $this->projecao->previsaoParaPeso($t, 480));
    }

    public function test_menos_de_tres_pesagens_ou_intervalo_curto_nao_projeta(): void
    {
        $duas = $this->projecao->tendencia($this->pesagens([30 => 400, 0 => 424]));
        $curto = $this->projecao->tendencia($this->pesagens([20 => 400, 10 => 410, 0 => 419]));

        $this->assertSame('INSUFICIENTE', $duas['situacao']);
        $this->assertSame(0.8, $duas['gmd_ultimo']);
        $this->assertSame(424.0, $duas['peso_atual']);
        $this->assertSame([], $duas['projecoes']);
        $this->assertSame('INSUFICIENTE', $curto['situacao']);
    }

    public function test_pesagens_fora_da_janela_nao_entram_na_tendencia(): void
    {
        $t = $this->projecao->tendencia($this->pesagens([300 => 200, 150 => 330, 60 => 380, 30 => 402, 0 => 425]));

        $this->assertSame(3, $t['pesagens_usadas']);
        $this->assertSame(0.75, $t['gmd']);
    }

    public function test_pesagem_digitada_errada_no_meio_e_marcada_e_ignorada(): void
    {
        // 4020 em vez de 402: destoa das duas vizinhas, que entre si fazem sentido.
        $t = $this->projecao->tendencia($this->pesagens([90 => 358, 60 => 380, 30 => 4020, 0 => 425]));

        $this->assertSame([3], $t['suspeitas']);
        $this->assertSame(3, $t['pesagens_usadas']);
        $this->assertSame('CONFIAVEL', $t['situacao']);
    }

    public function test_ultima_e_primeira_pesagens_erradas_sao_marcadas(): void
    {
        $ultima = $this->projecao->tendencia($this->pesagens([60 => 380, 30 => 402, 0 => 245]));
        $primeira = $this->projecao->tendencia($this->pesagens([90 => 3580, 60 => 380, 30 => 402, 0 => 425]));

        $this->assertSame([3], $ultima['suspeitas']);
        $this->assertSame(402.0, $ultima['ultima_pesagem']['peso']);
        $this->assertSame([1], $primeira['suspeitas']);
    }

    public function test_ganho_irregular_nao_projeta(): void
    {
        $t = $this->projecao->tendencia($this->pesagens([90 => 400, 60 => 440, 30 => 405, 0 => 445]));

        $this->assertSame('IRREGULAR', $t['situacao']);
        $this->assertFalse($t['projetavel']);
        $this->assertNull($this->projecao->previsaoParaPeso($t, 480));
    }

    public function test_animal_perdendo_peso_nao_tem_previsao_para_o_alvo(): void
    {
        $t = $this->projecao->tendencia($this->pesagens([60 => 430, 30 => 421, 0 => 412]));

        $this->assertSame(-0.3, $t['gmd']);
        $this->assertNull($this->projecao->previsaoParaPeso($t, 480));
    }

    public static function situacoesDaMeta(): array
    {
        // peso atual 420, alvo 480: em 80 dias precisa de 0,75 kg/dia.
        return [
            'ganho igual ao necessário' => [420.0, 0.75, 80, 'NO_RITMO'],
            'ganho de 88% do necessário' => [420.0, 0.66, 80, 'ATENCAO'],
            'ganho de 67% do necessário' => [420.0, 0.5, 80, 'ATRASADA'],
            'precisaria de 2 kg/dia' => [420.0, 0.75, 30, 'INVIAVEL'],
            'já passou do alvo' => [481.0, 0.75, 80, 'ATINGIDA'],
            'data alvo passou' => [420.0, 0.75, -1, 'VENCIDA'],
            'sem pesagens' => [null, null, 80, 'SEM_DADOS'],
        ];
    }

    #[DataProvider('situacoesDaMeta')]
    public function test_situacao_da_meta(?float $peso, ?float $gmd, int $diasAteAlvo, string $esperada): void
    {
        $meta = new MetaEngorda(['peso_alvo_kg' => 480, 'data_alvo' => now()->addDays($diasAteAlvo)->toDateString(), 'peso_inicial_kg' => 360]);

        $this->assertSame($esperada, $this->projecao->avaliarMeta($meta, $peso, $gmd)['situacao']);
    }

    public function test_meta_mostra_data_projetada_atraso_e_progresso(): void
    {
        $meta = new MetaEngorda(['peso_alvo_kg' => 480, 'data_alvo' => now()->addDays(80)->toDateString(), 'peso_inicial_kg' => 360]);

        $av = $this->projecao->avaliarMeta($meta, 420.0, 0.5);

        $this->assertSame(0.75, $av['gmd_necessario']);
        $this->assertSame('2026-09-29', $av['data_projetada']);
        $this->assertSame(40, $av['diferenca_dias']);
        $this->assertSame(0.5, $av['progresso']);
    }
}

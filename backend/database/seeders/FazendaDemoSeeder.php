<?php

namespace Database\Seeders;

use App\Enums\Aptidao;
use App\Enums\Categoria;
use App\Enums\Sexo;
use App\Enums\StatusPiquete;
use App\Enums\StatusReprodutivo;
use App\Models\Animal;
use App\Models\CategoriaSanitaria;
use App\Models\Pesagem;
use App\Models\Piquete;
use App\Models\ProducaoLeite;
use App\Models\ProdutoSanitario;
use App\Models\VacinaSanidade;
use App\Services\ServicoReprodutivo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/** Dados de demonstração: rebanho misto, piquetes, pesagens, ordenhas e eventos. */
class FazendaDemoSeeder extends Seeder
{
    public function run(ServicoReprodutivo $reprodutivo): void
    {
        $hoje = Carbon::today();

        $piquetes = collect(['P-01' => 12.5, 'P-02' => 8, 'P-03' => 15.75, 'P-04' => 6.2])
            ->map(fn ($ha, $id) => Piquete::create(['identificacao' => $id, 'hectares' => $ha]));
        $piquetes['P-04']->update(['status' => StatusPiquete::DEGRADADO]);

        // Vacas leiteiras em lactação com ordenhas dos últimos 100 dias (curva de lactação suave).
        $vacas = Animal::factory()->count(8)->sequence(fn ($s) => ['brinco' => sprintf('L-%03d', $s->index + 1)])->create();
        $linhas = [];
        foreach ($vacas as $i => $vaca) {
            $base = 16 + $i * 1.6;                  // potencial diário de cada vaca (L)
            foreach (range(0, 99) as $d) {
                $curva = 1 - 0.0025 * (99 - $d) * 0.5 + 0.0025 * min(99 - $d, 40);  // sobe e depois declina
                $fator = $vaca->brinco === 'L-004' && $d <= 4 ? 0.55 : 1;           // L-004: mastite recente
                $dia = $base * $curva * $fator;
                foreach (['MANHA' => 0.56, 'TARDE' => 0.44] as $periodo => $parte) {
                    $linhas[] = [
                        'fazenda_id' => $vaca->fazenda_id, 'animal_id' => $vaca->id, 'data_pesagem' => $hoje->copy()->subDays($d)->toDateString(),
                        'periodo' => $periodo, 'quantidade_litros' => round($dia * $parte * fake()->randomFloat(3, 0.92, 1.08), 2),
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }
            }
        }
        foreach (array_chunk($linhas, 500) as $lote) {
            ProducaoLeite::insert($lote);
        }

        // Ciclo reprodutivo completo em uma vaca; outra inseminada; outra gestante.
        $reprodutivo->registrar(['animal_id' => $vacas[0]->id, 'tipo_evento' => 'INSEMINACAO', 'data_evento' => $hoje->copy()->subDays(320)->toDateString()]);
        $reprodutivo->registrar(['animal_id' => $vacas[0]->id, 'tipo_evento' => 'TOQUE_POSITIVO', 'data_evento' => $hoje->copy()->subDays(285)->toDateString()]);
        $reprodutivo->registrar([
            'animal_id' => $vacas[0]->id, 'tipo_evento' => 'PARTO', 'data_evento' => $hoje->copy()->subDays(37)->toDateString(),
            'brinco_bezerro' => 'L-B01', 'sexo_bezerro' => 'F', 'observacoes' => 'Parto normal',
        ]);
        $reprodutivo->registrar(['animal_id' => $vacas[1]->id, 'tipo_evento' => 'INSEMINACAO', 'data_evento' => $hoje->copy()->subDays(20)->toDateString()]);
        $reprodutivo->registrar(['animal_id' => $vacas[2]->id, 'tipo_evento' => 'COBERTURA_NATURAL', 'data_evento' => $hoje->copy()->subDays(150)->toDateString()]);
        $reprodutivo->registrar(['animal_id' => $vacas[2]->id, 'tipo_evento' => 'TOQUE_POSITIVO', 'data_evento' => $hoje->copy()->subDays(110)->toDateString()]);

        // Vacina com carência vigente (aparece com alerta crítico na ordenha).
        VacinaSanidade::create(['animal_id' => $vacas[3]->id, 'tipo' => 'MEDICAMENTO', 'categoria_sanitaria_id' => CategoriaSanitaria::porChave('mastite')->id, 'nome_vacina' => 'Antibiótico (mastite)', 'data_aplicacao' => $hoje->copy()->subDays(4), 'dias_carencia_leite_carne' => 7]);
        VacinaSanidade::create(['animal_id' => $vacas[4]->id, 'tipo' => 'VACINA', 'categoria_sanitaria_id' => CategoriaSanitaria::porChave('aftosa')->id, 'nome_vacina' => 'Aftosa', 'data_aplicacao' => $hoje->copy()->subDays(60), 'dias_carencia_leite_carne' => 0]);

        // Gado de corte com pesagens mensais (GMD).
        $bois = Animal::factory()->boiGordo()->count(6)->sequence(fn ($s) => ['brinco' => sprintf('C-%03d', $s->index + 1)])->create();
        $garrotes = Animal::factory()->count(5)->sequence(fn ($s) => ['brinco' => sprintf('G-%03d', $s->index + 1)])->create([
            'sexo' => Sexo::M, 'aptidao' => Aptidao::CORTE, 'categoria' => Categoria::GARROTE,
            'status_reprodutivo' => StatusReprodutivo::INAPTA, 'nome' => null,
            'data_nascimento' => $hoje->copy()->subMonths(14),
        ]);
        foreach ($bois->concat($garrotes) as $animal) {
            $peso = $animal->categoria === Categoria::BOI_GORDO ? fake()->numberBetween(430, 480) : fake()->numberBetween(260, 300);
            foreach ([150, 120, 90, 60, 30, 0] as $diasAtras) {
                Pesagem::create(['animal_id' => $animal->id, 'data_pesagem' => $hoje->copy()->subDays($diasAtras), 'peso_kg' => $peso]);
                $peso += fake()->randomFloat(2, 18, 33); // ~0,6 a 1,1 kg/dia
            }
        }
        $ivermectina = ProdutoSanitario::create(['categoria_sanitaria_id' => CategoriaSanitaria::porChave('antiparasitario')->id, 'nome' => 'Ivermectina 1%', 'dias_carencia' => 35]);
        VacinaSanidade::create(['animal_id' => $bois[0]->id, 'tipo' => 'MEDICAMENTO', 'categoria_sanitaria_id' => $ivermectina->categoria_sanitaria_id, 'produto_sanitario_id' => $ivermectina->id, 'nome_vacina' => $ivermectina->nome, 'data_aplicacao' => $hoje->copy()->subDays(10), 'dias_carencia_leite_carne' => 35]);

        // Bezerros: um pronto para desmame (rotina diária), outro novo.
        Animal::factory()->bezerro(Sexo::M, 215)->create(['brinco' => 'B-001', 'aptidao' => Aptidao::CORTE, 'nome' => null]);
        Animal::factory()->bezerro(Sexo::F, 90)->create(['brinco' => 'B-002', 'mae_id' => $vacas[5]->id, 'nome' => null]);

        // Ocupação dos piquetes.
        $this->alocar($piquetes['P-01'], $vacas->pluck('id'), $hoje->copy()->subDays(3));
        $this->alocar($piquetes['P-02'], $bois->concat($garrotes)->pluck('id'), $hoje->copy()->subDays(12));
    }

    private function alocar(Piquete $piquete, $ids, Carbon $desde): void
    {
        foreach ($ids as $id) {
            $piquete->historico()->create(['animal_id' => $id, 'data_entrada' => $desde]);
        }
        $piquete->update(['status' => StatusPiquete::OCUPADO]);
    }
}

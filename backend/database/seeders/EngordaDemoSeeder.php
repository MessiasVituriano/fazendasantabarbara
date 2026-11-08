<?php

namespace Database\Seeders;

use App\Models\Animal;
use App\Models\ItemEstoque;
use App\Models\Lote;
use App\Models\MetaEngorda;
use App\Models\Pesagem;
use App\Models\User;
use App\Services\PainelEngorda;
use App\Services\ServicoEstoque;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Engorda de demonstração (somente desenvolvimento): garrotes no Lote 01, bois num lote de terminação,
 * metas em situações diferentes, ração e sal saindo do estoque para os lotes e uma pesagem digitada errada.
 * Roda depois do FazendaDemoSeeder, do FinanceiroDemoSeeder e do EstoqueDemoSeeder.
 */
class EngordaDemoSeeder extends Seeder
{
    public function run(PainelEngorda $painel, ServicoEstoque $estoque): void
    {
        $hoje = Carbon::today();
        $caseiro = User::firstWhere('usuario', 'caseiro');
        $gestor = User::firstWhere('usuario', 'gestor');

        $garrotes = Lote::where('nome', 'like', 'Lote 01%')->firstOrFail();
        $terminacao = Lote::create([
            'nome' => 'Lote 03 · Terminação', 'data_formacao' => $hoje->copy()->subDays(160),
            'categoria' => 'Bois em terminação', 'origem' => 'Recria própria',
        ]);
        $this->colocar($garrotes, Animal::where('brinco', 'like', 'G-%')->pluck('id'), $hoje->copy()->subDays(155));
        $this->colocar($terminacao, Animal::where('brinco', 'like', 'C-%')->pluck('id'), $hoje->copy()->subDays(155));
        // Bezerro ainda sem pesagem: gera o aviso de lote sem pesagem recente.
        $this->colocar(Lote::where('nome', 'like', 'Lote 02%')->firstOrFail(), Animal::where('brinco', 'B-001')->pluck('id'), $hoje->copy()->subDays(20));

        // Digitado 5120 em vez de 512: aparece como suspeita e fica fora da tendência.
        $boi = Animal::firstWhere('brinco', 'C-002');
        $anterior = $boi->pesagens()->where('data_pesagem', '<=', $hoje->copy()->subDays(60)->toDateString())->orderByDesc('data_pesagem')->value('peso_kg');
        Pesagem::create(['animal_id' => $boi->id, 'data_pesagem' => $hoje->copy()->subDays(45), 'peso_kg' => round((float) $anterior + 12, 0) * 10]);

        // Metas a partir do ritmo real, para mostrar situações diferentes: terminação no ritmo, garrotes em atenção.
        foreach ([[$terminacao, 75, 0.92], [$garrotes, 90, 1.2]] as [$lote, $dias, $exigencia]) {
            $r = $painel->resumoLote($lote->setRelation('metaAtual', null))['resumo'];
            MetaEngorda::create([
                'lote_id' => $lote->id, 'data_inicio' => $hoje->copy()->subDays(30), 'data_alvo' => $hoje->copy()->addDays($dias),
                'peso_inicial_kg' => round($r['peso_medio'] - $r['gmd_medio'] * 30),
                'peso_alvo_kg' => round(($r['peso_medio'] + $r['gmd_medio'] * $exigencia * $dias) / 5) * 5,
            ]);
        }

        // Ração de terminação e sal mineral saindo para os lotes.
        $racao = ItemEstoque::create(['nome' => 'Ração terminação 16%', 'tipo' => 'RACAO', 'unidade' => 'KG', 'estoque_minimo' => 300, 'localizacao' => 'Silo 2']);
        $estoque->registrar(['item_estoque_id' => $racao->id, 'tipo' => 'ENTRADA', 'data_movimento' => $hoje->copy()->subDays(60)->toDateString(),
            'quantidade' => 2500, 'valor_unitario' => 1.85, 'observacao' => 'Nutrição Animal Cerrado'], $gestor);
        foreach (range(8, 0) as $semana) {
            $estoque->registrar(['item_estoque_id' => $racao->id, 'tipo' => 'SAIDA', 'lote_id' => $terminacao->id,
                'data_movimento' => $hoje->copy()->subDays($semana * 7)->toDateString(), 'quantidade' => 6 * 3.2 * 7, 'observacao' => 'Trato semanal'], $caseiro);
        }
        $sal = ItemEstoque::firstWhere('nome', 'Sal mineral 80P');
        foreach ([24, 10] as $dias) {
            $estoque->registrar(['item_estoque_id' => $sal->id, 'tipo' => 'SAIDA', 'lote_id' => $garrotes->id,
                'data_movimento' => $hoje->copy()->subDays($dias)->toDateString(), 'quantidade' => 1, 'observacao' => 'Cocho do pasto'], $caseiro);
        }
    }

    private function colocar(Lote $lote, $ids, Carbon $desde): void
    {
        foreach ($ids as $id) {
            $lote->historicoAnimais()->create(['animal_id' => $id, 'data_entrada' => $desde]);
        }
    }
}

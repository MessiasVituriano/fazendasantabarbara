<?php

namespace Database\Seeders;

use App\Models\ItemEstoque;
use App\Models\User;
use App\Services\ServicoEstoque;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/** Estoque de demonstração (somente desenvolvimento): itens com entradas, usos e alertas. */
class EstoqueDemoSeeder extends Seeder
{
    public function run(ServicoEstoque $estoque): void
    {
        $hoje = Carbon::today();
        $gestor = User::firstWhere('usuario', 'gestor');
        $caseiro = User::firstWhere('usuario', 'caseiro');

        $itens = [
            // nome, tipo, unidade, mínimo, validade, local, entrada, preço, saídas
            ['Ração lactação 22%', 'RACAO', 'SACO', 20, $hoje->copy()->addMonths(3), 'Galpão de ração', 60, 98.5, [12, 15, 10]],
            ['Sal mineral 80P', 'SUPLEMENTO', 'SACO', 10, null, 'Galpão de ração', 15, 145, [3, 4]],
            ['Ivermectina 1% 500 mL', 'MEDICAMENTO', 'FRASCO', 2, $hoje->copy()->subDays(5), 'Farmácia', 4, 89.9, [1]],
            ['Oxitetraciclina LA 50 mL', 'MEDICAMENTO', 'FRASCO', 3, $hoje->copy()->addDays(20), 'Farmácia', 6, 42, [2, 1]],
            ['Vacina aftosa (50 doses)', 'VACINA', 'DOSE', 50, $hoje->copy()->addMonths(8), 'Geladeira', 200, 2.1, [80]],
            ['Seringa descartável 10 mL', 'OUTRO', 'UN', 0, null, 'Farmácia', 100, 0.85, [20]],
        ];

        foreach ($itens as [$nome, $tipo, $unidade, $minimo, $validade, $local, $entrada, $preco, $saidas]) {
            $item = ItemEstoque::create([
                'nome' => $nome, 'tipo' => $tipo, 'unidade' => $unidade, 'estoque_minimo' => $minimo,
                'data_validade' => $validade?->toDateString(), 'localizacao' => $local,
            ]);
            $estoque->registrar([
                'item_estoque_id' => $item->id, 'tipo' => 'ENTRADA', 'data_movimento' => $hoje->copy()->subDays(30)->toDateString(),
                'quantidade' => $entrada, 'valor_unitario' => $preco, 'observacao' => 'Compra do mês',
            ], $gestor);
            foreach ($saidas as $i => $quantidade) {
                $estoque->registrar([
                    'item_estoque_id' => $item->id, 'tipo' => 'SAIDA', 'data_movimento' => $hoje->copy()->subDays(20 - $i * 7)->toDateString(),
                    'quantidade' => $quantidade, 'observacao' => 'Uso no rebanho',
                ], $caseiro);
            }
        }
    }
}

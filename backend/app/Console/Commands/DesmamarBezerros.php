<?php

namespace App\Console\Commands;

use App\Enums\Categoria;
use App\Enums\Sexo;
use App\Models\Animal;
use App\Models\Fazenda;
use App\Services\ContextoFazenda;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * RF03 — desmame automatizado: bezerros ativos com idade >= PECUARIA_DIAS_DESMAME_BEZERRO
 * passam a NOVILHA (fêmea) ou GARROTE (macho). Roda fazenda por fazenda.
 */
#[Signature('pecuaria:desmame {--data= : Data de referência (AAAA-MM-DD); padrão: hoje}')]
#[Description('Migra bezerros que atingiram a idade de desmame para Novilha/Garrote')]
class DesmamarBezerros extends Command
{
    public function handle(ContextoFazenda $contexto): int
    {
        $referencia = $this->option('data') ? Carbon::parse($this->option('data')) : Carbon::today();
        $nascidosAte = $referencia->copy()->subDays(config('pecuaria.dias_desmame_bezerro'))->toDateString();

        $total = 0;
        foreach (Fazenda::acessiveis()->orderBy('id')->get() as $fazenda) {
            $contexto->executarEm($fazenda, function () use ($fazenda, $nascidosAte, &$total) {
                foreach ([Sexo::F->value => Categoria::NOVILHA, Sexo::M->value => Categoria::GARROTE] as $sexo => $categoria) {
                    $atualizados = Animal::ativos()
                        ->where('categoria', Categoria::BEZERRO)
                        ->where('sexo', $sexo)
                        ->where('data_nascimento', '<=', $nascidosAte)
                        ->update(['categoria' => $categoria, 'updated_at' => now()]);

                    $this->line("{$fazenda->nome} · {$categoria->label()}: {$atualizados} animal(is)");
                    $total += $atualizados;
                }
            });
        }

        $this->info("Desmame concluído: {$total} bezerro(s) migrado(s) (nascidos até {$nascidosAte}).");

        return self::SUCCESS;
    }
}

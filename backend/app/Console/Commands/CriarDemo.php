<?php

namespace App\Console\Commands;

use App\Enums\Perfil;
use App\Models\Cliente;
use App\Models\Fazenda;
use App\Models\FazendaUsuario;
use App\Models\User;
use App\Services\ContextoFazenda;
use App\Services\ServicoExclusaoCliente;
use App\Services\ServicoFazenda;
use Database\Seeders\EngordaDemoSeeder;
use Database\Seeders\EstoqueDemoSeeder;
use Database\Seeders\FazendaDemoSeeder;
use Database\Seeders\FinanceiroDemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Fazenda de demonstração, também em produção: um cliente próprio (marcado como demonstração) com rebanho, ordenha,
 * engorda, financeiro e estoque de exemplo, isolado das fazendas reais. Os usuários informados entram nela como gestor
 * (a troca de fazenda aparece no menu); nenhum usuário com senha padrão é criado. --recriar apaga e gera de novo, com as
 * datas de hoje, mantendo quem tinha acesso.
 */
#[Signature('pecuaria:criar-demo {--usuario=* : Usuário que recebe acesso de gestor (pode repetir)} {--recriar : Apaga a demonstração atual e gera de novo}')]
#[Description('Cria (ou recria) a fazenda de demonstração e dá acesso aos usuários informados')]
class CriarDemo extends Command
{
    public const NOME_FAZENDA = 'Fazenda Demonstração';

    public function handle(ServicoFazenda $fazendas, ServicoExclusaoCliente $exclusao, ContextoFazenda $contexto): int
    {
        $usuarios = collect($this->option('usuario'))->map(fn (string $u) => [$u, User::firstWhere('usuario', mb_strtolower(trim($u)))]);
        if ($faltando = $usuarios->filter(fn ($par) => $par[1] === null)->map(fn ($par) => $par[0])->implode(', ')) {
            $this->error("Usuário não encontrado: {$faltando}.");

            return self::FAILURE;
        }
        $usuarios = $usuarios->map(fn ($par) => $par[1]);

        $cliente = Cliente::where('demonstracao', true)->first();
        $acessosAnteriores = collect();
        if ($cliente && $this->option('recriar')) {
            $acessosAnteriores = FazendaUsuario::whereIn('fazenda_id', $cliente->fazendas()->select('id'))->get(['user_id', 'perfil', 'ativo']);
            $exclusao->excluir($cliente, apagarUsuarios: false);
            $cliente = null;
            $this->line('Demonstração anterior apagada.');
        }

        $fazenda = $cliente?->fazendas()->orderBy('id')->first();
        $autor = $usuarios->first() ?? User::find($acessosAnteriores->first()?->user_id);
        if ($fazenda === null && $autor === null) {
            $this->error('Informe quem recebe o acesso: --usuario=SEU_USUARIO.');

            return self::FAILURE;
        }
        if ($fazenda === null) {
            $fazenda = DB::transaction(function () use ($fazendas, $contexto, $autor) {
                $cliente = (new Cliente(['nome' => 'Demonstração']))->forceFill(['demonstracao' => true]);
                $cliente->save();
                $fazenda = $fazendas->criar($cliente, ['nome' => self::NOME_FAZENDA, 'municipio' => 'Uberaba', 'uf' => 'MG']);
                $contexto->executarEm($fazenda, fn () => $this->semear($autor));

                return $fazenda;
            });
            $this->info("Demonstração criada: {$fazenda->nome} (id {$fazenda->id}).");
        } else {
            $this->line("A demonstração já existe: {$fazenda->nome} (id {$fazenda->id}). Use --recriar para gerar de novo.");
        }

        foreach ($acessosAnteriores as $acesso) {
            User::find($acesso->user_id)?->vincular($fazenda, $acesso->perfil, $acesso->ativo);
        }
        foreach ($usuarios as $usuario) {
            $usuario->vincular($fazenda, Perfil::GESTOR);
            $this->info("Acesso de gestor para \"{$usuario->usuario}\". Recarregue o painel e troque de fazenda no menu do usuário.");
        }

        return self::SUCCESS;
    }

    private function semear(?User $autor): void
    {
        foreach ([FazendaDemoSeeder::class, FinanceiroDemoSeeder::class, EstoqueDemoSeeder::class, EngordaDemoSeeder::class] as $classe) {
            /** @var Seeder $seeder */
            $seeder = new $classe;
            if (property_exists($seeder, 'gestor')) {
                $seeder->gestor = $autor;
                $seeder->caseiro = $autor;
            }
            $seeder->setContainer($this->laravel)->setCommand($this)->__invoke();
        }
    }
}

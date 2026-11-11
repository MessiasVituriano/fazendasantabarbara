<?php

namespace Database\Seeders;

use App\Enums\Perfil;
use App\Models\Cliente;
use App\Models\Fazenda;
use App\Models\User;
use App\Services\ContextoFazenda;
use App\Services\ServicoFazenda;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    private const FAZENDA_DEMO = 2;

    public function run(): void
    {
        // Dados de demonstração nunca entram em produção (as categorias financeiras vêm da migration).
        if (app()->isProduction()) {
            $this->command?->warn('Ambiente de produção: seed de demonstração ignorado. Crie o gestor com pecuaria:criar-gestor.');

            return;
        }

        // A fazenda 1 é a Santa Bárbara (criada pela migration, com os registros reais em produção);
        // a demonstração fica separada, na fazenda 2, de um cliente próprio.
        if (Fazenda::whereKey(self::FAZENDA_DEMO)->exists()) {
            $this->command?->warn('A fazenda de demonstração já existe: rode migrate:fresh --seed para recriá-la.');

            return;
        }
        $cliente = Cliente::create(['nome' => 'Cliente Demonstração']);
        $fazenda = app(ServicoFazenda::class)->criar($cliente, ['nome' => 'Fazenda Demonstração', 'municipio' => 'Uberaba', 'uf' => 'MG'], self::FAZENDA_DEMO);

        app(ContextoFazenda::class)->executarEm($fazenda, function (Fazenda $fazenda) {
            // Usuários de demonstração (troque as senhas em produção ou use pecuaria:criar-gestor).
            User::updateOrCreate(['usuario' => 'gestor'], ['name' => 'Gestor da Fazenda', 'ativo' => true, 'password' => 'gestor123'])
                ->vincular($fazenda, Perfil::GESTOR);
            User::updateOrCreate(['usuario' => 'caseiro'], ['name' => 'João Caseiro', 'ativo' => true, 'password' => 'caseiro123'])
                ->vincular($fazenda, Perfil::CASEIRO);

            $this->call([FazendaDemoSeeder::class, FinanceiroDemoSeeder::class, EstoqueDemoSeeder::class, EngordaDemoSeeder::class]);
        });
    }
}

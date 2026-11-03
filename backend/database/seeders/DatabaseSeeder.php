<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Dados de demonstração nunca entram em produção (as categorias financeiras vêm da migration).
        if (app()->isProduction()) {
            $this->command?->warn('Ambiente de produção: seed de demonstração ignorado. Crie o gestor com pecuaria:criar-gestor.');

            return;
        }

        // Usuários de demonstração (troque as senhas em produção ou use pecuaria:criar-gestor).
        User::updateOrCreate(['usuario' => 'gestor'], ['name' => 'Gestor da Fazenda', 'perfil' => 'GESTOR', 'ativo' => true, 'password' => 'gestor123']);
        User::updateOrCreate(['usuario' => 'caseiro'], ['name' => 'João Caseiro', 'perfil' => 'CASEIRO', 'ativo' => true, 'password' => 'caseiro123']);

        $this->call([FazendaDemoSeeder::class, FinanceiroDemoSeeder::class]);
    }
}

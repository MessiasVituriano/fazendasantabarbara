<?php

use App\Enums\Perfil;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multipropriedade: cliente (quem assina) → fazendas; o perfil do usuário passa a valer por fazenda.
 * A instalação existente vira o cliente 1 / fazenda 1, e cada usuário recebe o vínculo com o perfil e a situação
 * que já tinha. users.ativo continua como bloqueio geral do login (da plataforma), por isso volta a true aqui:
 * quem estava desativado fica desativado no vínculo com a fazenda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 120);
            $table->string('documento', 20)->nullable(); // CPF/CNPJ, só dígitos
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        Schema::create('fazendas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->string('nome', 120);
            $table->string('municipio', 80)->nullable();
            $table->string('uf', 2)->nullable();
            $table->string('documento', 20)->nullable(); // CPF/CNPJ/IE, só dígitos
            $table->boolean('ativa')->default(true);
            $table->timestamps();
        });

        Schema::create('fazenda_usuario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fazenda_id')->constrained('fazendas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('perfil', Perfil::values());
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->unique(['fazenda_id', 'user_id']);
            $table->index('user_id');
        });

        // Quem gerencia o cliente (fazendas, usuários e assinatura). Usado a partir da fase 3.
        Schema::create('cliente_usuario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('papel', 20)->default('DONO');
            $table->timestamps();

            $table->unique(['cliente_id', 'user_id']);
        });

        $agora = now();
        DB::table('clientes')->insert(['id' => 1, 'nome' => 'Fazenda Santa Bárbara', 'ativo' => true, 'created_at' => $agora, 'updated_at' => $agora]);
        DB::table('fazendas')->insert(['id' => 1, 'cliente_id' => 1, 'nome' => 'Fazenda Santa Bárbara', 'ativa' => true, 'created_at' => $agora, 'updated_at' => $agora]);
        $this->ajustarSequencias(['clientes', 'fazendas']);

        foreach (DB::table('users')->orderBy('id')->get(['id', 'perfil', 'ativo']) as $usuario) {
            DB::table('fazenda_usuario')->insert([
                'fazenda_id' => 1, 'user_id' => $usuario->id, 'perfil' => $usuario->perfil, 'ativo' => (bool) $usuario->ativo,
                'created_at' => $agora, 'updated_at' => $agora,
            ]);
            if ($usuario->perfil === Perfil::GESTOR->value && $usuario->ativo) {
                DB::table('cliente_usuario')->insert(['cliente_id' => 1, 'user_id' => $usuario->id, 'papel' => 'DONO', 'created_at' => $agora, 'updated_at' => $agora]);
            }
        }
        DB::table('users')->update(['ativo' => true]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('perfil');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('perfil', Perfil::values())->default(Perfil::CASEIRO->value)->after('usuario');
        });

        // Devolve a cada usuário o perfil e a situação que ele tem na fazenda 1.
        foreach (DB::table('fazenda_usuario')->where('fazenda_id', 1)->get() as $vinculo) {
            DB::table('users')->where('id', $vinculo->user_id)->update(['perfil' => $vinculo->perfil, 'ativo' => (bool) $vinculo->ativo]);
        }

        Schema::dropIfExists('cliente_usuario');
        Schema::dropIfExists('fazenda_usuario');
        Schema::dropIfExists('fazendas');
        Schema::dropIfExists('clientes');
    }

    /** No PostgreSQL, inserir com id explícito não avança a sequência: a próxima fazenda colidiria com a 1. */
    private function ajustarSequencias(array $tabelas): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($tabelas as $tabela) {
            DB::statement("SELECT setval(pg_get_serial_sequence('{$tabela}', 'id'), (SELECT MAX(id) FROM {$tabela}))");
        }
    }
};

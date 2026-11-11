<?php

use App\Enums\Perfil;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acesso de clientes externos: administrador da plataforma, convites por link (enviados por WhatsApp ou e-mail),
 * redefinição de senha por link e acesso de suporte a uma fazenda (com motivo e prazo, para ficar registrado).
 * Os links guardam só o hash do token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('admin_plataforma')->default(false)->after('ativo');
        });

        Schema::create('convites', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('fazenda_id')->constrained('fazendas')->cascadeOnDelete();
            $table->enum('perfil', Perfil::values());
            $table->boolean('dono')->default(false); // também passa a gerenciar o cliente
            $table->string('nome', 100);
            $table->string('email', 150)->nullable();
            $table->string('telefone', 20)->nullable();
            $table->foreignId('convidado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('aceito_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aceito_em')->nullable();
            $table->timestamp('cancelado_em')->nullable();
            $table->timestamp('expira_em');
            $table->timestamps();

            $table->index(['fazenda_id', 'aceito_em']);
        });

        Schema::create('redefinicoes_senha', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('gerado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expira_em');
            $table->timestamp('usado_em')->nullable();
            $table->timestamps();
        });

        Schema::create('acessos_suporte', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('fazenda_id')->constrained('fazendas')->cascadeOnDelete();
            $table->string('motivo', 255);
            $table->timestamp('expira_em');
            $table->timestamp('encerrado_em')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'fazenda_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acessos_suporte');
        Schema::dropIfExists('redefinicoes_senha');
        Schema::dropIfExists('convites');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('admin_plataforma');
        });
    }
};

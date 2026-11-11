<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Marca o cliente de demonstração (criado por pecuaria:criar-demo), que pode ser apagado e recriado a qualquer momento. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->boolean('demonstracao')->default(false)->after('ativo');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('demonstracao');
        });
    }
};

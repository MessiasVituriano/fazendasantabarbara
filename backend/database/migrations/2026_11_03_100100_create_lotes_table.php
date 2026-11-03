<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotes', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 80)->unique();
            $table->date('data_formacao');
            $table->string('categoria', 60)->nullable();   // ex.: "Garrotes de recria", "Bezerros desmamados"
            $table->string('origem', 120)->nullable();
            $table->text('observacoes')->nullable();
            $table->date('data_encerramento')->nullable(); // lote vendido/desfeito: some das listas ativas, mantém o histórico
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lotes');
    }
};

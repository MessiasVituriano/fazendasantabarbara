<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacinas_sanidade', function (Blueprint $table) {
            $table->id();
            $table->foreignId('animal_id')->constrained('animais')->cascadeOnDelete();
            $table->string('nome_vacina');
            $table->date('data_aplicacao');
            $table->unsignedInteger('dias_carencia_leite_carne')->default(0);
            // data_aplicacao + dias_carencia, gravada pelo model: consulta de carência
            // portável e indexável (sem aritmética de datas específica do banco).
            $table->date('data_fim_carencia');
            $table->timestamps();

            $table->index(['animal_id', 'data_fim_carencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacinas_sanidade');
    }
};

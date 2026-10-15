<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fotos_animal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('animal_id')->constrained('animais')->cascadeOnDelete();
            $table->string('caminho');
            $table->string('caminho_miniatura');
            $table->unsignedSmallInteger('largura');
            $table->unsignedSmallInteger('altura');
            $table->string('legenda')->nullable();
            $table->date('data_registro');
            // A foto principal substitui a silhueta no avatar.
            $table->boolean('principal')->default(false);
            $table->timestamps();

            $table->index(['animal_id', 'principal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fotos_animal');
    }
};

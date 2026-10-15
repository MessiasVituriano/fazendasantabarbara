<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pesagens', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique(); // idempotência da sincronização mobile
            $table->foreignId('animal_id')->constrained('animais')->cascadeOnDelete();
            $table->decimal('peso_kg', 6, 2);
            $table->date('data_pesagem');
            $table->timestamps();

            // Uma pesagem por dia por animal: evita divisão por zero no GMD.
            $table->unique(['animal_id', 'data_pesagem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pesagens');
    }
};

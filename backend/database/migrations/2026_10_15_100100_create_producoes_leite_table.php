<?php

use App\Enums\Periodo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producoes_leite', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique(); // idempotência da sincronização mobile
            $table->foreignId('animal_id')->constrained('animais')->cascadeOnDelete();
            $table->date('data_pesagem');
            $table->enum('periodo', Periodo::values());
            $table->decimal('quantidade_litros', 5, 2);
            $table->timestamps();

            $table->unique(['animal_id', 'data_pesagem', 'periodo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producoes_leite');
    }
};

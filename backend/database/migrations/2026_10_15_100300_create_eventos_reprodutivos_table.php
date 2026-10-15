<?php

use App\Enums\TipoEventoReprodutivo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventos_reprodutivos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique(); // idempotência da sincronização mobile
            $table->foreignId('animal_id')->constrained('animais')->cascadeOnDelete();
            $table->enum('tipo_evento', TipoEventoReprodutivo::values());
            $table->date('data_evento');
            $table->text('observacoes')->nullable();
            // Preenchido nos eventos de PARTO com o bezerro gerado automaticamente.
            $table->foreignId('bezerro_id')->nullable()->constrained('animais')->nullOnDelete();
            $table->timestamps();

            $table->index(['animal_id', 'data_evento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_reprodutivos');
    }
};

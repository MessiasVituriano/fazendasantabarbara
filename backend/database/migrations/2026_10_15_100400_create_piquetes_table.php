<?php

use App\Enums\StatusPiquete;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('piquetes', function (Blueprint $table) {
            $table->id();
            $table->string('identificacao')->unique();
            $table->decimal('hectares', 8, 2);
            $table->enum('status', StatusPiquete::values())->default(StatusPiquete::DESCANSO->value);
            $table->timestamps();
        });

        Schema::create('historico_pastoreio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('piquete_id')->constrained('piquetes')->cascadeOnDelete();
            $table->foreignId('animal_id')->constrained('animais')->cascadeOnDelete();
            $table->date('data_entrada');
            $table->date('data_saida')->nullable();
            $table->timestamps();

            $table->index(['piquete_id', 'data_saida']);
            $table->index(['animal_id', 'data_saida']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historico_pastoreio');
        Schema::dropIfExists('piquetes');
    }
};

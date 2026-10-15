<?php

use App\Enums\Aptidao;
use App\Enums\Categoria;
use App\Enums\Sexo;
use App\Enums\StatusGeral;
use App\Enums\StatusReprodutivo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('animais', function (Blueprint $table) {
            $table->id();
            $table->string('brinco')->unique();
            $table->string('nome')->nullable();
            $table->enum('sexo', Sexo::values());
            $table->date('data_nascimento');
            $table->enum('aptidao', Aptidao::values());
            $table->enum('categoria', Categoria::values());
            $table->enum('status_reprodutivo', StatusReprodutivo::values())->default(StatusReprodutivo::APTA->value);
            $table->enum('status_geral', StatusGeral::values())->default(StatusGeral::ATIVO->value);
            $table->foreignId('mae_id')->nullable()->constrained('animais')->nullOnDelete();

            // Projeções gravadas pelas automações reprodutivas (config/pecuaria.php).
            $table->date('data_prevista_toque')->nullable();
            $table->date('data_provavel_parto')->nullable();
            $table->date('data_prevista_secagem')->nullable();

            $table->timestamps();

            $table->index(['status_geral', 'categoria']);
            $table->index('data_nascimento');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('animais');
    }
};

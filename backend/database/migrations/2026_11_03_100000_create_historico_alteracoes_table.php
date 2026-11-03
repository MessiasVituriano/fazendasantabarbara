<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Trilha de auditoria: quem alterou o quê, com os valores antes e depois. Nunca é editada. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historico_alteracoes', function (Blueprint $table) {
            $table->id();
            $table->string('auditavel_type');
            $table->unsignedBigInteger('auditavel_id');
            $table->string('acao', 30);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('antes')->nullable();
            $table->json('depois')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditavel_type', 'auditavel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historico_alteracoes');
    }
};

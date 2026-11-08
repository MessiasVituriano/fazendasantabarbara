<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itens_estoque', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 100)->unique();
            $table->string('tipo', 20)->index();
            $table->string('unidade', 10);
            // Saldo mantido pelos movimentos (nunca editado direto); o mínimo dispara o alerta de reposição.
            $table->decimal('saldo', 14, 3)->default(0);
            $table->decimal('estoque_minimo', 14, 3)->default(0);
            // Valor unitário da última entrada com preço: base do valor do estoque.
            $table->decimal('custo_unitario', 14, 4)->nullable();
            // Validade do lote em uso (medicamentos, vacinas, ração).
            $table->date('data_validade')->nullable();
            $table->string('localizacao', 100)->nullable();
            $table->text('observacoes')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        Schema::create('movimentos_estoque', function (Blueprint $table) {
            $table->id();
            // Gerado no aparelho: o reenvio da fila offline não duplica o movimento.
            $table->uuid('uuid')->nullable()->unique();
            $table->foreignId('item_estoque_id')->constrained('itens_estoque')->restrictOnDelete();
            $table->string('tipo', 10);
            $table->date('data_movimento');
            // Entrada soma, saída subtrai; no ajuste é a diferença (com sinal) entre o contado e o saldo.
            $table->decimal('quantidade', 14, 3);
            $table->decimal('valor_unitario', 14, 4)->nullable();
            $table->string('observacao', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelado_em')->nullable();
            $table->foreignId('cancelado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo_cancelamento', 255)->nullable();
            $table->timestamps();

            $table->index(['item_estoque_id', 'data_movimento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimentos_estoque');
        Schema::dropIfExists('itens_estoque');
    }
};

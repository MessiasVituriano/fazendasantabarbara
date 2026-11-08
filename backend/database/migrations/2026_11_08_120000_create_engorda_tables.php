<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engorda (fase 1): animais dentro do lote (com histórico), metas de engorda do lote
 * e destino das saídas de estoque (ração consumida pelo lote).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Mesmo modelo do historico_pastoreio: data_saida nula = animal está no lote agora.
        Schema::create('animais_lote', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lote_id')->constrained('lotes')->restrictOnDelete();
            $table->foreignId('animal_id')->constrained('animais')->cascadeOnDelete();
            $table->date('data_entrada');
            $table->date('data_saida')->nullable();
            $table->timestamps();

            $table->index(['lote_id', 'data_saida']);
            $table->index(['animal_id', 'data_saida']);
        });

        // A meta atual do lote é a mais recente não cancelada; criar outra cancela a anterior (histórico mantido).
        Schema::create('metas_engorda', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lote_id')->constrained('lotes')->restrictOnDelete();
            $table->decimal('peso_alvo_kg', 6, 2);
            $table->date('data_inicio');
            $table->date('data_alvo');
            $table->decimal('gmd_alvo', 5, 3)->nullable();
            $table->decimal('peso_inicial_kg', 6, 2)->nullable(); // peso médio do lote ao criar a meta
            $table->text('observacoes')->nullable();
            $table->timestamp('cancelada_em')->nullable();
            $table->string('motivo_cancelamento', 255)->nullable();
            $table->timestamps();

            $table->index(['lote_id', 'cancelada_em']);
        });

        Schema::table('movimentos_estoque', function (Blueprint $table) {
            // Para qual lote foi a saída (ração, sal...). Só em saídas.
            $table->foreignId('lote_id')->nullable()->after('item_estoque_id')->constrained('lotes')->restrictOnDelete();
            // Custo do item no momento da saída: o custo do item muda a cada compra.
            $table->decimal('custo_unitario', 14, 4)->nullable()->after('valor_unitario');
            $table->index(['lote_id', 'data_movimento']);
        });
    }

    public function down(): void
    {
        Schema::table('movimentos_estoque', function (Blueprint $table) {
            $table->dropIndex(['lote_id', 'data_movimento']);
            $table->dropConstrainedForeignId('lote_id');
            $table->dropColumn('custo_unitario');
        });
        Schema::dropIfExists('metas_engorda');
        Schema::dropIfExists('animais_lote');
    }
};

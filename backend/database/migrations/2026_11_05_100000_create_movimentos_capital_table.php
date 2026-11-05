<?php

use App\Enums\FormaPagamento;
use App\Enums\TipoMovimentoCapital;
use App\Enums\TipoPessoa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->permitirTiposDePessoa(TipoPessoa::values());

        // Aportes e retiradas de investidores: mexem no saldo das contas, mas não são receita nem despesa.
        Schema::create('movimentos_capital', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', TipoMovimentoCapital::values());
            $table->foreignId('pessoa_id')->constrained('pessoas')->restrictOnDelete();
            $table->decimal('valor', 14, 2);
            $table->date('data_movimento');
            $table->foreignId('conta_financeira_id')->constrained('contas_financeiras')->restrictOnDelete();
            $table->enum('forma_pagamento', FormaPagamento::values())->nullable();
            $table->string('documento', 60)->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamp('cancelado_em')->nullable();
            $table->string('motivo_cancelamento', 255)->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('atualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('data_movimento');
            $table->index(['pessoa_id', 'data_movimento']);
            $table->index(['conta_financeira_id', 'data_movimento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimentos_capital');

        $this->permitirTiposDePessoa(['FORNECEDOR', 'CLIENTE', 'AMBOS']);
    }

    /**
     * No PostgreSQL o enum é um CHECK, que o change() do schema builder não sabe reescrever.
     *
     * @param  list<string>  $tipos
     */
    private function permitirTiposDePessoa(array $tipos): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('pessoas', fn (Blueprint $table) => $table->enum('tipo', $tipos)->change());

            return;
        }

        $lista = implode(', ', array_map(fn (string $tipo) => "'{$tipo}'", $tipos));
        DB::statement('ALTER TABLE pessoas DROP CONSTRAINT pessoas_tipo_check');
        DB::statement("ALTER TABLE pessoas ADD CONSTRAINT pessoas_tipo_check CHECK (tipo IN ({$lista}))");
    }
};

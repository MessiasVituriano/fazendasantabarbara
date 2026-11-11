<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada registro de negócio passa a pertencer a uma fazenda. Os registros que já existem vão para a fazenda 1
 * (Santa Bárbara): nada é apagado nem recriado, só ganha a coluna. Em etapas, para funcionar com dados:
 * coluna opcional → preenchimento → obrigatória. As tabelas filhas também recebem a coluna, para o filtro
 * por fazenda não depender de passar pelo registro pai.
 */
return new class extends Migration
{
    /** Tabelas que ganham fazenda_id. */
    private const TABELAS = [
        'animais', 'producoes_leite', 'pesagens', 'eventos_reprodutivos', 'piquetes', 'historico_pastoreio',
        'vacinas_sanidade', 'fotos_animal', 'historico_alteracoes', 'lotes', 'animais_lote', 'metas_engorda',
        'contas_financeiras', 'categorias_financeiras', 'pessoas', 'lancamentos', 'anexos_lancamento',
        'movimentos_capital', 'categorias_sanitarias', 'produtos_sanitarios', 'itens_estoque', 'movimentos_estoque',
    ];

    /**
     * Unicidades que passam a valer dentro da fazenda: [tabela, colunas antigas, colunas novas].
     *
     * @var list<array{0: string, 1: list<string>, 2: list<string>}>
     */
    private const UNICAS = [
        ['animais', ['brinco'], ['fazenda_id', 'brinco']],
        ['piquetes', ['identificacao'], ['fazenda_id', 'identificacao']],
        ['lotes', ['nome'], ['fazenda_id', 'nome']],
        ['contas_financeiras', ['nome'], ['fazenda_id', 'nome']],
        ['categorias_financeiras', ['chave'], ['fazenda_id', 'chave']],
        ['categorias_financeiras', ['tipo', 'nome'], ['fazenda_id', 'tipo', 'nome']],
        ['categorias_sanitarias', ['chave'], ['fazenda_id', 'chave']],
        ['categorias_sanitarias', ['tipo', 'nome'], ['fazenda_id', 'tipo', 'nome']],
        ['itens_estoque', ['nome'], ['fazenda_id', 'nome']],
    ];

    public function up(): void
    {
        foreach (self::TABELAS as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->foreignId('fazenda_id')->nullable()->after('id')->constrained('fazendas')->restrictOnDelete();
            });
            DB::table($tabela)->update(['fazenda_id' => 1]);
            Schema::table($tabela, function (Blueprint $table) {
                $table->unsignedBigInteger('fazenda_id')->nullable(false)->change();
            });
        }

        foreach (self::UNICAS as [$tabela, $antigas, $novas]) {
            Schema::table($tabela, function (Blueprint $table) use ($antigas, $novas) {
                $table->dropUnique($antigas);
                $table->unique($novas);
            });
        }

        // Nas tabelas sem unicidade começando por fazenda_id, um índice próprio (o PostgreSQL não cria para a FK).
        $comUnica = array_unique(array_column(self::UNICAS, 0));
        foreach (array_diff(self::TABELAS, $comUnica) as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->index('fazenda_id');
            });
        }
    }

    public function down(): void
    {
        $comUnica = array_unique(array_column(self::UNICAS, 0));
        foreach (array_diff(self::TABELAS, $comUnica) as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->dropIndex(['fazenda_id']);
            });
        }

        foreach (array_reverse(self::UNICAS) as [$tabela, $antigas, $novas]) {
            Schema::table($tabela, function (Blueprint $table) use ($antigas, $novas) {
                $table->dropUnique($novas);
                $table->unique($antigas);
            });
        }

        foreach (self::TABELAS as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->dropConstrainedForeignId('fazenda_id');
            });
        }
    }
};

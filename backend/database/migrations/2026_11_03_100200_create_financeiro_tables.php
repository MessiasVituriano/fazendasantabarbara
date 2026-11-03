<?php

use App\Enums\FormaPagamento;
use App\Enums\GrupoCusto;
use App\Enums\SituacaoLancamento;
use App\Enums\TipoContaFinanceira;
use App\Enums\TipoLancamento;
use App\Enums\TipoPessoa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Onde o dinheiro está: caixa, conta corrente etc. O saldo atual é sempre calculado.
        Schema::create('contas_financeiras', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 80)->unique();
            $table->enum('tipo', TipoContaFinanceira::values());
            $table->decimal('saldo_inicial', 14, 2)->default(0);
            $table->date('data_saldo_inicial');
            $table->boolean('ativa')->default(true);
            $table->timestamps();
        });

        Schema::create('categorias_financeiras', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 80);
            $table->enum('tipo', TipoLancamento::values());
            // Só para despesas: em que grupo de custo da recria a despesa entra.
            $table->enum('grupo_custo', GrupoCusto::values())->nullable();
            // Chave estável das categorias padrão (usada por compras/vendas); null nas criadas pelo usuário.
            $table->string('chave', 40)->nullable()->unique();
            $table->boolean('ativa')->default(true);
            $table->timestamps();

            $table->unique(['tipo', 'nome']);
        });

        Schema::create('pessoas', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 120);
            $table->enum('tipo', TipoPessoa::values());
            $table->string('documento', 20)->nullable(); // CPF/CNPJ/IE, só dígitos
            $table->string('telefone', 20)->nullable();
            $table->text('observacoes')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->index('nome');
        });

        Schema::create('lancamentos', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', TipoLancamento::values());
            $table->string('descricao', 160);
            $table->foreignId('categoria_id')->constrained('categorias_financeiras')->restrictOnDelete();
            // Cópia do grupo da categoria no momento do lançamento: mudar a categoria depois não reescreve custos antigos.
            $table->enum('grupo_custo', GrupoCusto::values())->nullable();
            $table->decimal('valor', 14, 2);

            // Competência (quando o fato aconteceu) ≠ vencimento (quando deve ser pago) ≠ pagamento (quando o dinheiro saiu).
            $table->date('data_competencia');
            $table->date('data_vencimento');
            $table->enum('situacao', SituacaoLancamento::values())->default(SituacaoLancamento::PENDENTE->value);
            $table->date('data_pagamento')->nullable();
            $table->decimal('valor_pago', 14, 2)->nullable(); // pode diferir do valor (juros, multa, desconto)
            $table->foreignId('conta_financeira_id')->nullable()->constrained('contas_financeiras')->restrictOnDelete();
            $table->enum('forma_pagamento', FormaPagamento::values())->nullable();

            $table->foreignId('pessoa_id')->nullable()->constrained('pessoas')->nullOnDelete();
            $table->foreignId('lote_id')->nullable()->constrained('lotes')->restrictOnDelete();
            $table->string('documento', 60)->nullable(); // nº da nota, boleto, recibo
            $table->text('observacoes')->nullable();

            $table->uuid('parcelamento_id')->nullable();
            $table->unsignedSmallInteger('parcela')->nullable();
            $table->unsignedSmallInteger('total_parcelas')->nullable();
            $table->string('motivo_cancelamento', 255)->nullable();

            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('atualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['situacao', 'data_vencimento']);
            $table->index(['situacao', 'data_pagamento']);
            $table->index('data_competencia');
            $table->index('lote_id');
            $table->index('parcelamento_id');
        });

        Schema::create('anexos_lancamento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lancamento_id')->constrained('lancamentos')->cascadeOnDelete();
            $table->string('nome_original');
            $table->string('caminho');
            $table->string('mime', 100);
            $table->unsignedInteger('tamanho');
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->categoriasPadrao();
    }

    /** Categorias do plano de contas rural. São dados de referência: existem também em produção. */
    private function categoriasPadrao(): void
    {
        $agora = now();
        $linhas = [
            ['venda_bovinos', 'Venda de bovinos', 'RECEITA', null],
            ['venda_outros_produtos', 'Venda de outros produtos rurais', 'RECEITA', null],
            ['receitas_diversas', 'Receitas diversas', 'RECEITA', null],

            ['compra_animais', 'Compra de animais', 'DESPESA', 'AQUISICAO'],
            ['despesas_aquisicao', 'Frete, comissão e custos de compra', 'DESPESA', 'AQUISICAO'],
            ['suplementacao_mineral', 'Suplementação mineral', 'DESPESA', 'SUPLEMENTACAO'],
            ['proteinados', 'Proteinados', 'DESPESA', 'SUPLEMENTACAO'],
            ['racoes', 'Rações', 'DESPESA', 'ALIMENTACAO'],
            ['medicamentos_vacinas', 'Medicamentos e vacinas', 'DESPESA', 'SANIDADE'],
            ['mao_de_obra', 'Mão de obra', 'DESPESA', 'MAO_DE_OBRA'],
            ['combustivel', 'Combustível', 'DESPESA', 'TRANSPORTE'],
            ['frete_transporte', 'Frete e transporte', 'DESPESA', 'TRANSPORTE'],
            ['manutencao_cercas', 'Manutenção de cercas', 'DESPESA', 'MANUTENCAO'],
            ['manutencao_pastagens', 'Manutenção de pastagens', 'DESPESA', 'PASTAGENS'],
            ['energia_eletrica', 'Energia elétrica', 'DESPESA', 'ADMINISTRATIVO'],
            ['agua', 'Água', 'DESPESA', 'ADMINISTRATIVO'],
            ['arrendamento', 'Arrendamento', 'DESPESA', 'PASTAGENS'],
            ['impostos', 'Impostos', 'DESPESA', 'ADMINISTRATIVO'],
            ['despesas_administrativas', 'Despesas administrativas', 'DESPESA', 'ADMINISTRATIVO'],
            ['despesas_vendas', 'Frete, comissão e custos de venda', 'DESPESA', 'OUTROS'],
            ['outras_despesas', 'Outras despesas', 'DESPESA', 'OUTROS'],
        ];

        DB::table('categorias_financeiras')->insert(array_map(fn ($l) => [
            'chave' => $l[0], 'nome' => $l[1], 'tipo' => $l[2], 'grupo_custo' => $l[3],
            'ativa' => true, 'created_at' => $agora, 'updated_at' => $agora,
        ], $linhas));
    }

    public function down(): void
    {
        Schema::dropIfExists('anexos_lancamento');
        Schema::dropIfExists('lancamentos');
        Schema::dropIfExists('pessoas');
        Schema::dropIfExists('categorias_financeiras');
        Schema::dropIfExists('contas_financeiras');
    }
};

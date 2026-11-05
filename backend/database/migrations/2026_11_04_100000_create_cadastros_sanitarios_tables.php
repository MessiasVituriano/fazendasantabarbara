<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias_sanitarias', function (Blueprint $table) {
            $table->id();
            // Chave estável das categorias padrão; null nas criadas pelo usuário.
            $table->string('chave', 40)->nullable()->unique();
            $table->string('nome', 80);
            $table->string('tipo', 20);
            // Só uma sugestão para o formulário; null = sem sugestão, o usuário informa o prazo da bula.
            $table->unsignedInteger('dias_carencia_padrao')->nullable();
            $table->boolean('ativa')->default(true);
            $table->timestamps();

            $table->unique(['tipo', 'nome']);
        });

        Schema::create('produtos_sanitarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_sanitaria_id')->constrained('categorias_sanitarias')->restrictOnDelete();
            $table->string('nome', 100);
            // Quando preenchida, prevalece sobre a sugestão da categoria.
            $table->unsignedInteger('dias_carencia')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->unique(['categoria_sanitaria_id', 'nome']);
        });

        Schema::table('vacinas_sanidade', function (Blueprint $table) {
            // Nulo nos registros antigos: o nome livre não permite saber se era vacina ou medicamento.
            $table->string('tipo', 20)->nullable()->after('animal_id');
            $table->foreignId('categoria_sanitaria_id')->nullable()->after('tipo')->constrained('categorias_sanitarias')->restrictOnDelete();
            $table->foreignId('produto_sanitario_id')->nullable()->after('categoria_sanitaria_id')->constrained('produtos_sanitarios')->restrictOnDelete();
        });

        $agora = now();
        DB::table('categorias_sanitarias')->insert(array_map(fn ($c) => [
            'chave' => $c[0], 'nome' => $c[1], 'tipo' => $c[2], 'dias_carencia_padrao' => $c[3],
            'ativa' => true, 'created_at' => $agora, 'updated_at' => $agora,
        ], self::CATEGORIAS_PADRAO));
    }

    public function down(): void
    {
        Schema::table('vacinas_sanidade', function (Blueprint $table) {
            $table->dropConstrainedForeignId('produto_sanitario_id');
            $table->dropConstrainedForeignId('categoria_sanitaria_id');
            $table->dropColumn('tipo');
        });
        Schema::dropIfExists('produtos_sanitarios');
        Schema::dropIfExists('categorias_sanitarias');
    }

    /**
     * Sugestões iniciais e editáveis, não substituem a bula: o prazo varia por produto e
     * formulação. Onde a variação é grande demais para sugerir um número, fica null.
     */
    private const CATEGORIAS_PADRAO = [
        ['aftosa', 'Aftosa', 'VACINA', 21],
        ['brucelose', 'Brucelose', 'VACINA', 21],
        ['raiva', 'Raiva', 'VACINA', 21],
        ['clostridioses', 'Clostridioses', 'VACINA', 21],
        ['reprodutivas', 'Reprodutivas (IBR, BVD, leptospirose)', 'VACINA', 21],
        ['outras_vacinas', 'Outras vacinas', 'VACINA', null],
        ['antibiotico', 'Antibiótico', 'MEDICAMENTO', 30],
        ['anti_inflamatorio', 'Anti-inflamatório', 'MEDICAMENTO', 15],
        ['antiparasitario', 'Antiparasitário / vermífugo', 'MEDICAMENTO', 35],
        ['carrapaticida', 'Carrapaticida / mosquicida', 'MEDICAMENTO', 30],
        ['mastite', 'Tratamento de mastite', 'MEDICAMENTO', 7],
        ['hormonio', 'Hormônio (reprodução)', 'MEDICAMENTO', null],
        ['vitaminas', 'Vitaminas e suplementos', 'MEDICAMENTO', null],
        ['outros_medicamentos', 'Outros medicamentos', 'MEDICAMENTO', null],
    ];
};

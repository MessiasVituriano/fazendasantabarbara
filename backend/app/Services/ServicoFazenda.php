<?php

namespace App\Services;

use App\Models\CategoriaFinanceira;
use App\Models\CategoriaSanitaria;
use App\Models\Cliente;
use App\Models\Fazenda;
use Illuminate\Support\Facades\DB;

/**
 * Criação de fazendas. Cada fazenda nasce com o próprio catálogo de categorias financeiras e sanitárias,
 * o mesmo que as migrations criaram para a fazenda 1 (as compras, vendas e a sanidade dependem das chaves).
 */
class ServicoFazenda
{
    /** Plano de contas rural: [chave, nome, tipo, grupo de custo]. */
    public const CATEGORIAS_FINANCEIRAS = [
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

    /**
     * Sugestões de carência editáveis, não substituem a bula: [chave, nome, tipo, dias de carência padrão].
     */
    public const CATEGORIAS_SANITARIAS = [
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

    public function __construct(private ContextoFazenda $contexto) {}

    /** @param  array{nome: string, municipio?: ?string, uf?: ?string, documento?: ?string}  $dados */
    public function criar(Cliente $cliente, array $dados, ?int $id = null): Fazenda
    {
        return DB::transaction(function () use ($cliente, $dados, $id) {
            $fazenda = new Fazenda([...$dados, 'cliente_id' => $cliente->id]);
            if ($id !== null) {
                $fazenda->id = $id;
            }
            $fazenda->save();
            $this->criarCatalogo($fazenda);

            if ($id !== null && DB::getDriverName() === 'pgsql') {
                DB::statement("SELECT setval(pg_get_serial_sequence('fazendas', 'id'), (SELECT MAX(id) FROM fazendas))");
            }

            return $fazenda;
        });
    }

    /** Categorias financeiras e sanitárias padrão da fazenda. */
    public function criarCatalogo(Fazenda $fazenda): void
    {
        $this->contexto->executarEm($fazenda, function () {
            foreach (self::CATEGORIAS_FINANCEIRAS as [$chave, $nome, $tipo, $grupo]) {
                (new CategoriaFinanceira(['nome' => $nome, 'tipo' => $tipo, 'grupo_custo' => $grupo, 'ativa' => true]))
                    ->forceFill(['chave' => $chave])->save();
            }
            foreach (self::CATEGORIAS_SANITARIAS as [$chave, $nome, $tipo, $dias]) {
                (new CategoriaSanitaria(['nome' => $nome, 'tipo' => $tipo, 'dias_carencia_padrao' => $dias, 'ativa' => true]))
                    ->forceFill(['chave' => $chave])->save();
            }
        });
    }
}

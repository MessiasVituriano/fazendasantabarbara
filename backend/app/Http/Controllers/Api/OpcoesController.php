<?php

namespace App\Http\Controllers\Api;

use App\Enums;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Valores de enums (com rótulos) e parâmetros de config para montar formulários no front/mobile. */
class OpcoesController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $opcoes = fn (string $enum) => array_map(
            fn ($caso) => ['value' => $caso->value, 'label' => $caso->label()],
            $enum::cases(),
        );

        return response()->json(['data' => [
            'aptidao' => $opcoes(Enums\Aptidao::class),
            'sexo' => $opcoes(Enums\Sexo::class),
            'categoria' => $opcoes(Enums\Categoria::class),
            'status_reprodutivo' => $opcoes(Enums\StatusReprodutivo::class),
            'status_geral' => $opcoes(Enums\StatusGeral::class),
            'periodo' => $opcoes(Enums\Periodo::class),
            'tipo_evento' => $opcoes(Enums\TipoEventoReprodutivo::class),
            'status_piquete' => $opcoes(Enums\StatusPiquete::class),
            'perfil' => array_map(fn ($p) => ['value' => $p->value, 'label' => $p->label(), 'descricao' => $p->descricao()], Enums\Perfil::cases()),
            'tipo_lancamento' => $opcoes(Enums\TipoLancamento::class),
            'situacao_lancamento' => Enums\SituacaoLancamento::opcoesDeFiltro(),
            'forma_pagamento' => $opcoes(Enums\FormaPagamento::class),
            'grupo_custo' => $opcoes(Enums\GrupoCusto::class),
            'tipo_conta_financeira' => $opcoes(Enums\TipoContaFinanceira::class),
            'tipo_pessoa' => $opcoes(Enums\TipoPessoa::class),
            'tipo_produto_sanitario' => $opcoes(Enums\TipoProdutoSanitario::class),
            'tipo_item_estoque' => $opcoes(Enums\TipoItemEstoque::class),
            'unidade_estoque' => array_map(fn ($u) => ['value' => $u->value, 'label' => $u->label(), 'sigla' => $u->sigla()], Enums\UnidadeEstoque::cases()),
            'tipo_movimento_estoque' => $opcoes(Enums\TipoMovimentoEstoque::class),
            'pecuaria' => config('pecuaria'),
        ]]);
    }
}

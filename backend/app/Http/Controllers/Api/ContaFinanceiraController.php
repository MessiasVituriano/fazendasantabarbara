<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\RegraNegocioException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SalvarContaFinanceiraRequest;
use App\Models\ContaFinanceira;
use Illuminate\Http\JsonResponse;

/** Caixa e contas bancárias. Não são excluídas (têm histórico): desativam-se. */
class ContaFinanceiraController extends Controller
{
    public function index(): JsonResponse
    {
        $saldos = ContaFinanceira::saldos();

        return response()->json(['data' => ContaFinanceira::orderByDesc('ativa')->orderBy('nome')->get()
            ->map(fn ($c) => [...$c->toArray(), 'tipo_label' => $c->tipo->label(), 'saldo_atual' => $saldos[$c->id] ?? 0])]);
    }

    public function store(SalvarContaFinanceiraRequest $request): JsonResponse
    {
        return response()->json(['data' => ContaFinanceira::create($request->validated())], 201);
    }

    public function update(SalvarContaFinanceiraRequest $request, ContaFinanceira $conta): JsonResponse
    {
        $dados = $request->validated();
        if (isset($dados['data_saldo_inicial'])
            && ($conta->lancamentos()->pagos()->where('data_pagamento', '<', $dados['data_saldo_inicial'])->exists()
                || $conta->movimentosCapital()->validos()->where('data_movimento', '<', $dados['data_saldo_inicial'])->exists())) {
            throw new RegraNegocioException(
                'Há pagamentos, aportes ou retiradas nesta conta anteriores a essa data. A data do saldo inicial deve ser igual ou anterior ao primeiro deles.',
                'SALDO_INICIAL_POSTERIOR', 422, '/data_saldo_inicial',
            );
        }
        $conta->update($dados);

        return response()->json(['data' => $conta]);
    }
}

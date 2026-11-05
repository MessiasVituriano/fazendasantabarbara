<?php

namespace App\Services;

use App\Enums\SituacaoLancamento;
use App\Enums\TipoLancamento;
use App\Enums\TipoPessoa;
use App\Exceptions\RegraNegocioException;
use App\Models\CategoriaFinanceira;
use App\Models\ContaFinanceira;
use App\Models\Lancamento;
use App\Models\MovimentoCapital;
use App\Models\Pessoa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Regras de escrita do financeiro. Toda mudança passa por aqui para garantir:
 *  - categoria compatível com o tipo (receita/despesa);
 *  - pagamento só em conta ativa e em data a partir do saldo inicial da conta;
 *  - lançamento pago não muda de valor/conta sem estorno (o saldo nunca "se mexe sozinho");
 *  - nada é excluído: cancela-se, e a trilha fica no histórico.
 */
class ServicoFinanceiro
{
    /** Campos que, num lançamento pago, só mudam após estornar o pagamento. */
    private const TRAVADOS_QUANDO_PAGO = ['tipo', 'valor', 'data_pagamento', 'valor_pago', 'conta_financeira_id', 'forma_pagamento'];

    /**
     * Cria um lançamento ou um parcelamento (parcelas > 1): mesmo valor total, dividido em centavos
     * (a diferença de arredondamento vai para a 1ª parcela), vencimentos mensais e mesma competência.
     *
     * @return Collection<int, Lancamento>
     */
    public function criar(array $dados): Collection
    {
        $categoria = $this->categoriaValida($dados['categoria_id'], $dados['tipo']);
        $parcelas = (int) ($dados['parcelas'] ?? 1);
        $pago = ! empty($dados['data_pagamento']);

        if ($pago && $parcelas > 1) {
            throw new RegraNegocioException('Um parcelamento é lançado em aberto. Dê baixa em cada parcela quando for paga.', 'PARCELAMENTO_PAGO', 422, '/data_pagamento');
        }
        if ($pago) {
            $this->validarPagamento($dados['conta_financeira_id'] ?? null, $dados['data_pagamento']);
        }

        $base = [
            ...collect($dados)->except(['parcelas', 'data_pagamento', 'valor_pago', 'conta_financeira_id', 'forma_pagamento'])->all(),
            'grupo_custo' => $this->grupoCusto($categoria, $dados),
            'criado_por' => Auth::id(),
            'atualizado_por' => Auth::id(),
        ];
        $centavos = $this->emCentavos($dados['valor']);
        $porParcela = intdiv($centavos, $parcelas);
        $resto = $centavos - $porParcela * $parcelas;
        $grupo = $parcelas > 1 ? (string) Str::uuid() : null;
        $vencimento = Carbon::parse($dados['data_vencimento']);

        return DB::transaction(fn () => collect(range(1, $parcelas))->map(fn (int $i) => Lancamento::create([
            ...$base,
            'descricao' => $parcelas > 1 ? Str::limit($dados['descricao'], 150, '')." ({$i}/{$parcelas})" : $dados['descricao'],
            'valor' => $this->deCentavos($porParcela + ($i === 1 ? $resto : 0)),
            'data_vencimento' => $vencimento->copy()->addMonthsNoOverflow($i - 1)->toDateString(),
            'parcelamento_id' => $grupo,
            'parcela' => $parcelas > 1 ? $i : null,
            'total_parcelas' => $parcelas > 1 ? $parcelas : null,
            ...($pago ? [
                'situacao' => SituacaoLancamento::PAGO,
                'data_pagamento' => $dados['data_pagamento'],
                'valor_pago' => $dados['valor_pago'] ?? $dados['valor'],
                'conta_financeira_id' => $dados['conta_financeira_id'],
                'forma_pagamento' => $dados['forma_pagamento'] ?? null,
            ] : []),
        ])));
    }

    public function atualizar(Lancamento $lancamento, array $dados): Lancamento
    {
        if ($lancamento->situacao === SituacaoLancamento::CANCELADO) {
            throw new RegraNegocioException('Lançamento cancelado não pode ser alterado.', 'LANCAMENTO_CANCELADO', 409);
        }

        if ($lancamento->situacao === SituacaoLancamento::PAGO) {
            $lancamento->fill(collect($dados)->only(self::TRAVADOS_QUANDO_PAGO)->all());
            if ($lancamento->isDirty(self::TRAVADOS_QUANDO_PAGO)) {
                throw new RegraNegocioException(
                    'Este lançamento já foi pago. Para alterar valor, tipo ou dados do pagamento, estorne o pagamento primeiro.',
                    'LANCAMENTO_PAGO', 409,
                );
            }
        }
        $dados = collect($dados)->except(['data_pagamento', 'valor_pago', 'conta_financeira_id', 'forma_pagamento'])->all();

        $tipo = $dados['tipo'] ?? $lancamento->tipo->value;
        $categoriaId = $dados['categoria_id'] ?? $lancamento->categoria_id;
        if (isset($dados['tipo']) || isset($dados['categoria_id'])) {
            $categoria = $this->categoriaValida($categoriaId, $tipo, $categoriaId === $lancamento->categoria_id);
            // Trocou a categoria: o grupo de custo acompanha, salvo se veio explícito.
            if ($categoriaId !== $lancamento->categoria_id || $tipo !== $lancamento->tipo->value) {
                $dados['grupo_custo'] = $this->grupoCusto($categoria, $dados);
            }
        }
        if ($tipo === TipoLancamento::RECEITA->value) {
            $dados['grupo_custo'] = null;
        }

        $lancamento->update([...$dados, 'atualizado_por' => Auth::id()]);

        return $lancamento;
    }

    /** Baixa (pagamento/recebimento). valor_pago pode diferir do valor por juros, multa ou desconto. */
    public function baixar(Lancamento $lancamento, array $dados): Lancamento
    {
        if ($lancamento->situacao !== SituacaoLancamento::PENDENTE) {
            throw new RegraNegocioException(
                $lancamento->situacao === SituacaoLancamento::PAGO ? 'Este lançamento já foi pago.' : 'Lançamento cancelado não pode receber baixa.',
                'BAIXA_INVALIDA', 409,
            );
        }
        $this->validarPagamento($dados['conta_financeira_id'], $dados['data_pagamento']);

        $lancamento->acaoAuditoria = 'BAIXA';
        $lancamento->update([
            'situacao' => SituacaoLancamento::PAGO,
            'data_pagamento' => $dados['data_pagamento'],
            'valor_pago' => $dados['valor_pago'] ?? $lancamento->valor,
            'conta_financeira_id' => $dados['conta_financeira_id'],
            'forma_pagamento' => $dados['forma_pagamento'] ?? null,
            'atualizado_por' => Auth::id(),
        ]);

        return $lancamento;
    }

    /** @return Collection<int, Lancamento> */
    public function baixarEmLote(array $ids, array $dados): Collection
    {
        return DB::transaction(fn () => Lancamento::whereKey($ids)->orderBy('data_vencimento')->lockForUpdate()->get()
            ->each(fn (Lancamento $l) => $this->baixar($l, $dados)));
    }

    /** Desfaz a baixa: volta a PENDENTE. O pagamento desfeito fica registrado no histórico. */
    public function estornar(Lancamento $lancamento, ?string $motivo): Lancamento
    {
        if ($lancamento->situacao !== SituacaoLancamento::PAGO) {
            throw new RegraNegocioException('Só é possível estornar um lançamento pago.', 'ESTORNO_INVALIDO', 409);
        }

        $lancamento->acaoAuditoria = 'ESTORNO';
        $lancamento->extraAuditoria = ['motivo' => $motivo];
        $lancamento->update([
            'situacao' => SituacaoLancamento::PENDENTE,
            'data_pagamento' => null,
            'valor_pago' => null,
            'conta_financeira_id' => null,
            'forma_pagamento' => null,
            'atualizado_por' => Auth::id(),
        ]);

        return $lancamento;
    }

    /**
     * Cancela o lançamento (sai de todos os totais, mas continua consultável).
     * Com $parcelasSeguintes, cancela também as parcelas seguintes ainda em aberto do mesmo parcelamento.
     *
     * @return Collection<int, Lancamento>
     */
    public function cancelar(Lancamento $lancamento, string $motivo, bool $parcelasSeguintes = false): Collection
    {
        if ($lancamento->situacao === SituacaoLancamento::PAGO) {
            throw new RegraNegocioException('Este lançamento já foi pago. Estorne o pagamento antes de cancelar.', 'LANCAMENTO_PAGO', 409);
        }
        if ($lancamento->situacao === SituacaoLancamento::CANCELADO) {
            throw new RegraNegocioException('Este lançamento já está cancelado.', 'LANCAMENTO_CANCELADO', 409);
        }

        $alvos = collect([$lancamento]);
        if ($parcelasSeguintes && $lancamento->parcelamento_id) {
            $alvos = $alvos->merge(Lancamento::where('parcelamento_id', $lancamento->parcelamento_id)
                ->where('parcela', '>', $lancamento->parcela)->emAberto()->get());
        }

        return DB::transaction(fn () => $alvos->each(function (Lancamento $l) use ($motivo) {
            $l->acaoAuditoria = 'CANCELAMENTO';
            $l->update(['situacao' => SituacaoLancamento::CANCELADO, 'motivo_cancelamento' => $motivo, 'atualizado_por' => Auth::id()]);
        }));
    }

    /** Registra um aporte ou retirada de investidor: o dinheiro já entrou ou saiu da conta. */
    public function registrarMovimentoCapital(array $dados): MovimentoCapital
    {
        $investidor = Pessoa::findOrFail($dados['pessoa_id']);
        if ($investidor->tipo !== TipoPessoa::INVESTIDOR) {
            throw new RegraNegocioException("\"{$investidor->nome}\" não está cadastrado como investidor.", 'PESSOA_NAO_INVESTIDORA', 422, '/pessoa_id');
        }
        if (! $investidor->ativo) {
            throw new RegraNegocioException("O investidor \"{$investidor->nome}\" está inativo.", 'INVESTIDOR_INATIVO', 422, '/pessoa_id');
        }
        $this->validarPagamento($dados['conta_financeira_id'], $dados['data_movimento'], '/data_movimento');

        return MovimentoCapital::create([...$dados, 'criado_por' => Auth::id(), 'atualizado_por' => Auth::id()]);
    }

    /** Cancela o aporte ou retirada: sai do saldo e dos totais, mas continua consultável. */
    public function cancelarMovimentoCapital(MovimentoCapital $movimento, string $motivo): MovimentoCapital
    {
        if ($movimento->cancelado_em) {
            throw new RegraNegocioException('Este movimento já está cancelado.', 'MOVIMENTO_CANCELADO', 409);
        }

        $movimento->acaoAuditoria = 'CANCELAMENTO';
        $movimento->update(['cancelado_em' => now(), 'motivo_cancelamento' => $motivo, 'atualizado_por' => Auth::id()]);

        return $movimento;
    }

    private function categoriaValida(int $id, string $tipo, bool $aceitaInativa = false): CategoriaFinanceira
    {
        $categoria = CategoriaFinanceira::findOrFail($id);
        if ($categoria->tipo->value !== $tipo) {
            throw new RegraNegocioException(
                "A categoria \"{$categoria->nome}\" é de ".mb_strtolower($categoria->tipo->label()).'. Escolha uma categoria de '.mb_strtolower(TipoLancamento::from($tipo)->label()).'.',
                'CATEGORIA_INCOMPATIVEL', 422, '/categoria_id',
            );
        }
        if (! $categoria->ativa && ! $aceitaInativa) {
            throw new RegraNegocioException("A categoria \"{$categoria->nome}\" está desativada.", 'CATEGORIA_INATIVA', 422, '/categoria_id');
        }

        return $categoria;
    }

    private function grupoCusto(CategoriaFinanceira $categoria, array $dados): ?string
    {
        if ($categoria->tipo === TipoLancamento::RECEITA) {
            return null;
        }

        return $dados['grupo_custo'] ?? $categoria->grupo_custo?->value;
    }

    private function validarPagamento(?int $contaId, string $data, string $campoData = '/data_pagamento'): void
    {
        $conta = $contaId ? ContaFinanceira::find($contaId) : null;
        if (! $conta) {
            throw new RegraNegocioException('Informe a conta (caixa ou banco) do pagamento.', 'CONTA_OBRIGATORIA', 422, '/conta_financeira_id');
        }
        if (! $conta->ativa) {
            throw new RegraNegocioException("A conta \"{$conta->nome}\" está desativada.", 'CONTA_INATIVA', 422, '/conta_financeira_id');
        }
        if (Carbon::parse($data)->lt($conta->data_saldo_inicial)) {
            throw new RegraNegocioException(
                "A conta \"{$conta->nome}\" tem saldo inicial em {$conta->data_saldo_inicial->format('d/m/Y')}. A movimentação não pode ser anterior a essa data.",
                'PAGAMENTO_ANTES_DO_SALDO_INICIAL', 422, $campoData,
            );
        }
    }

    private function emCentavos(string|float|int $valor): int
    {
        return (int) bcmul((string) $valor, '100', 0);
    }

    private function deCentavos(int $centavos): string
    {
        return bcdiv((string) $centavos, '100', 2);
    }
}

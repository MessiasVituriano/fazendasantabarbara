<?php

namespace App\Services;

use App\Enums\TipoMovimentoEstoque;
use App\Exceptions\RegraNegocioException;
use App\Models\ItemEstoque;
use App\Models\MovimentoEstoque;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Movimentação do estoque. O saldo do item é mantido aqui, dentro de uma transação com o item
 * travado, para que duas saídas simultâneas não levem o saldo abaixo de zero.
 * As contas são feitas em milésimos inteiros (a precisão da coluna) para não acumular erro de ponto flutuante.
 */
class ServicoEstoque
{
    /**
     * @param  array{item_estoque_id: int, tipo: string, data_movimento: string, quantidade?: numeric-string|float|null, saldo_contado?: numeric-string|float|null, valor_unitario?: numeric-string|float|null, observacao?: string|null, uuid?: string|null}  $dados
     */
    public function registrar(array $dados, ?User $usuario): MovimentoEstoque
    {
        return DB::transaction(function () use ($dados, $usuario) {
            $item = ItemEstoque::lockForUpdate()->findOrFail($dados['item_estoque_id']);
            $tipo = TipoMovimentoEstoque::from($dados['tipo']);
            $saldo = self::milesimos($item->saldo);

            if (! $item->ativo) {
                throw new RegraNegocioException("{$item->nome} está desativado. Reative o item para movimentá-lo.", 'ITEM_INATIVO', ponteiro: '/item_estoque_id');
            }

            $quantidade = match ($tipo) {
                TipoMovimentoEstoque::AJUSTE => self::milesimos($dados['saldo_contado']) - $saldo,
                default => self::milesimos($dados['quantidade']),
            };

            if ($tipo === TipoMovimentoEstoque::AJUSTE && $quantidade === 0) {
                throw new RegraNegocioException('O saldo contado é igual ao saldo atual: não há o que ajustar.', 'AJUSTE_SEM_DIFERENCA', ponteiro: '/saldo_contado');
            }
            if ($tipo === TipoMovimentoEstoque::SAIDA && $quantidade > $saldo) {
                $sigla = $item->unidade->sigla();
                throw new RegraNegocioException(
                    "Estoque insuficiente de {$item->nome}: há ".self::texto($saldo)." {$sigla}.",
                    'ESTOQUE_INSUFICIENTE',
                    ponteiro: '/quantidade',
                );
            }

            $movimento = MovimentoEstoque::create([
                'uuid' => $dados['uuid'] ?? null,
                'item_estoque_id' => $item->id,
                'tipo' => $tipo,
                'data_movimento' => $dados['data_movimento'],
                'quantidade' => $quantidade / 1000,
                'valor_unitario' => $tipo === TipoMovimentoEstoque::ENTRADA ? ($dados['valor_unitario'] ?? null) : null,
                'observacao' => $dados['observacao'] ?? null,
                'user_id' => $usuario?->id,
            ]);

            $item->saldo = ($saldo + ($tipo === TipoMovimentoEstoque::SAIDA ? -$quantidade : $quantidade)) / 1000;
            if ($movimento->valor_unitario !== null) {
                $item->custo_unitario = $movimento->valor_unitario;
            }
            $item->save();

            return $movimento;
        });
    }

    /** Desfaz o efeito no saldo; o movimento continua na lista como cancelado. */
    public function cancelar(MovimentoEstoque $movimento, string $motivo, ?User $usuario): MovimentoEstoque
    {
        return DB::transaction(function () use ($movimento, $motivo, $usuario) {
            $movimento = MovimentoEstoque::lockForUpdate()->findOrFail($movimento->id);
            if ($movimento->cancelado_em) {
                throw new RegraNegocioException('Este movimento já foi cancelado.', 'JA_CANCELADO');
            }

            $item = ItemEstoque::lockForUpdate()->findOrFail($movimento->item_estoque_id);
            $novoSaldo = self::milesimos($item->saldo) - self::milesimos($movimento->efeitoNoSaldo());
            if ($novoSaldo < 0) {
                throw new RegraNegocioException(
                    "Cancelar deixaria {$item->nome} com saldo negativo: parte desta entrada já foi usada. Cancele antes as saídas ou faça um ajuste de inventário.",
                    'ESTOQUE_INSUFICIENTE',
                );
            }

            $movimento->forceFill(['cancelado_em' => now(), 'cancelado_por' => $usuario?->id, 'motivo_cancelamento' => $motivo])->save();

            $item->saldo = $novoSaldo / 1000;
            if ($movimento->valor_unitario !== null) {
                // O custo volta a ser o da última entrada com preço que continua valendo.
                $item->custo_unitario = $item->movimentos()->validos()
                    ->where('tipo', TipoMovimentoEstoque::ENTRADA)->whereNotNull('valor_unitario')
                    ->orderByDesc('data_movimento')->orderByDesc('id')
                    ->value('valor_unitario');
            }
            $item->save();

            return $movimento;
        });
    }

    private static function milesimos(mixed $valor): int
    {
        return (int) round((float) $valor * 1000);
    }

    private static function texto(int $milesimos): string
    {
        return rtrim(rtrim(number_format($milesimos / 1000, 3, ',', '.'), '0'), ',');
    }
}

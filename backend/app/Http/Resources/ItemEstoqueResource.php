<?php

namespace App\Http\Resources;

use App\Models\ItemEstoque;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ItemEstoque — custos só aparecem para o gestor. */
class ItemEstoqueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $gestor = (bool) $request->user()?->ehGestor();

        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'tipo' => $this->tipo->value,
            'tipo_label' => $this->tipo->label(),
            'unidade' => $this->unidade->value,
            'unidade_sigla' => $this->unidade->sigla(),
            'saldo' => (float) $this->saldo,
            'estoque_minimo' => (float) $this->estoque_minimo,
            'abaixo_minimo' => $this->abaixoDoMinimo(),
            'data_validade' => $this->data_validade?->toDateString(),
            'situacao_validade' => $this->situacaoValidade(),
            'localizacao' => $this->localizacao,
            'observacoes' => $this->observacoes,
            'ativo' => $this->ativo,
            $this->mergeWhen($gestor, fn () => [
                'custo_unitario' => $this->custo_unitario === null ? null : (float) $this->custo_unitario,
                'valor_estoque' => $this->custo_unitario === null ? null : round((float) $this->saldo * (float) $this->custo_unitario, 2),
            ]),
        ];
    }
}

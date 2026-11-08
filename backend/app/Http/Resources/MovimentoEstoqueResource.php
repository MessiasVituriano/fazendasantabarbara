<?php

namespace App\Http\Resources;

use App\Models\MovimentoEstoque;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MovimentoEstoque */
class MovimentoEstoqueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $gestor = (bool) $request->user()?->ehGestor();

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'item_estoque_id' => $this->item_estoque_id,
            'item' => $this->whenLoaded('item', fn () => [
                'id' => $this->item->id,
                'nome' => $this->item->nome,
                'unidade_sigla' => $this->item->unidade->sigla(),
            ]),
            'tipo' => $this->tipo->value,
            'tipo_label' => $this->tipo->label(),
            'data_movimento' => $this->data_movimento->toDateString(),
            'quantidade' => (float) $this->quantidade,
            'efeito_no_saldo' => $this->efeitoNoSaldo(),
            'observacao' => $this->observacao,
            'usuario' => $this->whenLoaded('usuario', fn () => $this->usuario?->name),
            'cancelado' => $this->cancelado_em !== null,
            'motivo_cancelamento' => $this->motivo_cancelamento,
            $this->mergeWhen($gestor, fn () => [
                'valor_unitario' => $this->valor_unitario === null ? null : (float) $this->valor_unitario,
                'valor_total' => $this->valor_unitario === null ? null : round((float) $this->quantidade * (float) $this->valor_unitario, 2),
            ]),
        ];
    }
}

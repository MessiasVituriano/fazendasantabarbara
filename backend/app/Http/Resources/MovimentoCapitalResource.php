<?php

namespace App\Http\Resources;

use App\Models\MovimentoCapital;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MovimentoCapital */
class MovimentoCapitalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo->value,
            'tipo_label' => $this->tipo->label(),
            'pessoa_id' => $this->pessoa_id,
            'investidor' => $this->pessoa?->nome,
            'valor' => (float) $this->valor,
            'data_movimento' => $this->data_movimento->toDateString(),
            'conta_financeira_id' => $this->conta_financeira_id,
            'conta' => $this->conta?->nome,
            'forma_pagamento' => $this->forma_pagamento?->value,
            'forma_pagamento_label' => $this->forma_pagamento?->label(),
            'documento' => $this->documento,
            'observacoes' => $this->observacoes,
            'cancelado' => $this->cancelado_em !== null,
            'motivo_cancelamento' => $this->motivo_cancelamento,
        ];
    }
}

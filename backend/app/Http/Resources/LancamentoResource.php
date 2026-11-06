<?php

namespace App\Http\Resources;

use App\Models\Lancamento;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Lancamento */
class LancamentoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $situacao = $this->situacaoExibida();

        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'tipo_label' => $this->tipo->label(),
            'descricao' => $this->descricao,
            'categoria_id' => $this->categoria_id,
            'categoria' => $this->whenLoaded('categoria', fn () => $this->categoria?->nome),
            'grupo_custo' => $this->grupo_custo,
            'grupo_custo_label' => $this->grupo_custo?->label(),
            'valor' => $this->valor,
            'data_competencia' => $this->data_competencia?->toDateString(),
            'data_vencimento' => $this->data_vencimento?->toDateString(),
            'situacao' => $situacao,
            'situacao_label' => $situacao === 'VENCIDO' ? 'Atrasado' : $this->situacao->label(),
            'data_pagamento' => $this->data_pagamento?->toDateString(),
            'valor_pago' => $this->valor_pago,
            'conta_financeira_id' => $this->conta_financeira_id,
            'conta' => $this->whenLoaded('conta', fn () => $this->conta?->nome),
            'forma_pagamento' => $this->forma_pagamento,
            'forma_pagamento_label' => $this->forma_pagamento?->label(),
            'pessoa_id' => $this->pessoa_id,
            'pessoa' => $this->whenLoaded('pessoa', fn () => $this->pessoa?->nome),
            'lote_id' => $this->lote_id,
            'lote' => $this->whenLoaded('lote', fn () => $this->lote?->nome),
            'documento' => $this->documento,
            'observacoes' => $this->observacoes,
            'parcelamento_id' => $this->parcelamento_id,
            'parcela' => $this->parcela,
            'total_parcelas' => $this->total_parcelas,
            'motivo_cancelamento' => $this->motivo_cancelamento,
            'anexos_count' => $this->whenCounted('anexos'),
            'anexos' => $this->whenLoaded('anexos'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

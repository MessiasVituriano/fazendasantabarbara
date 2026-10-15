<?php

namespace App\Http\Resources;

use App\Models\Pesagem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Pesagem */
class PesagemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'animal_id' => $this->animal_id,
            'peso_kg' => (float) $this->peso_kg,
            'data_pesagem' => $this->data_pesagem->toDateString(),
            'gmd_kg_dia' => $this->gmd(),
            'created_at' => $this->created_at,
        ];
    }
}

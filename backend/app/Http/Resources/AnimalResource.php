<?php

namespace App\Http\Resources;

use App\Models\Animal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin Animal */
class AnimalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $emCarencia = (bool) $this->em_carencia;

        return [
            'id' => $this->id,
            'brinco' => $this->brinco,
            'nome' => $this->nome,
            'sexo' => $this->sexo,
            'sexo_label' => $this->sexo->label(),
            'data_nascimento' => $this->data_nascimento?->toDateString(),
            'idade_dias' => (int) $this->data_nascimento?->diffInDays(Carbon::today()),
            'aptidao' => $this->aptidao,
            'aptidao_label' => $this->aptidao->label(),
            'categoria' => $this->categoria,
            'categoria_label' => $this->categoria->label(),
            'status_reprodutivo' => $this->status_reprodutivo,
            'status_reprodutivo_label' => $this->status_reprodutivo->label(),
            'status_geral' => $this->status_geral,
            'status_geral_label' => $this->status_geral->label(),
            'mae_id' => $this->mae_id,
            'mae' => $this->whenLoaded('mae', fn () => $this->mae ? [
                'id' => $this->mae->id,
                'brinco' => $this->mae->brinco,
                'nome' => $this->mae->nome,
            ] : null),
            // Foto principal (null = o front exibe a silhueta ilustrada).
            'foto' => $this->whenLoaded('fotoPrincipal', fn () => $this->fotoPrincipal ? [
                'id' => $this->fotoPrincipal->id,
                'url' => $this->fotoPrincipal->url,
                'miniatura_url' => $this->fotoPrincipal->miniatura_url,
            ] : null),
            'data_prevista_toque' => $this->data_prevista_toque?->toDateString(),
            'data_provavel_parto' => $this->data_provavel_parto?->toDateString(),
            'data_prevista_secagem' => $this->data_prevista_secagem?->toDateString(),
            'piquete_atual' => $this->whenLoaded('pastoreioAtual', fn () => $this->pastoreioAtual ? [
                'id' => $this->pastoreioAtual->piquete_id,
                'identificacao' => $this->pastoreioAtual->piquete?->identificacao,
                'desde' => $this->pastoreioAtual->data_entrada->toDateString(),
            ] : null),
            // RF04: presentes quando a consulta usa o scope comCarencia().
            $this->mergeWhen(array_key_exists('em_carencia', $this->resource->getAttributes()), fn () => [
                'em_carencia' => $emCarencia,
                'carencia_ate' => $this->carencia_ate ? Carbon::parse($this->carencia_ate)->toDateString() : null,
                'alerta' => $emCarencia ? [
                    'nivel' => 'CRITICO',
                    'mensagem' => 'Animal em período de carência sanitária até '
                        .Carbon::parse($this->carencia_ate)->format('d/m/Y')
                        .'. Leite e carne não podem ser comercializados.',
                ] : null,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

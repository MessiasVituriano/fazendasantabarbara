<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin User */
class UsuarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->name,
            'usuario' => $this->usuario,
            'email' => $this->email,
            'telefone' => $this->telefone,
            // Perfil e situação na fazenda ativa.
            'perfil' => $this->perfil,
            'perfil_label' => $this->perfil?->label(),
            'ativo' => $this->ativo && (bool) $this->vinculoNaFazendaAtual()?->ativo,
            // Último uso de qualquer sessão (token) do usuário.
            // (gravado no fuso da aplicação; enviado em ISO 8601 com fuso)
            'ultimo_acesso' => $this->whenHas('ultimo_acesso', fn ($v) => $v ? Carbon::parse($v)->toIso8601String() : null),
            'created_at' => $this->created_at,
        ];
    }
}

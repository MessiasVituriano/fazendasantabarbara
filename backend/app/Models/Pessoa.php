<?php

namespace App\Models;

use App\Enums\TipoPessoa;
use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Fornecedor e/ou cliente (comprador de gado, casa agropecuária, transportador...) ou investidor/sócio. */
class Pessoa extends Model
{
    use Auditavel, HasFactory;

    protected $table = 'pessoas';

    protected $fillable = ['nome', 'tipo', 'documento', 'telefone', 'observacoes', 'ativo'];

    protected $attributes = ['ativo' => true];

    protected function casts(): array
    {
        return ['tipo' => TipoPessoa::class, 'ativo' => 'boolean'];
    }

    public function setDocumentoAttribute(?string $valor): void
    {
        $limpo = $valor === null ? '' : preg_replace('/\D/', '', $valor);
        $this->attributes['documento'] = $limpo === '' ? null : $limpo;
    }

    public function setTelefoneAttribute(?string $valor): void
    {
        $limpo = $valor === null ? '' : preg_replace('/(?!^\+)\D/', '', trim($valor));
        $this->attributes['telefone'] = $limpo === '' ? null : $limpo;
    }
}

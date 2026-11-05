<?php

namespace App\Models;

use App\Enums\TipoProdutoSanitario;
use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoriaSanitaria extends Model
{
    use Auditavel;

    protected $table = 'categorias_sanitarias';

    protected $fillable = ['nome', 'tipo', 'dias_carencia_padrao', 'ativa'];

    protected $attributes = ['ativa' => true];

    protected function casts(): array
    {
        return [
            'tipo' => TipoProdutoSanitario::class,
            'dias_carencia_padrao' => 'integer',
            'ativa' => 'boolean',
        ];
    }

    public function produtos(): HasMany
    {
        return $this->hasMany(ProdutoSanitario::class);
    }

    public static function porChave(string $chave): self
    {
        return self::where('chave', $chave)->firstOrFail();
    }
}

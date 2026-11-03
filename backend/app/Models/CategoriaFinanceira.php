<?php

namespace App\Models;

use App\Enums\GrupoCusto;
use App\Enums\TipoLancamento;
use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoriaFinanceira extends Model
{
    use Auditavel;

    protected $table = 'categorias_financeiras';

    protected $fillable = ['nome', 'tipo', 'grupo_custo', 'ativa'];

    protected $attributes = ['ativa' => true];

    protected function casts(): array
    {
        return [
            'tipo' => TipoLancamento::class,
            'grupo_custo' => GrupoCusto::class,
            'ativa' => 'boolean',
        ];
    }

    public function lancamentos(): HasMany
    {
        return $this->hasMany(Lancamento::class, 'categoria_id');
    }

    public static function porChave(string $chave): self
    {
        return self::where('chave', $chave)->firstOrFail();
    }
}

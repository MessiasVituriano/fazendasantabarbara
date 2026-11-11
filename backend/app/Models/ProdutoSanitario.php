<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceAFazenda;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProdutoSanitario extends Model
{
    use Auditavel, PertenceAFazenda;

    protected $table = 'produtos_sanitarios';

    protected $fillable = ['categoria_sanitaria_id', 'nome', 'dias_carencia', 'ativo'];

    protected $attributes = ['ativo' => true];

    protected function casts(): array
    {
        return [
            'dias_carencia' => 'integer',
            'ativo' => 'boolean',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaSanitaria::class, 'categoria_sanitaria_id');
    }
}

<?php

namespace App\Models;

use App\Casts\Data;
use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lote extends Model
{
    use Auditavel, HasFactory;

    protected $table = 'lotes';

    protected $fillable = ['nome', 'data_formacao', 'categoria', 'origem', 'observacoes', 'data_encerramento'];

    protected function casts(): array
    {
        return ['data_formacao' => Data::class, 'data_encerramento' => Data::class];
    }

    public function lancamentos(): HasMany
    {
        return $this->hasMany(Lancamento::class);
    }

    public function scopeAtivos(Builder $query): void
    {
        $query->whereNull('data_encerramento');
    }
}

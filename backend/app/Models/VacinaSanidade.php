<?php

namespace App\Models;

use App\Casts\Data;
use App\Enums\TipoProdutoSanitario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class VacinaSanidade extends Model
{
    use HasFactory;

    protected $table = 'vacinas_sanidade';

    protected $fillable = ['uuid', 'animal_id', 'tipo', 'categoria_sanitaria_id', 'produto_sanitario_id', 'nome_vacina', 'data_aplicacao', 'dias_carencia_leite_carne'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoProdutoSanitario::class,
            'data_aplicacao' => Data::class,
            'data_fim_carencia' => Data::class,
            'dias_carencia_leite_carne' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // data_fim_carencia é derivada: data_aplicacao + dias_carencia_leite_carne.
        static::saving(function (self $vacina) {
            $vacina->data_fim_carencia = Carbon::parse($vacina->data_aplicacao)
                ->addDays((int) $vacina->dias_carencia_leite_carne);
        });
    }

    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaSanitaria::class, 'categoria_sanitaria_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(ProdutoSanitario::class, 'produto_sanitario_id');
    }

    /** Carência vigente: data_aplicacao + dias_carencia >= hoje (RF04). */
    public function scopeVigentes(Builder $query): void
    {
        $query->where('data_fim_carencia', '>=', Carbon::today()->toDateString());
    }
}

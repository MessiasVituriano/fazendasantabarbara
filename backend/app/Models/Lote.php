<?php

namespace App\Models;

use App\Casts\Data;
use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    /** Passagens de animais pelo lote (histórico completo). */
    public function historicoAnimais(): HasMany
    {
        return $this->hasMany(AnimalLote::class);
    }

    /** Animais que estão no lote agora. */
    public function ocupacoesAtuais(): HasMany
    {
        return $this->hasMany(AnimalLote::class)->whereNull('data_saida');
    }

    public function metas(): HasMany
    {
        return $this->hasMany(MetaEngorda::class);
    }

    /** Meta atual: a mais recente não cancelada. */
    public function metaAtual(): HasOne
    {
        return $this->hasOne(MetaEngorda::class)->vigentes()->latestOfMany();
    }

    public function movimentosEstoque(): HasMany
    {
        return $this->hasMany(MovimentoEstoque::class);
    }

    public function scopeAtivos(Builder $query): void
    {
        $query->whereNull('data_encerramento');
    }
}

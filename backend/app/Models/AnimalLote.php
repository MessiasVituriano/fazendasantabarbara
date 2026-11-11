<?php

namespace App\Models;

use App\Casts\Data;
use App\Models\Concerns\PertenceAFazenda;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Passagem de um animal por um lote. data_saida nula = está no lote agora. */
class AnimalLote extends Model
{
    use PertenceAFazenda;

    protected $table = 'animais_lote';

    protected $fillable = ['lote_id', 'animal_id', 'data_entrada', 'data_saida'];

    protected function casts(): array
    {
        return ['data_entrada' => Data::class, 'data_saida' => Data::class];
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }
}

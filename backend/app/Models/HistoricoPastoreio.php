<?php

namespace App\Models;

use App\Casts\Data;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricoPastoreio extends Model
{
    protected $table = 'historico_pastoreio';

    protected $fillable = ['piquete_id', 'animal_id', 'data_entrada', 'data_saida'];

    protected function casts(): array
    {
        return [
            'data_entrada' => Data::class,
            'data_saida' => Data::class,
        ];
    }

    public function piquete(): BelongsTo
    {
        return $this->belongsTo(Piquete::class);
    }

    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }
}

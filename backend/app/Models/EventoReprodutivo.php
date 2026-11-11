<?php

namespace App\Models;

use App\Casts\Data;
use App\Enums\TipoEventoReprodutivo;
use App\Models\Concerns\PertenceAFazenda;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventoReprodutivo extends Model
{
    use HasFactory, PertenceAFazenda;

    protected $table = 'eventos_reprodutivos';

    protected $fillable = ['uuid', 'animal_id', 'tipo_evento', 'data_evento', 'observacoes', 'bezerro_id'];

    protected function casts(): array
    {
        return [
            'tipo_evento' => TipoEventoReprodutivo::class,
            'data_evento' => Data::class,
        ];
    }

    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }

    public function bezerro(): BelongsTo
    {
        return $this->belongsTo(Animal::class, 'bezerro_id');
    }
}

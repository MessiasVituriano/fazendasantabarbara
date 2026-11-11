<?php

namespace App\Models;

use App\Casts\Data;
use App\Enums\Periodo;
use App\Models\Concerns\PertenceAFazenda;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProducaoLeite extends Model
{
    use HasFactory, PertenceAFazenda;

    protected $table = 'producoes_leite';

    protected $fillable = ['uuid', 'animal_id', 'data_pesagem', 'periodo', 'quantidade_litros'];

    protected function casts(): array
    {
        return [
            'periodo' => Periodo::class,
            'data_pesagem' => Data::class,
            'quantidade_litros' => 'decimal:2',
        ];
    }

    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }
}

<?php

namespace App\Models;

use App\Enums\StatusPiquete;
use App\Models\Concerns\PertenceAFazenda;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Piquete extends Model
{
    use HasFactory, PertenceAFazenda;

    protected $table = 'piquetes';

    protected $fillable = ['identificacao', 'hectares', 'status'];

    protected $attributes = [
        'status' => 'DESCANSO',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusPiquete::class,
            'hectares' => 'decimal:2',
        ];
    }

    public function historico(): HasMany
    {
        return $this->hasMany(HistoricoPastoreio::class);
    }

    /** Ocupações em aberto (animais que estão no piquete agora). */
    public function ocupacoesAtuais(): HasMany
    {
        return $this->historico()->whereNull('data_saida');
    }
}

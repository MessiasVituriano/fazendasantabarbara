<?php

namespace App\Models;

use App\Casts\Data;
use App\Models\Concerns\PertenceAFazenda;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Pesagem extends Model
{
    use HasFactory, PertenceAFazenda;

    protected $table = 'pesagens';

    protected $fillable = ['uuid', 'animal_id', 'peso_kg', 'data_pesagem'];

    protected function casts(): array
    {
        return [
            'data_pesagem' => Data::class,
            'peso_kg' => 'decimal:2',
        ];
    }

    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }

    /**
     * GMD (Ganho Médio Diário) em kg/dia entre duas pesagens.
     * Retorna null quando não há base de comparação válida.
     */
    public static function calcularGmd(
        float $pesoAtual,
        CarbonInterface|string $dataAtual,
        ?float $pesoAnterior,
        CarbonInterface|string|null $dataAnterior,
    ): ?float {
        if ($pesoAnterior === null || $dataAnterior === null) {
            return null;
        }

        $dias = (int) Carbon::parse($dataAnterior)->startOfDay()->diffInDays(Carbon::parse($dataAtual)->startOfDay());

        return $dias > 0 ? round(($pesoAtual - $pesoAnterior) / $dias, 3) : null;
    }

    /**
     * Anexa peso/data da pesagem anterior do mesmo animal via window function (LAG),
     * permitindo calcular o GMD de uma lista inteira em uma única query.
     * Funciona em PostgreSQL e SQLite >= 3.25. Filtros de data aplicados na mesma
     * query restringem a janela: filtre por animal, não por período.
     */
    public function scopeComGmd(Builder $query): void
    {
        $janela = 'OVER (PARTITION BY animal_id ORDER BY data_pesagem)';

        $query->select('pesagens.*')
            ->selectRaw("LAG(peso_kg) {$janela} AS peso_anterior")
            ->selectRaw("LAG(data_pesagem) {$janela} AS data_pesagem_anterior");
    }

    /** Pesagem imediatamente anterior deste animal. */
    public function anterior(): ?self
    {
        return self::where('animal_id', $this->animal_id)
            ->where('data_pesagem', '<', $this->data_pesagem->toDateString())
            ->orderByDesc('data_pesagem')
            ->first();
    }

    /** GMD desta pesagem em relação à anterior (usa os dados do scope comGmd quando presentes). */
    public function gmd(): ?float
    {
        if (array_key_exists('peso_anterior', $this->attributes)) {
            $pesoAnterior = $this->attributes['peso_anterior'];
            $dataAnterior = $this->attributes['data_pesagem_anterior'];
        } else {
            $anterior = $this->anterior();
            $pesoAnterior = $anterior?->peso_kg;
            $dataAnterior = $anterior?->data_pesagem;
        }

        return self::calcularGmd(
            (float) $this->peso_kg,
            $this->data_pesagem,
            $pesoAnterior === null ? null : (float) $pesoAnterior,
            $dataAnterior,
        );
    }
}

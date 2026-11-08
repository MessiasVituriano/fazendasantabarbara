<?php

namespace App\Models;

use App\Casts\Data;
use App\Enums\TipoItemEstoque;
use App\Enums\UnidadeEstoque;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** Material guardado na fazenda (medicamento, vacina, ração...). O saldo só muda por movimentos. */
class ItemEstoque extends Model
{
    use HasFactory;

    protected $table = 'itens_estoque';

    protected $fillable = ['nome', 'tipo', 'unidade', 'estoque_minimo', 'data_validade', 'localizacao', 'observacoes', 'ativo'];

    protected $attributes = ['ativo' => true, 'saldo' => 0, 'estoque_minimo' => 0];

    protected function casts(): array
    {
        return [
            'tipo' => TipoItemEstoque::class,
            'unidade' => UnidadeEstoque::class,
            'saldo' => 'decimal:3',
            'estoque_minimo' => 'decimal:3',
            'custo_unitario' => 'decimal:4',
            'data_validade' => Data::class,
            'ativo' => 'boolean',
        ];
    }

    public function movimentos(): HasMany
    {
        return $this->hasMany(MovimentoEstoque::class);
    }

    public function abaixoDoMinimo(): bool
    {
        return (float) $this->estoque_minimo > 0 && (float) $this->saldo <= (float) $this->estoque_minimo;
    }

    /** null = sem validade informada; 'VENCIDO' ou 'VENCE_EM_BREVE' (dentro do prazo de aviso). */
    public function situacaoValidade(): ?string
    {
        if (! $this->data_validade) {
            return null;
        }
        $validade = Carbon::parse($this->data_validade);
        if ($validade->lt(Carbon::today())) {
            return 'VENCIDO';
        }

        return $validade->lte(Carbon::today()->addDays(config('pecuaria.estoque.dias_aviso_validade'))) ? 'VENCE_EM_BREVE' : null;
    }
}

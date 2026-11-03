<?php

namespace App\Models;

use App\Casts\Data;
use App\Enums\FormaPagamento;
use App\Enums\GrupoCusto;
use App\Enums\SituacaoLancamento;
use App\Enums\TipoLancamento;
use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Lançamento financeiro (receita ou despesa).
 *
 * Três datas, três significados:
 *  - competência: quando o fato econômico aconteceu (base do demonstrativo de resultado e dos custos do lote);
 *  - vencimento:  quando deve ser pago (base de contas a pagar/receber e da projeção de caixa);
 *  - pagamento:   quando o dinheiro de fato entrou/saiu (base do fluxo de caixa e do saldo).
 * Cancelados nunca entram em totais. Nada é excluído: cancela-se, e a trilha fica em historico_alteracoes.
 */
class Lancamento extends Model
{
    use Auditavel, HasFactory;

    protected $table = 'lancamentos';

    protected $fillable = [
        'tipo', 'descricao', 'categoria_id', 'grupo_custo', 'valor',
        'data_competencia', 'data_vencimento', 'situacao', 'data_pagamento', 'valor_pago',
        'conta_financeira_id', 'forma_pagamento', 'pessoa_id', 'lote_id', 'documento', 'observacoes',
        'parcelamento_id', 'parcela', 'total_parcelas', 'motivo_cancelamento', 'criado_por', 'atualizado_por',
    ];

    protected $attributes = ['situacao' => 'PENDENTE'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoLancamento::class,
            'situacao' => SituacaoLancamento::class,
            'grupo_custo' => GrupoCusto::class,
            'forma_pagamento' => FormaPagamento::class,
            'valor' => 'decimal:2',
            'valor_pago' => 'decimal:2',
            'data_competencia' => Data::class,
            'data_vencimento' => Data::class,
            'data_pagamento' => Data::class,
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaFinanceira::class, 'categoria_id');
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_financeira_id');
    }

    public function pessoa(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class);
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function anexos(): HasMany
    {
        return $this->hasMany(AnexoLancamento::class)->latest('id');
    }

    /** PENDENTE com vencimento já passado aparece como VENCIDO (calculado, nunca gravado). */
    public function situacaoExibida(): string
    {
        if ($this->situacao === SituacaoLancamento::PENDENTE && $this->data_vencimento->lt(Carbon::today())) {
            return 'VENCIDO';
        }

        return $this->situacao->value;
    }

    public function scopeValidos(Builder $query): void
    {
        $query->where('situacao', '!=', SituacaoLancamento::CANCELADO);
    }

    public function scopePagos(Builder $query): void
    {
        $query->where('situacao', SituacaoLancamento::PAGO);
    }

    public function scopeEmAberto(Builder $query): void
    {
        $query->where('situacao', SituacaoLancamento::PENDENTE);
    }

    /** Filtra pelas situações de tela: PENDENTE (a vencer), VENCIDO, PAGO, CANCELADO. */
    public function scopeNaSituacao(Builder $query, array $situacoes): void
    {
        $hoje = Carbon::today()->toDateString();
        $query->where(function (Builder $q) use ($situacoes, $hoje) {
            foreach ($situacoes as $s) {
                match ($s) {
                    'VENCIDO' => $q->orWhere(fn ($q) => $q->where('situacao', 'PENDENTE')->where('data_vencimento', '<', $hoje)),
                    'PENDENTE' => $q->orWhere(fn ($q) => $q->where('situacao', 'PENDENTE')->where('data_vencimento', '>=', $hoje)),
                    'EM_ABERTO' => $q->orWhere('situacao', 'PENDENTE'),
                    default => $q->orWhere('situacao', $s),
                };
            }
        });
    }
}

<?php

namespace App\Models;

use App\Casts\Data;
use App\Enums\Aptidao;
use App\Enums\Categoria;
use App\Enums\Sexo;
use App\Enums\StatusGeral;
use App\Enums\StatusReprodutivo;
use App\Models\Concerns\PertenceAFazenda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Animal extends Model
{
    use HasFactory, PertenceAFazenda;

    protected $table = 'animais';

    protected $fillable = [
        'brinco', 'nome', 'sexo', 'data_nascimento', 'aptidao', 'categoria',
        'status_reprodutivo', 'status_geral', 'mae_id',
        'data_prevista_toque', 'data_provavel_parto', 'data_prevista_secagem',
    ];

    protected $attributes = [
        'status_reprodutivo' => 'APTA',
        'status_geral' => 'ATIVO',
    ];

    protected function casts(): array
    {
        return [
            'sexo' => Sexo::class,
            'aptidao' => Aptidao::class,
            'categoria' => Categoria::class,
            'status_reprodutivo' => StatusReprodutivo::class,
            'status_geral' => StatusGeral::class,
            'data_nascimento' => Data::class,
            'data_prevista_toque' => Data::class,
            'data_provavel_parto' => Data::class,
            'data_prevista_secagem' => Data::class,
        ];
    }

    public function mae(): BelongsTo
    {
        return $this->belongsTo(Animal::class, 'mae_id');
    }

    public function filhos(): HasMany
    {
        return $this->hasMany(Animal::class, 'mae_id');
    }

    public function producoesLeite(): HasMany
    {
        return $this->hasMany(ProducaoLeite::class);
    }

    public function pesagens(): HasMany
    {
        return $this->hasMany(Pesagem::class);
    }

    public function eventosReprodutivos(): HasMany
    {
        return $this->hasMany(EventoReprodutivo::class);
    }

    public function vacinas(): HasMany
    {
        return $this->hasMany(VacinaSanidade::class);
    }

    public function fotos(): HasMany
    {
        return $this->hasMany(FotoAnimal::class)->orderByDesc('principal')->orderByDesc('data_registro')->orderByDesc('id');
    }

    public function fotoPrincipal(): HasOne
    {
        return $this->hasOne(FotoAnimal::class)->where('principal', true);
    }

    public function historicoPastoreio(): HasMany
    {
        return $this->hasMany(HistoricoPastoreio::class);
    }

    public function pastoreioAtual(): HasOne
    {
        return $this->hasOne(HistoricoPastoreio::class)->whereNull('data_saida')->latestOfMany('data_entrada');
    }

    public function historicoLotes(): HasMany
    {
        return $this->hasMany(AnimalLote::class);
    }

    public function loteAtual(): HasOne
    {
        return $this->hasOne(AnimalLote::class)->whereNull('data_saida')->latestOfMany('data_entrada');
    }

    protected static function booted(): void
    {
        // O cascade do banco apagaria as linhas, mas não os arquivos: exclui pelas models.
        static::deleting(fn (self $animal) => $animal->fotos()->get()->each->delete());
    }

    public function scopeAtivos(Builder $query): void
    {
        $query->where('status_geral', StatusGeral::ATIVO);
    }

    /** Animais com ao menos uma vacina cuja carência ainda não terminou (RF04). */
    public function scopeEmCarencia(Builder $query, bool $emCarencia = true): void
    {
        $method = $emCarencia ? 'whereHas' : 'whereDoesntHave';
        $query->{$method}('vacinas', fn (Builder $q) => $q->vigentes());
    }

    /** Anexa `em_carencia` (bool) e `carencia_ate` (data) a cada animal (RF04). */
    public function scopeComCarencia(Builder $query): void
    {
        $query
            ->withExists(['vacinas as em_carencia' => fn (Builder $q) => $q->vigentes()])
            ->withMax(['vacinas as carencia_ate' => fn (Builder $q) => $q->vigentes()], 'data_fim_carencia');
    }
}

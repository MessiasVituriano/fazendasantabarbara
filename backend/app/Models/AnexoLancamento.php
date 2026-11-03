<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** Comprovante (nota, boleto, recibo) guardado em disco privado; baixado só com autenticação. */
class AnexoLancamento extends Model
{
    protected $table = 'anexos_lancamento';

    protected $fillable = ['lancamento_id', 'nome_original', 'caminho', 'mime', 'tamanho', 'enviado_por'];

    protected $hidden = ['caminho'];

    public static function disco(): string
    {
        return config('pecuaria.financeiro.disco_anexos');
    }

    public function lancamento(): BelongsTo
    {
        return $this->belongsTo(Lancamento::class);
    }

    protected static function booted(): void
    {
        static::deleted(fn (self $anexo) => Storage::disk(self::disco())->delete($anexo->caminho));
    }
}

<?php

namespace App\Models;

use App\Casts\Data;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class FotoAnimal extends Model
{
    protected $table = 'fotos_animal';

    protected $fillable = ['animal_id', 'caminho', 'caminho_miniatura', 'largura', 'altura', 'legenda', 'data_registro', 'principal'];

    protected $hidden = ['caminho', 'caminho_miniatura'];

    protected $appends = ['url', 'miniatura_url'];

    protected function casts(): array
    {
        return [
            'data_registro' => Data::class,
            'principal' => 'boolean',
            'largura' => 'integer',
            'altura' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Remove os arquivos junto com o registro.
        static::deleted(fn (self $foto) => self::disco()->delete([$foto->caminho, $foto->caminho_miniatura]));
    }

    public static function disco(): Filesystem
    {
        return Storage::disk(config('pecuaria.fotos.disco'));
    }

    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }

    public function getUrlAttribute(): string
    {
        return self::disco()->url($this->caminho);
    }

    public function getMiniaturaUrlAttribute(): string
    {
        return self::disco()->url($this->caminho_miniatura);
    }
}

<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Data sem hora: grava sempre 'AAAA-MM-DD' e lê como Carbon à meia-noite.
 * O cast 'date' nativo grava 'AAAA-MM-DD 00:00:00', o que em SQLite quebra comparações
 * de limite como `data <= '2026-10-15'` (no PostgreSQL a coluna date já descarta a hora).
 */
class Data implements CastsAttributes, SerializesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->toDateString();
    }

    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        // O Laravel já converte Carbon em ISO-UTC antes daqui; o valor bruto evita deslocamento de fuso.
        $bruto = $attributes[$key] ?? null;

        return $bruto === null ? null : substr((string) $bruto, 0, 10);
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base dos endpoints de gravação em lote (RF01 — sincronização mobile offline).
 *
 * Aceita três formatos de corpo, normalizados para `registros`:
 *   - array JSON:                 [ {...}, {...} ]
 *   - envelope:                   { "registros": [ {...}, {...} ] }
 *   - registro único (web):       { ... }
 */
abstract class RequisicaoEmLote extends FormRequest
{
    public const MAX_REGISTROS = 1000;

    /** Regras de UM registro; são aplicadas a `registros.*`. */
    abstract protected function regrasDoRegistro(): array;

    protected function prepareForValidation(): void
    {
        $dados = $this->json()->all() ?: $this->all();

        if (array_is_list($dados) && $dados !== []) {
            $this->replace(['registros' => $dados]);
        } elseif (! array_key_exists('registros', $dados)) {
            $this->replace(['registros' => [$dados]]);
        }
    }

    public function rules(): array
    {
        $regras = ['registros' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_REGISTROS]];

        foreach ($this->regrasDoRegistro() as $campo => $regra) {
            $regras["registros.*.{$campo}"] = $regra;
        }

        return $regras;
    }

    /** @return list<array<string, mixed>> */
    public function registros(): array
    {
        return $this->validated('registros');
    }
}

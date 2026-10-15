<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EnviarFotoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'foto' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('pecuaria.fotos.tamanho_maximo_kb')],
            'legenda' => ['nullable', 'string', 'max:255'],
            'data_registro' => ['nullable', 'date', 'before_or_equal:today'],
            'principal' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'foto.uploaded' => 'A foto excede o tamanho máximo aceito pelo servidor.',
            'foto.image' => 'O arquivo enviado não é uma imagem.',
            'foto.mimes' => 'Formato não suportado. Envie JPG, PNG ou WebP.',
            'foto.max' => 'A foto deve ter no máximo :max KB.',
        ];
    }
}

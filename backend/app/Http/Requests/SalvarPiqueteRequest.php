<?php

namespace App\Http\Requests;

use App\Enums\StatusPiquete;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalvarPiqueteRequest extends FormRequest
{
    public function rules(): array
    {
        $piquete = $this->route('piquete');
        $obrigatorio = $piquete ? 'sometimes' : 'required';

        return [
            'identificacao' => [$obrigatorio, 'string', 'max:50', Rule::unique('piquetes', 'identificacao')->ignore($piquete)],
            'hectares' => [$obrigatorio, 'numeric', 'gt:0', 'max:999999.99'],
            'status' => ['sometimes', Rule::enum(StatusPiquete::class)],
        ];
    }
}

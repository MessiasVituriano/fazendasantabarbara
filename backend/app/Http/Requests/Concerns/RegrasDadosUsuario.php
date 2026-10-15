<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;
use Illuminate\Validation\Rule;

/** Validação dos dados cadastrais do usuário (perfil próprio e gerenciamento). */
trait RegrasDadosUsuario
{
    protected function regrasDados(?User $usuario, bool $obrigatorios): array
    {
        $req = $obrigatorios ? 'required' : 'sometimes';

        return [
            'nome' => [$req, 'string', 'max:100'],
            'usuario' => [$req, 'string', 'min:3', 'max:50', 'regex:/^[a-zA-Z0-9._-]+$/',
                Rule::unique('users', 'usuario')->ignore($usuario)],
            'email' => ['sometimes', 'nullable', 'email', 'max:150', Rule::unique('users', 'email')->ignore($usuario)],
            // Aceita "(11) 98765-4321", "+55 11 98765-4321" etc.; 10 a 13 dígitos.
            'telefone' => ['sometimes', 'nullable', 'string', 'max:25', 'regex:/^\+?[\d\s().-]+$/',
                function (string $campo, mixed $valor, \Closure $falha) {
                    $digitos = strlen(preg_replace('/\D/', '', (string) $valor));
                    if ($valor !== null && $valor !== '' && ($digitos < 10 || $digitos > 13)) {
                        $falha('Informe o telefone com DDD, por exemplo (11) 98765-4321.');
                    }
                }],
        ];
    }

    protected function mensagensDados(): array
    {
        return [
            'usuario.regex' => 'O usuário pode ter apenas letras, números, ponto, hífen e sublinhado (sem espaços).',
            'usuario.unique' => 'Este nome de usuário já está em uso.',
            'email.unique' => 'Este e-mail já está em uso por outro usuário.',
            'telefone.regex' => 'Informe o telefone com DDD, por exemplo (11) 98765-4321.',
        ];
    }
}

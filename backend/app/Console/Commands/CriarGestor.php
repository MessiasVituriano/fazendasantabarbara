<?php

namespace App\Console\Commands;

use App\Enums\Perfil;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Cria (ou reativa) um gestor — usado para o primeiro acesso em produção. */
#[Signature('pecuaria:criar-gestor {usuario} {--nome=} {--email=}')]
#[Description('Cria um usuário com perfil Gestor (pede a senha no terminal)')]
class CriarGestor extends Command
{
    public function handle(): int
    {
        $senha = $this->secret('Senha (mínimo 6 caracteres)');
        if (mb_strlen((string) $senha) < 6) {
            $this->error('A senha deve ter pelo menos 6 caracteres.');

            return self::FAILURE;
        }

        $usuario = User::updateOrCreate(
            ['usuario' => mb_strtolower($this->argument('usuario'))],
            [
                'name' => $this->option('nome') ?: $this->argument('usuario'),
                'email' => $this->option('email'),
                'perfil' => Perfil::GESTOR,
                'ativo' => true,
                'password' => $senha,
            ],
        );
        $this->info("Gestor \"{$usuario->usuario}\" pronto para entrar.");

        return self::SUCCESS;
    }
}

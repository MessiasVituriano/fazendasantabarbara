<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Cria (ou promove) um administrador da plataforma: cadastra clientes, convida donos e faz suporte. */
#[Signature('pecuaria:criar-admin {usuario} {--nome=} {--email=}')]
#[Description('Cria um administrador da plataforma (pede a senha se o usuário for novo)')]
class CriarAdmin extends Command
{
    public function handle(): int
    {
        $usuario = User::firstWhere('usuario', mb_strtolower($this->argument('usuario')));
        if ($usuario === null) {
            $senha = $this->secret('Senha (mínimo 8 caracteres)');
            if (mb_strlen((string) $senha) < 8) {
                $this->error('A senha deve ter pelo menos 8 caracteres.');

                return self::FAILURE;
            }
            $usuario = User::create([
                'usuario' => $this->argument('usuario'),
                'name' => $this->option('nome') ?: $this->argument('usuario'),
                'email' => $this->option('email'),
                'ativo' => true,
                'password' => $senha,
            ]);
        }
        $usuario->forceFill(['admin_plataforma' => true, 'ativo' => true])->save();
        $this->info("Administrador \"{$usuario->usuario}\" pronto. Entre no painel e abra Administração.");

        return self::SUCCESS;
    }
}

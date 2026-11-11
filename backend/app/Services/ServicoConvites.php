<?php

namespace App\Services;

use App\Enums\Perfil;
use App\Exceptions\RegraNegocioException;
use App\Models\Convite;
use App\Models\Fazenda;
use App\Models\User;
use App\Notifications\ConviteRecebido;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Convites por link: quem convida recebe o link para mandar pelo WhatsApp (e, havendo e-mail, ele também vai por e-mail).
 * Quem aceita cria o próprio acesso (usuário e senha) ou usa o que já tem, se já trabalha em outra fazenda.
 */
class ServicoConvites
{
    public const DIAS_VALIDADE = 7;

    /**
     * @param  array{nome: string, perfil: string|Perfil, email?: ?string, telefone?: ?string}  $dados
     * @return array{convite: Convite, link: string, whatsapp: string}
     */
    public function criar(Fazenda $fazenda, array $dados, ?User $por, bool $dono = false): array
    {
        $token = Str::random(48);
        $convite = new Convite([
            'fazenda_id' => $fazenda->id,
            'perfil' => $dados['perfil'],
            'dono' => $dono,
            'nome' => $dados['nome'],
            'email' => $dados['email'] ?? null,
            'telefone' => isset($dados['telefone']) ? preg_replace('/\D/', '', $dados['telefone']) ?: null : null,
            'convidado_por' => $por?->id,
            'expira_em' => now()->addDays(self::DIAS_VALIDADE),
        ]);
        $convite->forceFill(['token_hash' => hash('sha256', $token)])->save();
        $convite->setRelation('fazenda', $fazenda);

        $link = Links::painel("/convite/{$token}");
        if ($convite->email) {
            Notification::route('mail', $convite->email)->notify(new ConviteRecebido($convite, $link));
        }

        $mensagem = "Olá, {$convite->nome}! Você foi convidado(a) para acessar a {$fazenda->nome} como {$convite->perfil->label()}. "
            .'Crie seu acesso por este link (vale por '.self::DIAS_VALIDADE." dias): {$link}";

        return ['convite' => $convite, 'link' => $link, 'whatsapp' => Links::whatsapp($convite->telefone, $mensagem)];
    }

    /** Convite que ainda pode ser aceito; os inválidos dão 404 sem dizer o motivo a quem não tem o link certo. */
    public function pendente(string $token): Convite
    {
        return Convite::pendentes()->with('fazenda')->where('token_hash', hash('sha256', $token))->first()
            ?? throw new RegraNegocioException('Este convite não existe, expirou ou já foi usado. Peça um novo a quem convidou você.', 'CONVITE_INVALIDO', 404);
    }

    /**
     * @param  array{modo: 'novo'|'existente', usuario: string, senha: string, nome?: string}  $dados
     */
    public function aceitar(string $token, array $dados): User
    {
        return DB::transaction(function () use ($token, $dados) {
            $convite = $this->pendente($token);
            Convite::whereKey($convite->id)->lockForUpdate()->first();

            $usuario = $dados['modo'] === 'existente' ? $this->autenticar($dados) : User::create([
                'name' => $dados['nome'] ?? $convite->nome,
                'usuario' => $dados['usuario'],
                // O e-mail do convite vira o do usuário, se ninguém o usa ainda (serve para recuperar a senha).
                'email' => $convite->email && ! User::where('email', $convite->email)->exists() ? $convite->email : null,
                'telefone' => $convite->telefone,
                'ativo' => true,
                'password' => $dados['senha'],
            ]);

            $usuario->vincular($convite->fazenda, $convite->perfil);
            if ($convite->dono) {
                $usuario->clientesGerenciados()->syncWithoutDetaching([$convite->fazenda->cliente_id => ['papel' => 'DONO']]);
            }
            $convite->forceFill(['aceito_em' => now(), 'aceito_por' => $usuario->id])->save();

            return $usuario;
        });
    }

    /** @param  array{usuario: string, senha: string}  $dados */
    private function autenticar(array $dados): User
    {
        $usuario = User::where('usuario', mb_strtolower(trim($dados['usuario'])))->first();
        if (! $usuario || ! Hash::check($dados['senha'], $usuario->password)) {
            throw ValidationException::withMessages(['usuario' => 'Usuário ou senha inválidos.']);
        }
        if (! $usuario->ativo) {
            throw ValidationException::withMessages(['usuario' => 'Este usuário está bloqueado. Fale com o suporte.']);
        }

        return $usuario;
    }
}

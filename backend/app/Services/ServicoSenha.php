<?php

namespace App\Services;

use App\Exceptions\RegraNegocioException;
use App\Models\RedefinicaoSenha;
use App\Models\User;
use App\Notifications\LinkNovaSenha;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Nova senha por link de uso único. Quem tem e-mail pede na tela de login e recebe por e-mail; quem não tem
 * (caso comum do caseiro) pede ao gestor, que gera o link e manda pelo WhatsApp.
 */
class ServicoSenha
{
    public const HORAS_VALIDADE = 24;

    /** @return array{link: string, whatsapp: string, expira_em: string} */
    public function gerarLink(User $usuario, ?User $por = null): array
    {
        $token = Str::random(48);
        $redefinicao = (new RedefinicaoSenha([
            'user_id' => $usuario->id, 'gerado_por' => $por?->id, 'expira_em' => now()->addHours(self::HORAS_VALIDADE),
        ]))->forceFill(['token_hash' => hash('sha256', $token)]);
        $redefinicao->save();

        $link = Links::painel("/nova-senha/{$token}");
        $mensagem = "Olá, {$usuario->name}! Crie sua nova senha por este link (vale por ".self::HORAS_VALIDADE." horas): {$link}";

        return ['link' => $link, 'whatsapp' => Links::whatsapp($usuario->telefone, $mensagem), 'expira_em' => $redefinicao->expira_em->toIso8601String()];
    }

    /** Pedido feito na tela de login. Não revela se o usuário existe nem se tem e-mail. */
    public function solicitar(string $identificador): void
    {
        $chave = mb_strtolower(trim($identificador));
        $usuario = User::where('usuario', $chave)->orWhere('email', $chave)->first();
        if ($usuario?->ativo && $usuario->email) {
            $usuario->notify(new LinkNovaSenha($this->gerarLink($usuario)['link']));
        }
    }

    public function redefinir(string $token, string $senha): User
    {
        return DB::transaction(function () use ($token, $senha) {
            $redefinicao = RedefinicaoSenha::where('token_hash', hash('sha256', $token))
                ->whereNull('usado_em')->where('expira_em', '>', now())
                ->lockForUpdate()->first()
                ?? throw new RegraNegocioException('Este link não vale mais. Peça um novo.', 'LINK_INVALIDO', 404);

            $usuario = $redefinicao->usuario;
            $usuario->update(['password' => $senha]);
            // O link usado e os outros pendentes deste usuário deixam de valer; as sessões abertas são encerradas.
            RedefinicaoSenha::where('user_id', $usuario->id)->whereNull('usado_em')->update(['usado_em' => now()]);
            $usuario->tokens()->delete();

            return $usuario;
        });
    }
}

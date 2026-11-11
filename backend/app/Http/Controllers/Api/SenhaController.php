<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\RegraNegocioException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ContextoFazenda;
use App\Services\ServicoSenha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/** Nova senha por link: pedido na tela de login (vai por e-mail) ou gerado pelo gestor (vai pelo WhatsApp). */
class SenhaController extends Controller
{
    public function __construct(private ServicoSenha $senhas) {}

    public function solicitar(Request $request): JsonResponse
    {
        $dados = $request->validate(['usuario' => ['required', 'string', 'max:150']]);
        $this->senhas->solicitar($dados['usuario']);

        return response()->json(['data' => [
            'mensagem' => 'Se houver um e-mail cadastrado para este acesso, o link para criar uma nova senha foi enviado. Sem e-mail, peça o link ao gestor da fazenda.',
        ]], 202);
    }

    public function redefinir(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'token' => ['required', 'string', 'max:100'],
            'senha' => ['required', 'string', 'confirmed', Password::min(6)],
        ], ['senha.confirmed' => 'A confirmação não confere com a senha.', 'senha.min' => 'A senha deve ter pelo menos :min caracteres.']);

        $usuario = $this->senhas->redefinir($dados['token'], $dados['senha']);

        return response()->json(['data' => ['usuario' => $usuario->usuario]]);
    }

    /** O gestor gera o link para alguém da fazenda dele (o caseiro sem e-mail, por exemplo). */
    public function gerarLink(Request $request, User $usuario, ContextoFazenda $contexto): JsonResponse
    {
        abort_unless($usuario->vinculos()->where('fazenda_id', $contexto->idObrigatorio())->exists(), 404);
        if ($usuario->acessaOutroCliente($contexto->fazenda()->cliente_id)) {
            throw new RegraNegocioException(
                'Este usuário também acessa fazendas de outro produtor: ele mesmo deve pedir a nova senha.', 'USUARIO_DE_OUTRO_CLIENTE', 403,
            );
        }

        return response()->json(['data' => $this->senhas->gerarLink($usuario, $request->user())]);
    }
}

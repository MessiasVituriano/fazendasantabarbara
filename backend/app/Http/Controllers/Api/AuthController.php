<?php

namespace App\Http\Controllers\Api;

use App\Enums\Perfil;
use App\Http\Controllers\Controller;
use App\Http\Requests\Concerns\RegrasDadosUsuario;
use App\Http\Resources\UsuarioResource;
use App\Models\User;
use App\Services\ContextoFazenda;
use App\Services\SessaoDoUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** Sessão por token (Sanctum): mesma autenticação para o painel web e o futuro app mobile. */
class AuthController extends Controller
{
    use RegrasDadosUsuario;

    private const TENTATIVAS = 5;

    public function __construct(private ContextoFazenda $contexto, private SessaoDoUsuario $sessao) {}

    public function login(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'usuario' => ['required', 'string', 'max:100'],
            'senha' => ['required', 'string', 'max:255'],
            'dispositivo' => ['nullable', 'string', 'max:100'],
        ]);
        $usuario = mb_strtolower(trim($dados['usuario']));
        $chave = 'login:'.$usuario.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($chave, self::TENTATIVAS)) {
            throw ValidationException::withMessages([
                'usuario' => 'Muitas tentativas. Tente novamente em '.RateLimiter::availableIn($chave).' segundos.',
            ]);
        }

        $user = User::where('usuario', $usuario)->orWhere('email', $usuario)->first();

        if (! $user || ! Hash::check($dados['senha'], $user->password)) {
            RateLimiter::hit($chave, 60);
            throw ValidationException::withMessages(['usuario' => 'Usuário ou senha inválidos.']);
        }
        // O administrador da plataforma entra mesmo sem fazenda (vai para a administração).
        $semAcesso = ! $user->vinculos()->acessiveis()->exists() && ! $user->admin_plataforma;
        if (! $user->ativo || $semAcesso) {
            throw ValidationException::withMessages(['usuario' => 'Este usuário está desativado. Fale com o gestor.']);
        }

        RateLimiter::clear($chave);

        // Com uma fazenda só, ela já é a ativa (perfil na resposta); com várias, o front escolhe e manda X-Fazenda.
        return response()->json(['data' => $this->sessao->abrir($user, $dados['dispositivo'] ?? 'web')]);
    }

    public function eu(Request $request): UsuarioResource
    {
        $user = $request->user();
        $fazenda = $this->contexto->fazenda();

        return (new UsuarioResource($user))->additional([
            'fazenda_atual' => $fazenda?->id,
            'fazendas' => $this->sessao->fazendas($user),
            'admin' => $user->admin_plataforma,
            // Responsável pelo cliente da fazenda ativa: cadastra fazendas, convida e exporta os dados.
            'dono' => $fazenda !== null && $user->ehDonoDe($fazenda->cliente_id),
        ]);
    }

    /** Atualiza os próprios dados cadastrais (perfil e situação não mudam por aqui). */
    public function atualizarPerfil(Request $request): UsuarioResource
    {
        $user = $request->user();
        $dados = $request->validate($this->regrasDados($user, obrigatorios: false), $this->mensagensDados());

        if (array_key_exists('nome', $dados)) {
            $user->name = $dados['nome'];
        }
        $user->fill(array_intersect_key($dados, array_flip(['usuario', 'email', 'telefone'])))->save();

        return new UsuarioResource($user->refresh());
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    public function alterarSenha(Request $request): Response
    {
        $request->validate([
            'senha_atual' => ['required', 'current_password:sanctum'],
            'nova_senha' => ['required', 'confirmed', Password::min(6)],
        ], [
            'senha_atual.current_password' => 'A senha atual está incorreta.',
            'nova_senha.confirmed' => 'A confirmação não confere com a nova senha.',
        ]);

        $user = $request->user();
        $user->update(['password' => $request->input('nova_senha')]);
        // Encerra as outras sessões; mantém a atual.
        $user->tokens()->whereKeyNot($user->currentAccessToken()?->id)->delete();

        return response()->noContent();
    }
}

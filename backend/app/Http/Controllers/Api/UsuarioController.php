<?php

namespace App\Http\Controllers\Api;

use App\Enums\Perfil;
use App\Exceptions\RegraNegocioException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Concerns\RegrasDadosUsuario;
use App\Http\Resources\UsuarioResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Gerenciamento de usuários (somente gestor). Usuários são desativados, nunca apagados. */
class UsuarioController extends Controller
{
    use RegrasDadosUsuario;

    public function index(): AnonymousResourceCollection
    {
        $usuarios = User::query()
            ->addSelect(['ultimo_acesso' => DB::table('personal_access_tokens')
                ->selectRaw('MAX(COALESCE(last_used_at, created_at))')
                ->whereColumn('tokenable_id', 'users.id')
                ->where('tokenable_type', User::class)])
            ->orderByDesc('ativo')->orderBy('perfil')->orderBy('name')
            ->get();

        return UsuarioResource::collection($usuarios);
    }

    public function store(Request $request): UsuarioResource
    {
        $dados = $request->validate($this->regras(), $this->mensagens());

        return new UsuarioResource(User::create([
            'name' => $dados['nome'],
            'usuario' => $dados['usuario'],
            'email' => $dados['email'] ?? null,
            'telefone' => $dados['telefone'] ?? null,
            'perfil' => $dados['perfil'],
            'ativo' => $dados['ativo'] ?? true,
            'password' => $dados['senha'],
        ]));
    }

    public function update(Request $request, User $usuario): UsuarioResource
    {
        $dados = $request->validate($this->regras($usuario), $this->mensagens());

        $deixaDeSerGestorAtivo = $usuario->ehGestor() && $usuario->ativo
            && (($dados['perfil'] ?? Perfil::GESTOR->value) !== Perfil::GESTOR->value || ($dados['ativo'] ?? true) === false);
        if ($deixaDeSerGestorAtivo && User::where('perfil', Perfil::GESTOR)->where('ativo', true)->count() <= 1) {
            throw new RegraNegocioException('É preciso manter pelo menos um gestor ativo no sistema.', 'ULTIMO_GESTOR');
        }

        DB::transaction(function () use ($usuario, $dados) {
            $usuario->fill(array_filter([
                'name' => $dados['nome'] ?? null,
                'usuario' => $dados['usuario'] ?? null,
                'perfil' => $dados['perfil'] ?? null,
                'password' => $dados['senha'] ?? null,
            ], fn ($v) => $v !== null));
            foreach (['email', 'telefone'] as $opcional) {
                if (array_key_exists($opcional, $dados)) {
                    $usuario->{$opcional} = $dados[$opcional];
                }
            }
            if (array_key_exists('ativo', $dados)) {
                $usuario->ativo = $dados['ativo'];
            }

            // Desativar, trocar perfil ou redefinir senha encerra as sessões abertas.
            $encerrarSessoes = $usuario->isDirty(['ativo', 'perfil', 'password']);
            $usuario->save();
            if ($encerrarSessoes) {
                $usuario->tokens()->delete();
            }
        });

        return new UsuarioResource($usuario->refresh());
    }

    private function regras(?User $usuario = null): array
    {
        return [
            ...$this->regrasDados($usuario, obrigatorios: $usuario === null),
            'perfil' => [$usuario ? 'sometimes' : 'required', Rule::enum(Perfil::class)],
            'ativo' => ['sometimes', 'boolean'],
            'senha' => [$usuario ? 'nullable' : 'required', 'string', Password::min(6)],
        ];
    }

    private function mensagens(): array
    {
        return [...$this->mensagensDados(), 'senha.min' => 'A senha deve ter pelo menos :min caracteres.'];
    }
}

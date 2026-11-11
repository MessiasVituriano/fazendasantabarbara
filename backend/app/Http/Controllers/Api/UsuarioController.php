<?php

namespace App\Http\Controllers\Api;

use App\Enums\Perfil;
use App\Exceptions\RegraNegocioException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Concerns\RegrasDadosUsuario;
use App\Http\Resources\UsuarioResource;
use App\Models\FazendaUsuario;
use App\Models\User;
use App\Services\ContextoFazenda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Usuários da fazenda ativa (somente gestor). Perfil e situação são do vínculo com esta fazenda: desativar tira o
 * acesso a ela, não às outras. Usuários não são apagados.
 */
class UsuarioController extends Controller
{
    use RegrasDadosUsuario;

    public function __construct(private ContextoFazenda $contexto) {}

    public function index(): AnonymousResourceCollection
    {
        $fazendaId = $this->contexto->idObrigatorio();
        $usuarios = User::query()
            ->whereHas('vinculos', fn (Builder $q) => $q->where('fazenda_id', $fazendaId))
            ->with(['vinculos' => fn ($q) => $q->where('fazenda_id', $fazendaId)])
            ->addSelect(['ultimo_acesso' => DB::table('personal_access_tokens')
                ->selectRaw('MAX(COALESCE(last_used_at, created_at))')
                ->whereColumn('tokenable_id', 'users.id')
                ->where('tokenable_type', User::class)])
            ->get()
            ->sortBy([
                fn (User $a, User $b) => $this->vinculo($b)->ativo <=> $this->vinculo($a)->ativo,
                fn (User $a, User $b) => $this->vinculo($a)->perfil->value <=> $this->vinculo($b)->perfil->value,
                fn (User $a, User $b) => strcoll($a->name, $b->name),
            ])
            ->values();

        return UsuarioResource::collection($usuarios);
    }

    public function store(Request $request): UsuarioResource
    {
        $dados = $request->validate($this->regras(), $this->mensagens());

        $usuario = DB::transaction(function () use ($dados) {
            $usuario = User::create([
                'name' => $dados['nome'],
                'usuario' => $dados['usuario'],
                'email' => $dados['email'] ?? null,
                'telefone' => $dados['telefone'] ?? null,
                'ativo' => true,
                'password' => $dados['senha'],
            ]);
            $usuario->vincular($this->contexto->idObrigatorio(), Perfil::from($dados['perfil']), $dados['ativo'] ?? true);

            return $usuario;
        });

        return new UsuarioResource($usuario);
    }

    public function update(Request $request, User $usuario): UsuarioResource
    {
        $vinculo = $usuario->vinculos()->where('fazenda_id', $this->contexto->idObrigatorio())->first();
        abort_if($vinculo === null, 404);

        $dados = $request->validate($this->regras($usuario), $this->mensagens());

        $mudaDadosDaPessoa = array_intersect_key($dados, array_flip(['nome', 'usuario', 'email', 'telefone', 'senha'])) !== [];
        if ($mudaDadosDaPessoa && $usuario->acessaOutroCliente($this->contexto->fazenda()->cliente_id)) {
            throw new RegraNegocioException(
                'Este usuário também acessa fazendas de outro produtor: só ele pode alterar os próprios dados e a senha.',
                'USUARIO_DE_OUTRO_CLIENTE', 403,
            );
        }

        $perfil = isset($dados['perfil']) ? Perfil::from($dados['perfil']) : $vinculo->perfil;
        $ativo = $dados['ativo'] ?? $vinculo->ativo;
        $deixaDeSerGestorAtivo = $vinculo->perfil === Perfil::GESTOR && $vinculo->ativo && ($perfil !== Perfil::GESTOR || ! $ativo);
        if ($deixaDeSerGestorAtivo && $this->gestoresAtivos() <= 1) {
            throw new RegraNegocioException('É preciso manter pelo menos um gestor ativo na fazenda.', 'ULTIMO_GESTOR');
        }

        DB::transaction(function () use ($usuario, $vinculo, $dados, $perfil, $ativo) {
            $usuario->fill(array_filter([
                'name' => $dados['nome'] ?? null,
                'usuario' => $dados['usuario'] ?? null,
                'password' => $dados['senha'] ?? null,
            ], fn ($v) => $v !== null));
            foreach (['email', 'telefone'] as $opcional) {
                if (array_key_exists($opcional, $dados)) {
                    $usuario->{$opcional} = $dados[$opcional];
                }
            }
            $vinculo->fill(['perfil' => $perfil, 'ativo' => $ativo]);

            // Desativar, trocar perfil ou redefinir senha encerra as sessões abertas.
            $encerrarSessoes = $usuario->isDirty('password') || $vinculo->isDirty(['ativo', 'perfil']);
            $usuario->save();
            $vinculo->save();
            if ($encerrarSessoes) {
                $usuario->tokens()->delete();
            }
        });

        return new UsuarioResource($usuario->refresh()->load(['vinculos' => fn ($q) => $q->where('fazenda_id', $this->contexto->id())]));
    }

    private function vinculo(User $usuario): FazendaUsuario
    {
        return $usuario->vinculos->first();
    }

    private function gestoresAtivos(): int
    {
        return FazendaUsuario::where('fazenda_id', $this->contexto->idObrigatorio())
            ->where('perfil', Perfil::GESTOR)->where('ativo', true)
            ->whereHas('usuario', fn (Builder $q) => $q->where('ativo', true))
            ->count();
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

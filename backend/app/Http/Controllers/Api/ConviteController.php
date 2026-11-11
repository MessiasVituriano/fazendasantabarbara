<?php

namespace App\Http\Controllers\Api;

use App\Enums\Perfil;
use App\Http\Controllers\Controller;
use App\Http\Requests\Concerns\RegrasDadosUsuario;
use App\Models\Convite;
use App\Services\ContextoFazenda;
use App\Services\ServicoConvites;
use App\Services\SessaoDoUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Convites para a fazenda ativa (gestor) e a página pública do convite (quem recebeu o link).
 * O link é devolvido para o gestor mandar pelo WhatsApp; com e-mail informado, também vai por e-mail.
 */
class ConviteController extends Controller
{
    use RegrasDadosUsuario;

    public function __construct(private ServicoConvites $convites, private ContextoFazenda $contexto) {}

    /** Convites ainda não aceitos da fazenda ativa. */
    public function index(): JsonResponse
    {
        $pendentes = Convite::pendentes()->where('fazenda_id', $this->contexto->idObrigatorio())
            ->with('convidadoPor:id,name')->latest('id')->get();

        return response()->json(['data' => $pendentes->map(fn (Convite $c) => $this->resumo($c))]);
    }

    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate($this->regrasConvite());
        $criado = $this->convites->criar($this->contexto->fazenda(), $dados, $request->user());

        return response()->json(['data' => [...$this->resumo($criado['convite']), 'link' => $criado['link'], 'whatsapp' => $criado['whatsapp']]], 201);
    }

    public function cancelar(int $convite): Response
    {
        Convite::pendentes()->where('fazenda_id', $this->contexto->idObrigatorio())->findOrFail($convite)
            ->forceFill(['cancelado_em' => now()])->save();

        return response()->noContent();
    }

    /** Página pública do convite: para quem é e em qual fazenda. */
    public function mostrar(string $token): JsonResponse
    {
        $convite = $this->convites->pendente($token);

        return response()->json(['data' => [
            'nome' => $convite->nome, 'fazenda' => $convite->fazenda->nome,
            'perfil' => $convite->perfil, 'perfil_label' => $convite->perfil->label(),
            'expira_em' => $convite->expira_em->toIso8601String(),
        ]]);
    }

    /** Aceita criando o acesso (usuário e senha) ou com um acesso que a pessoa já tem; já entra logado. */
    public function aceitar(Request $request, string $token, SessaoDoUsuario $sessao): JsonResponse
    {
        $convite = $this->convites->pendente($token);
        $novo = $request->input('modo') !== 'existente';
        $dados = $request->validate([
            'modo' => ['required', Rule::in(['novo', 'existente'])],
            ...($novo
                ? [...array_intersect_key($this->regrasDados(null, obrigatorios: true), ['usuario' => 1]), 'nome' => ['nullable', 'string', 'max:100'], 'senha' => ['required', 'string', 'confirmed', Password::min(6)]]
                : ['usuario' => ['required', 'string', 'max:100'], 'senha' => ['required', 'string', 'max:255']]),
        ], [...$this->mensagensDados(), 'senha.confirmed' => 'A confirmação não confere com a senha.', 'senha.min' => 'A senha deve ter pelo menos :min caracteres.']);

        $usuario = $this->convites->aceitar($token, $dados);

        return response()->json(['data' => $sessao->abrir($usuario, 'web', $convite->fazenda_id)]);
    }

    /** @return array<string, mixed> */
    private function regrasConvite(): array
    {
        return [
            'nome' => ['required', 'string', 'max:100'],
            'perfil' => ['required', Rule::enum(Perfil::class)],
            'email' => ['nullable', 'email', 'max:150'],
            'telefone' => ['nullable', 'string', 'max:25', 'regex:/^\+?[\d\s().-]+$/'],
        ];
    }

    /** @return array<string, mixed> */
    private function resumo(Convite $c): array
    {
        return [
            'id' => $c->id, 'nome' => $c->nome, 'email' => $c->email, 'telefone' => $c->telefone,
            'perfil' => $c->perfil, 'perfil_label' => $c->perfil->label(), 'dono' => $c->dono,
            'convidado_por' => $c->convidadoPor?->name, 'expira_em' => $c->expira_em->toIso8601String(),
        ];
    }
}

<?php

namespace App\Services;

use App\Enums\Perfil;
use App\Http\Resources\UsuarioResource;
use App\Models\AcessoSuporte;
use App\Models\FazendaUsuario;
use App\Models\User;

/** Resposta de sessão (login e aceite de convite) e a lista de fazendas em que o usuário pode entrar. */
class SessaoDoUsuario
{
    public function __construct(private ContextoFazenda $contexto) {}

    /**
     * Fazendas com o perfil em cada uma. O administrador da plataforma vê também as fazendas em que está
     * em suporte (marcadas, com o prazo).
     *
     * @return list<array{id: int, nome: string, perfil: Perfil, perfil_label: string, suporte?: bool, suporte_ate?: string}>
     */
    public function fazendas(User $usuario): array
    {
        $lista = $usuario->vinculos()->acessiveis()->with('fazenda')->get()
            ->map(fn (FazendaUsuario $v) => ['id' => $v->fazenda->id, 'nome' => $v->fazenda->nome, 'perfil' => $v->perfil, 'perfil_label' => $v->perfil->label()]);

        if ($usuario->admin_plataforma) {
            $proprias = $lista->pluck('id');
            AcessoSuporte::ativos()->where('user_id', $usuario->id)->whereNotIn('fazenda_id', $proprias)->with('fazenda')->get()
                ->unique('fazenda_id')
                ->each(fn (AcessoSuporte $a) => $lista->push([
                    'id' => $a->fazenda->id, 'nome' => $a->fazenda->nome, 'perfil' => Perfil::GESTOR, 'perfil_label' => 'Suporte',
                    'suporte' => true, 'suporte_ate' => $a->expira_em->toIso8601String(),
                ]));
        }

        return $lista->sortBy('nome')->values()->all();
    }

    /**
     * Cria o token e monta a resposta. Com uma fazenda só (ou a preferida), ela já fica ativa para o perfil sair na resposta.
     *
     * @return array{token: string, usuario: UsuarioResource, fazendas: list<array<string, mixed>>, admin: bool}
     */
    public function abrir(User $usuario, string $dispositivo = 'web', ?int $fazendaPreferida = null): array
    {
        $vinculos = $usuario->vinculos()->acessiveis()->with('fazenda')->get();
        $ativo = $vinculos->firstWhere('fazenda_id', $fazendaPreferida) ?? ($vinculos->count() === 1 ? $vinculos->first() : null);
        if ($ativo) {
            $this->contexto->definir($ativo->fazenda, $ativo);
        }

        return [
            'token' => $usuario->createToken($dispositivo)->plainTextToken,
            'usuario' => new UsuarioResource($usuario),
            'fazendas' => $this->fazendas($usuario),
            'fazenda_id' => $ativo?->fazenda_id,
            'admin' => $usuario->admin_plataforma,
        ];
    }
}

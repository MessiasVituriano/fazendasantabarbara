<?php

namespace App\Http\Middleware;

use App\Enums\Perfil;
use App\Exceptions\RegraNegocioException;
use App\Models\AcessoSuporte;
use App\Models\FazendaUsuario;
use App\Models\User;
use App\Services\ContextoFazenda;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Define a fazenda da requisição pelo cabeçalho X-Fazenda, conferindo o vínculo do usuário com ela.
 * Sem o cabeçalho, vale a única fazenda do usuário: mantém funcionando o front atual e os registros que estavam
 * na fila offline antes da multipropriedade. Com 'fazenda:opcional' (rotas da sessão), quem tem várias fazendas e
 * ainda não escolheu segue sem fazenda ativa em vez de receber erro. Roda antes da resolução dos models da rota
 * (ver bootstrap/app.php).
 */
class DefineFazendaAtiva
{
    public const CABECALHO = 'X-Fazenda';

    public function __construct(private ContextoFazenda $contexto) {}

    public function handle(Request $request, Closure $next, string $modo = 'obrigatoria'): Response
    {
        $usuario = $request->user();
        if (! $usuario?->ativo) {
            throw new AuthenticationException;
        }

        $vinculos = $usuario->vinculos()->acessiveis()->with('fazenda')->get();
        $pedida = (string) $request->header(self::CABECALHO, '');

        if ($pedida !== '') {
            $vinculo = ctype_digit($pedida) ? ($vinculos->firstWhere('fazenda_id', (int) $pedida) ?? $this->suporte($usuario, (int) $pedida)) : null;
            if ($vinculo === null) {
                throw new RegraNegocioException('Você não tem acesso a esta fazenda.', 'SEM_ACESSO_FAZENDA', 403);
            }
        } elseif ($vinculos->count() === 1) {
            $vinculo = $vinculos->first();
        } elseif ($modo === 'opcional') {
            return $next($request);
        } elseif ($vinculos->isEmpty()) {
            throw new RegraNegocioException('Seu acesso foi desativado. Fale com o gestor.', 'SEM_ACESSO_FAZENDA', 403);
        } else {
            throw new RegraNegocioException('Escolha a fazenda em que vai trabalhar.', 'FAZENDA_NAO_INFORMADA', 400);
        }

        $this->contexto->definir($vinculo->fazenda, $vinculo);

        return $next($request);
    }

    /**
     * Administrador da plataforma em suporte: entra como gestor enquanto o acesso de suporte valer (com motivo e
     * prazo registrados), inclusive em cliente suspenso. O vínculo não é gravado.
     */
    private function suporte(User $usuario, int $fazendaId): ?FazendaUsuario
    {
        if (! $usuario->admin_plataforma) {
            return null;
        }
        $acesso = AcessoSuporte::ativos()->where('user_id', $usuario->id)->where('fazenda_id', $fazendaId)->with('fazenda')->first();
        if ($acesso === null) {
            return null;
        }

        return (new FazendaUsuario(['fazenda_id' => $fazendaId, 'user_id' => $usuario->id, 'perfil' => Perfil::GESTOR, 'ativo' => true]))
            ->setRelation('fazenda', $acesso->fazenda);
    }
}

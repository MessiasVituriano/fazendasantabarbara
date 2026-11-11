<?php

namespace App\Http\Middleware;

use App\Exceptions\RegraNegocioException;
use App\Services\ContextoFazenda;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Define a fazenda da requisição pelo cabeçalho X-Fazenda, conferindo o vínculo do usuário com ela.
 * Sem o cabeçalho, vale a única fazenda do usuário: mantém funcionando o front atual e os registros que estavam
 * na fila offline antes da multipropriedade. Roda antes da resolução dos models da rota (ver bootstrap/app.php).
 */
class DefineFazendaAtiva
{
    public const CABECALHO = 'X-Fazenda';

    public function __construct(private ContextoFazenda $contexto) {}

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();
        if (! $usuario?->ativo) {
            throw new AuthenticationException;
        }

        $vinculos = $usuario->vinculos()->acessiveis()->with('fazenda')->get();
        $pedida = (string) $request->header(self::CABECALHO, '');

        if ($pedida !== '') {
            $vinculo = ctype_digit($pedida) ? $vinculos->firstWhere('fazenda_id', (int) $pedida) : null;
            if ($vinculo === null) {
                throw new RegraNegocioException('Você não tem acesso a esta fazenda.', 'SEM_ACESSO_FAZENDA', 403);
            }
        } elseif ($vinculos->count() === 1) {
            $vinculo = $vinculos->first();
        } elseif ($vinculos->isEmpty()) {
            throw new RegraNegocioException('Seu acesso foi desativado. Fale com o gestor.', 'SEM_ACESSO_FAZENDA', 403);
        } else {
            throw new RegraNegocioException('Escolha a fazenda em que vai trabalhar.', 'FAZENDA_NAO_INFORMADA', 400);
        }

        $this->contexto->definir($vinculo->fazenda, $vinculo);

        return $next($request);
    }
}

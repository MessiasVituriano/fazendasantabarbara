<?php

namespace App\Http\Middleware;

use App\Services\ContextoFazenda;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Restringe a rota a quem gerencia o cliente da fazenda ativa (papel DONO). */
class ExigeDono
{
    public function __construct(private ContextoFazenda $contexto) {}

    public function handle(Request $request, Closure $next): Response
    {
        $clienteId = $this->contexto->fazenda()?->cliente_id;
        abort_unless((bool) $request->user()?->ehDonoDe($clienteId), 403, 'Esta área é do responsável pela conta do produtor.');

        return $next($request);
    }
}

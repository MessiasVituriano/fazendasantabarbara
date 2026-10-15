<?php

namespace App\Http\Middleware;

use App\Enums\Perfil;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Restringe a rota aos perfis informados: ->middleware('perfil:GESTOR'). */
class ExigePerfil
{
    public function handle(Request $request, Closure $next, string ...$perfis): Response
    {
        $perfil = $request->user()?->perfil;

        if (! $perfil instanceof Perfil || ! in_array($perfil->value, $perfis, true)) {
            abort(403, 'Esta área é restrita ao gestor.');
        }

        return $next($request);
    }
}

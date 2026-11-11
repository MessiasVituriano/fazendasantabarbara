<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Restringe a rota ao administrador da plataforma (nossa equipe): clientes, convites de dono e suporte. */
class ExigeAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) $request->user()?->admin_plataforma, 403, 'Esta área é restrita à administração da plataforma.');

        return $next($request);
    }
}

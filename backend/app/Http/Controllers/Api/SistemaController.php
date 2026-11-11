<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Dados públicos do sistema para a tela de login (antes de saber a fazenda). */
class SistemaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => ['produto' => config('pecuaria.produto')]]);
    }
}

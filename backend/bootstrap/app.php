<?php

use App\Exceptions\RegraNegocioException;
use App\Http\Middleware\ExigePerfil;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resposta de erro no padrão JSON API: { "errors": [ { status, code, title, detail, source? } ] }.
 * `detail` é sempre uma mensagem legível, pronta para exibir ao usuário.
 */
$erroJsonApi = function (int $status, array $erros): JsonResponse {
    return response()->json(['errors' => array_map(fn (array $e) => array_filter([
        'status' => (string) $status,
        ...$e,
    ], fn ($v) => $v !== null), $erros)], $status, ['Content-Type' => 'application/vnd.api+json']);
};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['perfil' => ExigePerfil::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($erroJsonApi): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) use ($erroJsonApi) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => $erroJsonApi(422, collect($e->errors())
                    ->flatMap(fn (array $mensagens, string $campo) => array_map(fn ($msg) => [
                        'code' => 'VALIDACAO',
                        'title' => 'Dados inválidos',
                        'detail' => $msg,
                        'source' => ['pointer' => '/'.str_replace('.', '/', $campo)],
                    ], $mensagens))
                    ->values()->all()),

                $e instanceof RegraNegocioException => $erroJsonApi($e->status, [[
                    'code' => $e->codigo,
                    'title' => 'Operação não permitida',
                    'detail' => $e->getMessage(),
                    'source' => $e->ponteiro ? ['pointer' => $e->ponteiro] : null,
                ]]),

                $e instanceof AuthenticationException => $erroJsonApi(401, [[
                    'code' => 'NAO_AUTENTICADO',
                    'title' => 'Sessão encerrada',
                    'detail' => 'Sua sessão expirou ou não foi iniciada. Entre novamente.',
                ]]),

                $e instanceof AuthorizationException,
                $e instanceof HttpExceptionInterface && $e->getStatusCode() === 403 => $erroJsonApi(403, [[
                    'code' => 'SEM_PERMISSAO',
                    'title' => 'Acesso negado',
                    'detail' => $e->getMessage() && $e->getMessage() !== 'This action is unauthorized.'
                        ? $e->getMessage()
                        : 'Você não tem permissão para esta ação.',
                ]]),

                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => $erroJsonApi(404, [[
                    'code' => 'NAO_ENCONTRADO',
                    'title' => 'Não encontrado',
                    'detail' => 'O registro ou recurso solicitado não foi encontrado.',
                ]]),

                $e instanceof UniqueConstraintViolationException => $erroJsonApi(409, [[
                    'code' => 'REGISTRO_DUPLICADO',
                    'title' => 'Registro duplicado',
                    'detail' => 'Já existe um registro com esses dados (por exemplo, a mesma pesagem para o mesmo animal e data).',
                ]]),

                $e instanceof HttpExceptionInterface => $erroJsonApi($e->getStatusCode(), [[
                    'code' => 'HTTP_'.$e->getStatusCode(),
                    'title' => 'Erro na requisição',
                    'detail' => $e->getMessage() ?: 'Não foi possível concluir a requisição.',
                ]]),

                default => $erroJsonApi(500, [[
                    'code' => 'ERRO_INTERNO',
                    'title' => 'Erro interno',
                    'detail' => config('app.debug')
                        ? $e->getMessage()
                        : 'Ocorreu um erro inesperado. Tente novamente ou contate o suporte.',
                ]]),
            };
        });
    })->create();

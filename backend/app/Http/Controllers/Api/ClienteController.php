<?php

namespace App\Http\Controllers\Api;

use App\Enums\Perfil;
use App\Http\Controllers\Controller;
use App\Models\Fazenda;
use App\Services\ContextoFazenda;
use App\Services\ServicoExportacao;
use App\Services\ServicoFazenda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Área do dono: as fazendas do cliente (da fazenda ativa), nova fazenda e exportação dos dados. */
class ClienteController extends Controller
{
    public function __construct(private ContextoFazenda $contexto) {}

    public function mostrar(): JsonResponse
    {
        $cliente = $this->contexto->fazenda()->cliente;
        $fazendas = $cliente->fazendas()->orderBy('nome')
            ->withCount(['vinculos as usuarios' => fn ($q) => $q->where('ativo', true)])->get();

        return response()->json(['data' => [
            'id' => $cliente->id, 'nome' => $cliente->nome, 'documento' => $cliente->documento,
            'fazendas' => $fazendas->map(fn (Fazenda $f) => $this->fazenda($f) + ['usuarios' => $f->usuarios])->values(),
        ]]);
    }

    /** Nova fazenda do cliente, com o catálogo padrão; quem criou entra nela como gestor. */
    public function criarFazenda(Request $request, ServicoFazenda $servico): JsonResponse
    {
        $dados = $request->validate($this->regras());
        $fazenda = DB::transaction(function () use ($dados, $servico, $request) {
            $fazenda = $servico->criar($this->contexto->fazenda()->cliente, $this->normalizar($dados));
            $request->user()->vincular($fazenda, Perfil::GESTOR);

            return $fazenda;
        });

        return response()->json(['data' => $this->fazenda($fazenda) + ['perfil' => Perfil::GESTOR, 'perfil_label' => Perfil::GESTOR->label()]], 201);
    }

    public function atualizarFazenda(Request $request, Fazenda $fazenda): JsonResponse
    {
        abort_unless($fazenda->cliente_id === $this->contexto->fazenda()->cliente_id, 404);
        $fazenda->update($this->normalizar($request->validate($this->regras(parcial: true))));

        return response()->json(['data' => $this->fazenda($fazenda)]);
    }

    public function exportar(ServicoExportacao $exportacao): BinaryFileResponse
    {
        $cliente = $this->contexto->fazenda()->cliente;

        return response()->download($exportacao->gerar($cliente), 'dados-'.str($cliente->nome)->slug().'.zip')->deleteFileAfterSend();
    }

    /** @return array<string, mixed> */
    private function regras(bool $parcial = false): array
    {
        return [
            'nome' => [$parcial ? 'sometimes' : 'required', 'string', 'max:120'],
            'municipio' => ['nullable', 'string', 'max:80'],
            'uf' => ['nullable', 'string', 'size:2'],
            'documento' => ['nullable', 'string', 'max:20'],
        ];
    }

    private function normalizar(array $dados): array
    {
        if (array_key_exists('uf', $dados)) {
            $dados['uf'] = $dados['uf'] ? mb_strtoupper($dados['uf']) : null;
        }
        if (array_key_exists('documento', $dados)) {
            $dados['documento'] = preg_replace('/\D/', '', (string) $dados['documento']) ?: null;
        }

        return $dados;
    }

    /** @return array<string, mixed> */
    private function fazenda(Fazenda $f): array
    {
        return ['id' => $f->id, 'nome' => $f->nome, 'municipio' => $f->municipio, 'uf' => $f->uf, 'documento' => $f->documento, 'ativa' => $f->ativa];
    }
}

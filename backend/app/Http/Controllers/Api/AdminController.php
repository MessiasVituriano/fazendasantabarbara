<?php

namespace App\Http\Controllers\Api;

use App\Enums\Perfil;
use App\Http\Controllers\Controller;
use App\Models\AcessoSuporte;
use App\Models\Cliente;
use App\Models\Convite;
use App\Models\Fazenda;
use App\Models\User;
use App\Services\ServicoConvites;
use App\Services\ServicoExportacao;
use App\Services\ServicoFazenda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Administração da plataforma (nossa equipe): cadastra clientes com a primeira fazenda e convida o dono,
 * suspende e reativa, entra numa fazenda como suporte (com motivo e prazo) e exporta os dados de um cliente.
 */
class AdminController extends Controller
{
    public function __construct(private ServicoConvites $convites) {}

    public function clientes(): JsonResponse
    {
        $clientes = Cliente::with([
            'fazendas' => fn ($q) => $q->orderBy('nome')->withCount(['vinculos as usuarios' => fn ($v) => $v->where('ativo', true)]),
            'donos:id,name,usuario',
        ])->orderBy('nome')->get();
        $clienteDaFazenda = [];
        foreach ($clientes as $c) {
            foreach ($c->fazendas as $f) {
                $clienteDaFazenda[$f->id] = $c->id;
            }
        }
        $convites = Convite::pendentes()->where('dono', true)->whereIn('fazenda_id', array_keys($clienteDaFazenda))
            ->get()->groupBy(fn (Convite $c) => $clienteDaFazenda[$c->fazenda_id]);

        return response()->json(['data' => $clientes->map(fn (Cliente $c) => [
            'id' => $c->id, 'nome' => $c->nome, 'documento' => $c->documento, 'ativo' => $c->ativo, 'demonstracao' => $c->demonstracao,
            'created_at' => $c->created_at?->toIso8601String(),
            'fazendas' => $c->fazendas->map(fn (Fazenda $f) => [
                'id' => $f->id, 'nome' => $f->nome, 'municipio' => $f->municipio, 'uf' => $f->uf, 'ativa' => $f->ativa, 'usuarios' => $f->usuarios,
            ])->values(),
            'donos' => $c->donos->map(fn (User $u) => ['id' => $u->id, 'nome' => $u->name, 'usuario' => $u->usuario])->values(),
            'convites_dono' => ($convites[$c->id] ?? collect())->map(fn (Convite $cv) => [
                'id' => $cv->id, 'nome' => $cv->nome, 'expira_em' => $cv->expira_em->toIso8601String(),
            ])->values(),
        ])]);
    }

    /** Novo cliente com a primeira fazenda; o dono recebe o convite (link para o WhatsApp e, com e-mail, por e-mail). */
    public function criarCliente(Request $request, ServicoFazenda $fazendas): JsonResponse
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:120'],
            'documento' => ['nullable', 'string', 'max:20'],
            'fazenda.nome' => ['required', 'string', 'max:120'],
            'fazenda.municipio' => ['nullable', 'string', 'max:80'],
            'fazenda.uf' => ['nullable', 'string', 'size:2'],
            'dono.nome' => ['required', 'string', 'max:100'],
            'dono.email' => ['nullable', 'email', 'max:150'],
            'dono.telefone' => ['nullable', 'string', 'max:25', 'regex:/^\+?[\d\s().-]+$/'],
        ], [], ['fazenda.nome' => 'nome da fazenda', 'dono.nome' => 'nome do responsável']);

        $resultado = DB::transaction(function () use ($dados, $fazendas, $request) {
            $cliente = Cliente::create(['nome' => $dados['nome'], 'documento' => $this->digitos($dados['documento'] ?? null)]);
            $fazenda = $fazendas->criar($cliente, [...$dados['fazenda'], 'uf' => isset($dados['fazenda']['uf']) ? mb_strtoupper($dados['fazenda']['uf']) : null]);

            return [$cliente, $fazenda, $this->convites->criar($fazenda, [...$dados['dono'], 'perfil' => Perfil::GESTOR], $request->user(), dono: true)];
        });
        [$cliente, $fazenda, $convite] = $resultado;

        return response()->json(['data' => [
            'cliente' => ['id' => $cliente->id, 'nome' => $cliente->nome],
            'fazenda' => ['id' => $fazenda->id, 'nome' => $fazenda->nome],
            'convite' => ['link' => $convite['link'], 'whatsapp' => $convite['whatsapp']],
        ]], 201);
    }

    /** Edita ou suspende/reativa. Suspenso: ninguém do cliente entra nas fazendas dele (os dados ficam guardados). */
    public function atualizarCliente(Request $request, Cliente $cliente): JsonResponse
    {
        $dados = $request->validate([
            'nome' => ['sometimes', 'string', 'max:120'],
            'documento' => ['sometimes', 'nullable', 'string', 'max:20'],
            'ativo' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('documento', $dados)) {
            $dados['documento'] = $this->digitos($dados['documento']);
        }
        $cliente->update($dados);

        return response()->json(['data' => ['id' => $cliente->id, 'nome' => $cliente->nome, 'ativo' => $cliente->ativo]]);
    }

    /** Novo convite numa fazenda (ex.: o link do dono expirou). */
    public function convidar(Request $request, Fazenda $fazenda): JsonResponse
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:100'],
            'perfil' => ['required', Rule::enum(Perfil::class)],
            'email' => ['nullable', 'email', 'max:150'],
            'telefone' => ['nullable', 'string', 'max:25', 'regex:/^\+?[\d\s().-]+$/'],
            'dono' => ['sometimes', 'boolean'],
        ]);
        $criado = $this->convites->criar($fazenda, $dados, $request->user(), (bool) ($dados['dono'] ?? false));

        return response()->json(['data' => ['link' => $criado['link'], 'whatsapp' => $criado['whatsapp']]], 201);
    }

    /** Entra na fazenda como suporte por AcessoSuporte::HORAS horas; o motivo fica registrado. */
    public function iniciarSuporte(Request $request, Fazenda $fazenda): JsonResponse
    {
        $dados = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:255']]);
        $acesso = AcessoSuporte::create([
            'user_id' => $request->user()->id, 'fazenda_id' => $fazenda->id, 'motivo' => $dados['motivo'],
            'expira_em' => now()->addHours(AcessoSuporte::HORAS),
        ]);

        return response()->json(['data' => [
            'id' => $fazenda->id, 'nome' => $fazenda->nome, 'perfil' => Perfil::GESTOR, 'perfil_label' => 'Suporte',
            'suporte' => true, 'suporte_ate' => $acesso->expira_em->toIso8601String(),
        ]], 201);
    }

    public function encerrarSuporte(Request $request, Fazenda $fazenda): Response
    {
        AcessoSuporte::ativos()->where('user_id', $request->user()->id)->where('fazenda_id', $fazenda->id)->update(['encerrado_em' => now()]);

        return response()->noContent();
    }

    public function exportar(Cliente $cliente, ServicoExportacao $exportacao): BinaryFileResponse
    {
        return response()->download($exportacao->gerar($cliente), 'dados-'.str($cliente->nome)->slug().'.zip')->deleteFileAfterSend();
    }

    private function digitos(?string $valor): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $valor);

        return $digitos === '' ? null : $digitos;
    }
}

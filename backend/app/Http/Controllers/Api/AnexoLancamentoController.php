<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnexoLancamento;
use App\Models\Lancamento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Comprovantes do lançamento: notas, boletos, recibos (PDF ou imagem). */
class AnexoLancamentoController extends Controller
{
    public function store(Request $request, Lancamento $lancamento): JsonResponse
    {
        $max = config('pecuaria.financeiro.tamanho_maximo_anexo_kb');
        $request->validate([
            'arquivo' => ['required', 'file', "max:{$max}", 'mimes:pdf,jpg,jpeg,png,webp,heic,xml,txt,doc,docx,xls,xlsx,csv'],
        ], [], ['arquivo' => 'comprovante']);

        $arquivo = $request->file('arquivo');
        $anexo = $lancamento->anexos()->create([
            'nome_original' => mb_substr($arquivo->getClientOriginalName(), 0, 255),
            'caminho' => $arquivo->store("fazendas/{$lancamento->fazenda_id}/lancamentos/{$lancamento->id}", AnexoLancamento::disco()),
            'mime' => $arquivo->getMimeType() ?? 'application/octet-stream',
            'tamanho' => $arquivo->getSize(),
            'enviado_por' => Auth::id(),
        ]);
        $lancamento->registrarAuditoria('ANEXO_INCLUIDO', null, ['anexo' => $anexo->nome_original]);

        return response()->json(['data' => $anexo], 201);
    }

    public function download(AnexoLancamento $anexo): StreamedResponse
    {
        return Storage::disk(AnexoLancamento::disco())->download($anexo->caminho, $anexo->nome_original);
    }

    public function destroy(AnexoLancamento $anexo): Response
    {
        $anexo->lancamento->registrarAuditoria('ANEXO_REMOVIDO', ['anexo' => $anexo->nome_original], null);
        $anexo->delete();

        return response()->noContent();
    }
}

<?php

namespace App\Services;

use App\Models\AnexoLancamento;
use App\Models\Cliente;
use App\Models\FazendaUsuario;
use App\Models\FotoAnimal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Apaga um cliente com todas as fazendas, registros e arquivos. Usado na exclusão definitiva (LGPD) e para recriar a
 * demonstração. Não tem volta.
 */
class ServicoExclusaoCliente
{
    /**
     * @param  bool  $apagarUsuarios  Apaga também os usuários que só tinham acesso a este cliente (e não são da plataforma).
     * @return array{fazendas: int, usuarios: int}
     */
    public function excluir(Cliente $cliente, bool $apagarUsuarios = true): array
    {
        $fazendaIds = $cliente->fazendas()->pluck('id')->all();
        $usuariosSoDoCliente = $apagarUsuarios
            ? User::where('admin_plataforma', false)
                ->whereHas('vinculos', fn ($q) => $q->whereIn('fazenda_id', $fazendaIds))
                ->whereDoesntHave('vinculos', fn ($q) => $q->whereNotIn('fazenda_id', $fazendaIds))
                ->pluck('id')
            : collect();

        DB::transaction(function () use ($cliente, $fazendaIds, $usuariosSoDoCliente) {
            DB::table('animais')->whereIn('fazenda_id', $fazendaIds)->update(['mae_id' => null]);
            foreach (ServicoExportacao::MODELOS as $modelo) {
                DB::table((new $modelo)->getTable())->whereIn('fazenda_id', $fazendaIds)->delete();
            }
            FazendaUsuario::whereIn('fazenda_id', $fazendaIds)->delete();
            $cliente->fazendas()->delete(); // convites e acessos de suporte saem junto (cascade)
            $cliente->delete();

            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $usuariosSoDoCliente)->delete();
            User::whereKey($usuariosSoDoCliente)->delete();
        });

        foreach ($fazendaIds as $id) {
            FotoAnimal::disco()->deleteDirectory("fazendas/{$id}");
            Storage::disk(AnexoLancamento::disco())->deleteDirectory("fazendas/{$id}");
        }

        return ['fazendas' => count($fazendaIds), 'usuarios' => $usuariosSoDoCliente->count()];
    }
}

<?php

namespace App\Console\Commands;

use App\Models\AnexoLancamento;
use App\Models\Cliente;
use App\Models\FazendaUsuario;
use App\Models\FotoAnimal;
use App\Models\User;
use App\Services\ServicoExportacao;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Exclusão definitiva de um cliente que cancelou (LGPD): todos os registros e arquivos das fazendas dele, as fazendas,
 * o cliente e os usuários que só tinham acesso a ele. Só roda com o cliente já suspenso e com o nome digitado de novo.
 * Não tem volta: exporte os dados antes, se o cliente pediu.
 */
#[Signature('pecuaria:excluir-cliente {cliente : Id do cliente}')]
#[Description('Apaga definitivamente um cliente suspenso, com todas as fazendas, registros e arquivos')]
class ExcluirCliente extends Command
{
    public function handle(): int
    {
        $cliente = Cliente::with('fazendas')->find($this->argument('cliente'));
        if ($cliente === null) {
            $this->error('Cliente não encontrado.');

            return self::FAILURE;
        }
        if ($cliente->ativo) {
            $this->error('Suspenda o cliente antes de excluir (ativo = false).');

            return self::FAILURE;
        }

        $this->warn("Isto apaga para sempre {$cliente->nome}: {$cliente->fazendas->count()} fazenda(s), com rebanho, financeiro, estoque, fotos e anexos.");
        if ($this->ask('Digite o nome do cliente para confirmar') !== $cliente->nome) {
            $this->error('Nome não confere. Nada foi apagado.');

            return self::FAILURE;
        }

        $fazendaIds = $cliente->fazendas->modelKeys();
        // Usuários que ficam sem nenhuma fazenda (e não são da plataforma) também saem.
        $usuariosSoDoCliente = User::where('admin_plataforma', false)
            ->whereHas('vinculos', fn ($q) => $q->whereIn('fazenda_id', $fazendaIds))
            ->whereDoesntHave('vinculos', fn ($q) => $q->whereNotIn('fazenda_id', $fazendaIds))
            ->pluck('id');

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

        $this->info("Cliente excluído: {$cliente->nome}, ".count($fazendaIds).' fazenda(s), '.count($usuariosSoDoCliente).' usuário(s).');

        return self::SUCCESS;
    }
}

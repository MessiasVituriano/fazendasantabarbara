<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Services\ServicoExclusaoCliente;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Exclusão definitiva de um cliente que cancelou (LGPD): todos os registros e arquivos das fazendas dele, as fazendas,
 * o cliente e os usuários que só tinham acesso a ele. Só roda com o cliente já suspenso e com o nome digitado de novo.
 * Não tem volta: exporte os dados antes, se o cliente pediu.
 */
#[Signature('pecuaria:excluir-cliente {cliente : Id do cliente}')]
#[Description('Apaga definitivamente um cliente suspenso, com todas as fazendas, registros e arquivos')]
class ExcluirCliente extends Command
{
    public function handle(ServicoExclusaoCliente $exclusao): int
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

        $excluido = $exclusao->excluir($cliente);

        $this->info("Cliente excluído: {$cliente->nome}, {$excluido['fazendas']} fazenda(s), {$excluido['usuarios']} usuário(s).");

        return self::SUCCESS;
    }
}

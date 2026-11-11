<?php

namespace App\Services;

use App\Models\Fazenda;
use App\Models\FazendaUsuario;
use LogicException;

/**
 * Fazenda ativa da requisição (ou do comando). Definida pelo middleware DefineFazendaAtiva a partir do cabeçalho
 * X-Fazenda; todo model com PertenceAFazenda filtra e grava por ela. Sem fazenda ativa, consultar esses models
 * é erro de programação: nunca devolvemos dados de todas as fazendas por omissão.
 */
class ContextoFazenda
{
    private ?Fazenda $fazenda = null;

    /** Vínculo do usuário logado com a fazenda ativa (perfil e situação). */
    private ?FazendaUsuario $vinculo = null;

    public function definir(Fazenda $fazenda, ?FazendaUsuario $vinculo = null): void
    {
        $this->fazenda = $fazenda;
        $this->vinculo = $vinculo;
    }

    public function limpar(): void
    {
        $this->fazenda = null;
        $this->vinculo = null;
    }

    public function fazenda(): ?Fazenda
    {
        return $this->fazenda;
    }

    public function id(): ?int
    {
        return $this->fazenda?->id;
    }

    public function idObrigatorio(): int
    {
        return $this->fazenda?->id
            ?? throw new LogicException('Nenhuma fazenda ativa: defina a fazenda antes de consultar ou gravar registros dela.');
    }

    public function vinculo(): ?FazendaUsuario
    {
        return $this->vinculo;
    }

    /**
     * Executa o callback com outra fazenda ativa e restaura a anterior (comandos, seeders, rotinas).
     *
     * @template T
     *
     * @param  callable(Fazenda): T  $callback
     * @return T
     */
    public function executarEm(Fazenda $fazenda, callable $callback): mixed
    {
        [$fazendaAnterior, $vinculoAnterior] = [$this->fazenda, $this->vinculo];
        $this->definir($fazenda);

        try {
            return $callback($fazenda);
        } finally {
            $this->fazenda = $fazendaAnterior;
            $this->vinculo = $vinculoAnterior;
        }
    }
}

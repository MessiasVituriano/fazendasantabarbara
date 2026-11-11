<?php

namespace App\Console\Commands;

use App\Models\AnexoLancamento;
use App\Models\Fazenda;
use App\Models\FotoAnimal;
use App\Services\ContextoFazenda;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Move fotos e anexos gravados antes da multipropriedade para a pasta da fazenda (fazendas/{id}/...).
 * Para cada arquivo: copia, confere o tamanho da cópia, atualiza o caminho no banco e só então apaga o original.
 * Se algo falhar, a cópia é desfeita e o registro continua apontando para o original. Rodar de novo só move o que faltou.
 */
#[Signature('pecuaria:organizar-arquivos')]
#[Description('Move fotos e anexos antigos para a pasta da fazenda de cada um')]
class OrganizarArquivos extends Command
{
    public function handle(ContextoFazenda $contexto): int
    {
        $movidos = 0;
        $falhas = 0;

        foreach (Fazenda::orderBy('id')->get() as $fazenda) {
            $contexto->executarEm($fazenda, function (Fazenda $fazenda) use (&$movidos, &$falhas) {
                $prefixo = "fazendas/{$fazenda->id}/";
                $pendentes = [
                    [FotoAnimal::disco(), FotoAnimal::query()->where('caminho', 'not like', $prefixo.'%')->orderBy('id'), ['caminho', 'caminho_miniatura']],
                    [Storage::disk(AnexoLancamento::disco()), AnexoLancamento::query()->where('caminho', 'not like', $prefixo.'%')->orderBy('id'), ['caminho']],
                ];
                foreach ($pendentes as [$disco, $consulta, $colunas]) {
                    foreach ($consulta->lazyById() as $registro) {
                        try {
                            $this->mover($disco, $registro, $colunas, $prefixo);
                            $movidos++;
                        } catch (Throwable $e) {
                            $falhas++;
                            $this->error("{$fazenda->nome} · ".class_basename($registro)." {$registro->id}: {$e->getMessage()}");
                        }
                    }
                }
            });
        }

        $this->info("Arquivos organizados: {$movidos} registro(s) movido(s), {$falhas} com falha.");

        return $falhas ? self::FAILURE : self::SUCCESS;
    }

    /** @param  list<string>  $colunas */
    private function mover(Filesystem $disco, Model $registro, array $colunas, string $prefixo): void
    {
        $copias = [];
        try {
            foreach ($colunas as $coluna) {
                $origem = $registro->{$coluna};
                $destino = $prefixo.ltrim($origem, '/');
                if (! $disco->exists($origem)) {
                    throw new RuntimeException("arquivo {$origem} não encontrado");
                }
                $disco->writeStream($destino, $disco->readStream($origem), ['visibility' => $disco->getVisibility($origem)]);
                if ($disco->size($destino) !== $disco->size($origem)) {
                    throw new RuntimeException("cópia de {$origem} ficou com tamanho diferente");
                }
                $copias[$coluna] = [$origem, $destino];
            }
            $registro->forceFill(array_map(fn (array $par) => $par[1], $copias))->saveQuietly();
        } catch (Throwable $e) {
            $disco->delete(array_column($copias, 1));
            throw $e;
        }

        $disco->delete(array_column($copias, 0));
    }
}

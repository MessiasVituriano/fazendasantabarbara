<?php

namespace App\Services;

use App\Models\AnexoLancamento;
use App\Models\Animal;
use App\Models\AnimalLote;
use App\Models\CategoriaFinanceira;
use App\Models\CategoriaSanitaria;
use App\Models\Cliente;
use App\Models\ContaFinanceira;
use App\Models\EventoReprodutivo;
use App\Models\Fazenda;
use App\Models\FotoAnimal;
use App\Models\HistoricoAlteracao;
use App\Models\HistoricoPastoreio;
use App\Models\ItemEstoque;
use App\Models\Lancamento;
use App\Models\Lote;
use App\Models\MetaEngorda;
use App\Models\MovimentoCapital;
use App\Models\MovimentoEstoque;
use App\Models\Pesagem;
use App\Models\Pessoa;
use App\Models\Piquete;
use App\Models\ProducaoLeite;
use App\Models\ProdutoSanitario;
use App\Models\User;
use App\Models\VacinaSanidade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Exportação completa dos dados de um cliente (LGPD: portabilidade): um ZIP com um JSON por tabela de cada fazenda,
 * os usuários com acesso (sem senhas) e as fotos e anexos.
 */
class ServicoExportacao
{
    /** Tabelas de cada fazenda, na ordem em que podem ser apagadas (dependentes antes). */
    public const MODELOS = [
        HistoricoAlteracao::class, AnexoLancamento::class, Lancamento::class, MovimentoCapital::class,
        MovimentoEstoque::class, ItemEstoque::class, MetaEngorda::class, AnimalLote::class,
        VacinaSanidade::class, ProdutoSanitario::class, CategoriaSanitaria::class,
        ProducaoLeite::class, Pesagem::class, EventoReprodutivo::class, FotoAnimal::class, HistoricoPastoreio::class,
        Piquete::class, Animal::class, Lote::class, Pessoa::class, ContaFinanceira::class, CategoriaFinanceira::class,
    ];

    public function __construct(private ContextoFazenda $contexto) {}

    /** Gera o ZIP e devolve o caminho (o chamador apaga depois de enviar). */
    public function gerar(Cliente $cliente): string
    {
        $pasta = storage_path('app/exportacoes');
        File::ensureDirectoryExists($pasta);
        $caminho = $pasta.'/cliente-'.$cliente->id.'-'.now()->format('Ymd-His').'.zip';

        $zip = new ZipArchive;
        if ($zip->open($caminho, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar o arquivo de exportação.');
        }

        $fazendas = $cliente->fazendas()->orderBy('id')->get();
        $zip->addFromString('LEIA-ME.txt', $this->leiaMe($cliente));
        $zip->addFromString('cliente.json', $this->json($cliente->only(['id', 'nome', 'documento', 'ativo', 'created_at'])));
        $zip->addFromString('fazendas.json', $this->json($fazendas->map->only(['id', 'nome', 'municipio', 'uf', 'documento', 'ativa', 'created_at'])));
        $zip->addFromString('usuarios.json', $this->json($this->usuarios($fazendas->modelKeys())));

        foreach ($fazendas as $fazenda) {
            $this->contexto->executarEm($fazenda, function (Fazenda $fazenda) use ($zip) {
                foreach (self::MODELOS as $modelo) {
                    $linhas = $modelo::query()->orderBy('id')->get()->map(fn ($registro) => $registro->getAttributes());
                    $zip->addFromString("fazenda-{$fazenda->id}/".(new $modelo)->getTable().'.json', $this->json($linhas));
                }
                foreach (['fotos' => FotoAnimal::disco(), 'anexos' => Storage::disk(AnexoLancamento::disco())] as $tipo => $disco) {
                    foreach ($disco->allFiles("fazendas/{$fazenda->id}") as $arquivo) {
                        $zip->addFromString("fazenda-{$fazenda->id}/arquivos/{$tipo}/".substr($arquivo, strlen("fazendas/{$fazenda->id}/")), $disco->get($arquivo));
                    }
                }
            });
        }

        $zip->close();

        return $caminho;
    }

    /** @param  list<int>  $fazendaIds */
    private function usuarios(array $fazendaIds): array
    {
        return User::whereHas('vinculos', fn ($q) => $q->whereIn('fazenda_id', $fazendaIds))
            ->with(['vinculos' => fn ($q) => $q->whereIn('fazenda_id', $fazendaIds)])
            ->orderBy('id')->get()
            ->map(fn (User $u) => [
                'id' => $u->id, 'nome' => $u->name, 'usuario' => $u->usuario, 'email' => $u->email, 'telefone' => $u->telefone,
                'acessos' => $u->vinculos->map(fn ($v) => ['fazenda_id' => $v->fazenda_id, 'perfil' => $v->perfil, 'ativo' => $v->ativo])->all(),
            ])->all();
    }

    private function json(mixed $dados): string
    {
        return json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function leiaMe(Cliente $cliente): string
    {
        return "Exportação dos dados de {$cliente->nome}, gerada em ".now()->format('d/m/Y H:i').".\n\n"
            ."cliente.json, fazendas.json: cadastro do cliente e das fazendas.\n"
            ."usuarios.json: quem tem acesso e com qual perfil (sem senhas).\n"
            ."fazenda-{id}/: um arquivo JSON por tabela (rebanho, pesagens, ordenha, sanidade, lotes, financeiro, estoque,\n"
            ."histórico de alterações...) e, em arquivos/, as fotos dos animais e os anexos dos lançamentos.\n";
    }
}

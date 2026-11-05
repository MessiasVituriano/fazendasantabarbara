<?php

use App\Http\Controllers\Api\AnexoLancamentoController;
use App\Http\Controllers\Api\AnimalController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoriaFinanceiraController;
use App\Http\Controllers\Api\CategoriaSanitariaController;
use App\Http\Controllers\Api\ContaFinanceiraController;
use App\Http\Controllers\Api\EventoReprodutivoController;
use App\Http\Controllers\Api\FinanceiroController;
use App\Http\Controllers\Api\FotoAnimalController;
use App\Http\Controllers\Api\LancamentoController;
use App\Http\Controllers\Api\LoteController;
use App\Http\Controllers\Api\ManejoController;
use App\Http\Controllers\Api\OpcoesController;
use App\Http\Controllers\Api\PainelController;
use App\Http\Controllers\Api\PessoaController;
use App\Http\Controllers\Api\PesagemController;
use App\Http\Controllers\Api\PiqueteController;
use App\Http\Controllers\Api\ProducaoLeiteController;
use App\Http\Controllers\Api\ProdutoSanitarioController;
use App\Http\Controllers\Api\TimelineAnimalController;
use App\Http\Controllers\Api\UsuarioController;
use App\Http\Controllers\Api\VacinaController;
use Illuminate\Support\Facades\Route;

/*
| Perfis: GESTOR (acesso geral) e CASEIRO (operacional, dados do dia a dia).
| Rotas sem middleware de perfil valem para os dois.
*/

Route::post('login', [AuthController::class, 'login'])->name('login');

Route::middleware('auth:sanctum')->group(function () {
    // Sessão
    Route::get('eu', [AuthController::class, 'eu'])->name('eu');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('opcoes', OpcoesController::class);

    // ---- Operacional (gestor e caseiro) ----
    Route::get('animais', [AnimalController::class, 'index'])->name('animais.index');
    Route::get('animais/{animal}', [AnimalController::class, 'show'])->name('animais.show');
    Route::get('animais/{animal}/timeline', TimelineAnimalController::class)->name('animais.timeline');
    Route::get('animais/{animal}/fotos', [FotoAnimalController::class, 'index'])->name('animais.fotos.index');
    Route::post('animais/{animal}/fotos', [FotoAnimalController::class, 'store'])->name('animais.fotos.store');

    Route::get('manejo/ordenha', [ManejoController::class, 'ordenha'])->name('manejo.ordenha');

    // RF01: gravação em lote (sincronização mobile)
    Route::apiResource('producoes-leite', ProducaoLeiteController::class)->only(['index', 'store']);
    Route::put('producoes-leite/turno', [ProducaoLeiteController::class, 'salvarTurno'])->name('producoes-leite.turno');
    Route::apiResource('pesagens', PesagemController::class)->only(['index', 'store']);
    Route::apiResource('eventos-reprodutivos', EventoReprodutivoController::class)->only(['index', 'store']);
    Route::apiResource('vacinas', VacinaController::class)->only(['index', 'store']);
    Route::get('sanidade/categorias', [CategoriaSanitariaController::class, 'index'])->name('sanidade.categorias.index');
    Route::get('sanidade/produtos', [ProdutoSanitarioController::class, 'index'])->name('sanidade.produtos.index');

    Route::get('piquetes', [PiqueteController::class, 'index'])->name('piquetes.index');
    Route::get('piquetes/{piquete}', [PiqueteController::class, 'show'])->name('piquetes.show');
    Route::post('piquetes/{piquete}/entradas', [PiqueteController::class, 'entradas'])->name('piquetes.entradas');
    Route::post('piquetes/{piquete}/saidas', [PiqueteController::class, 'saidas'])->name('piquetes.saidas');

    // ---- Gestão (somente gestor) ----
    Route::middleware('perfil:GESTOR')->group(function () {
        Route::get('painel/leite', [PainelController::class, 'leite'])->name('painel.leite');
        Route::get('painel/leite/comparativo', [PainelController::class, 'comparativoLeite'])->name('painel.leite.comparativo');
        Route::get('manejo/abate', [ManejoController::class, 'abate'])->name('manejo.abate');

        Route::post('animais', [AnimalController::class, 'store'])->name('animais.store');
        Route::match(['put', 'patch'], 'animais/{animal}', [AnimalController::class, 'update'])->name('animais.update');
        Route::delete('animais/{animal}', [AnimalController::class, 'destroy'])->name('animais.destroy');
        Route::apiResource('animais.fotos', FotoAnimalController::class)
            ->parameters(['animais' => 'animal', 'fotos' => 'foto'])
            ->only(['update', 'destroy'])
            ->scoped(['foto' => 'id']);

        Route::delete('vacinas/{vacina}', [VacinaController::class, 'destroy'])->name('vacinas.destroy');
        Route::post('sanidade/categorias', [CategoriaSanitariaController::class, 'store'])->name('sanidade.categorias.store');
        Route::patch('sanidade/categorias/{categoria}', [CategoriaSanitariaController::class, 'update'])->name('sanidade.categorias.update');
        Route::post('sanidade/produtos', [ProdutoSanitarioController::class, 'store'])->name('sanidade.produtos.store');
        Route::patch('sanidade/produtos/{produto}', [ProdutoSanitarioController::class, 'update'])->name('sanidade.produtos.update');

        Route::post('piquetes', [PiqueteController::class, 'store'])->name('piquetes.store');
        Route::match(['put', 'patch'], 'piquetes/{piquete}', [PiqueteController::class, 'update'])->name('piquetes.update');
        Route::delete('piquetes/{piquete}', [PiqueteController::class, 'destroy'])->name('piquetes.destroy');

        // O caseiro não altera os próprios dados nem a senha: o gestor faz isso em Usuários.
        Route::put('eu', [AuthController::class, 'atualizarPerfil'])->name('eu.atualizar');
        Route::put('eu/senha', [AuthController::class, 'alterarSenha'])->name('eu.senha');
        Route::apiResource('usuarios', UsuarioController::class)->only(['index', 'store', 'update'])
            ->parameters(['usuarios' => 'usuario']);

        // ---- Financeiro (somente gestor) ----
        Route::prefix('financeiro')->name('financeiro.')->group(function () {
            Route::get('resumo', [FinanceiroController::class, 'resumo'])->name('resumo');
            Route::get('fluxo-caixa', [FinanceiroController::class, 'fluxoCaixa'])->name('fluxo-caixa');
            Route::get('demonstrativo', [FinanceiroController::class, 'demonstrativo'])->name('demonstrativo');

            Route::apiResource('contas', ContaFinanceiraController::class)->only(['index', 'store', 'update'])
                ->parameters(['contas' => 'conta']);
            Route::apiResource('categorias', CategoriaFinanceiraController::class)->only(['index', 'store', 'update'])
                ->parameters(['categorias' => 'categoria']);

            Route::post('lancamentos/baixa-em-lote', [LancamentoController::class, 'baixarEmLote'])->name('lancamentos.baixa-em-lote');
            Route::apiResource('lancamentos', LancamentoController::class)->except(['destroy']);
            Route::post('lancamentos/{lancamento}/baixa', [LancamentoController::class, 'baixar'])->name('lancamentos.baixa');
            Route::post('lancamentos/{lancamento}/estorno', [LancamentoController::class, 'estornar'])->name('lancamentos.estorno');
            Route::post('lancamentos/{lancamento}/cancelamento', [LancamentoController::class, 'cancelar'])->name('lancamentos.cancelamento');
            Route::get('lancamentos/{lancamento}/historico', [LancamentoController::class, 'historico'])->name('lancamentos.historico');
            Route::post('lancamentos/{lancamento}/anexos', [AnexoLancamentoController::class, 'store'])->name('lancamentos.anexos.store');
            Route::get('anexos/{anexo}', [AnexoLancamentoController::class, 'download'])->name('anexos.download');
            Route::delete('anexos/{anexo}', [AnexoLancamentoController::class, 'destroy'])->name('anexos.destroy');
        });

        Route::apiResource('pessoas', PessoaController::class)->only(['index', 'store', 'update']);
        Route::apiResource('lotes', LoteController::class)->except(['destroy']);
    });
});

<?php

namespace Tests\Feature;

use App\Enums\Categoria;
use App\Enums\Perfil;
use App\Enums\Sexo;
use App\Models\Animal;
use App\Models\CategoriaFinanceira;
use App\Models\CategoriaSanitaria;
use App\Models\Cliente;
use App\Models\ContaFinanceira;
use App\Models\Fazenda;
use App\Models\ItemEstoque;
use App\Models\Lancamento;
use App\Models\Lote;
use App\Models\Piquete;
use App\Models\User;
use App\Services\ContextoFazenda;
use App\Services\ServicoFazenda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Isolamento entre fazendas: o usuário logado é gestor da fazenda 1; a "outra" fazenda é de outro cliente.
 * Nenhum dado da outra pode ser lido, alterado ou referenciado; registros dela pelo id dão 404 (não confirmam que existem).
 */
class MultipropriedadeTest extends TestCase
{
    use RefreshDatabase;

    private Fazenda $outra;

    /** @var array{animal: Animal, lote: Lote, piquete: Piquete, item: ItemEstoque, conta: ContaFinanceira, lancamento: Lancamento} */
    private array $daOutra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outra = Fazenda::factory()->comCatalogo()->create(['nome' => 'Fazenda Vizinha']);
        $this->daOutra = $this->naFazenda($this->outra, fn () => [
            'animal' => Animal::factory()->corte()->create(['brinco' => 'V-001']),
            'lote' => Lote::factory()->create(['nome' => 'Lote da vizinha']),
            'piquete' => Piquete::factory()->create(),
            'item' => ItemEstoque::factory()->create(['nome' => 'Ração da vizinha']),
            'conta' => $conta = ContaFinanceira::create(['nome' => 'Banco da vizinha', 'tipo' => 'CONTA_CORRENTE', 'saldo_inicial' => 0, 'data_saldo_inicial' => '2026-01-01']),
            'lancamento' => Lancamento::create([
                'tipo' => 'DESPESA', 'descricao' => 'Sal da vizinha', 'categoria_id' => CategoriaFinanceira::porChave('suplementacao_mineral')->id,
                'valor' => 100, 'data_competencia' => '2026-11-01', 'data_vencimento' => '2026-11-10', 'conta_financeira_id' => $conta->id,
            ]),
        ]);
    }

    public function test_listagens_mostram_so_a_fazenda_ativa(): void
    {
        $meu = Animal::factory()->create(['brinco' => 'M-001']);

        $this->getJson('/api/animais')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $meu->id);
        $this->getJson('/api/lotes')->assertOk()->assertJsonMissing(['nome' => 'Lote da vizinha']);
        $this->getJson('/api/estoque/itens')->assertOk()->assertJsonMissing(['nome' => 'Ração da vizinha']);
        $this->getJson('/api/financeiro/contas')->assertOk()->assertJsonMissing(['nome' => 'Banco da vizinha']);
        $this->getJson('/api/financeiro/lancamentos')->assertOk()->assertJsonMissing(['descricao' => 'Sal da vizinha']);
        $this->getJson('/api/financeiro/categorias')->assertOk()->assertJsonCount(count(ServicoFazenda::CATEGORIAS_FINANCEIRAS), 'data');
    }

    public function test_registro_de_outra_fazenda_pelo_id_da_404(): void
    {
        ['animal' => $animal, 'lote' => $lote, 'piquete' => $piquete, 'item' => $item, 'lancamento' => $lancamento] = $this->daOutra;

        $pedidos = [
            ['get', "/api/animais/{$animal->id}"],
            ['get', "/api/animais/{$animal->id}/timeline"],
            ['patch', "/api/animais/{$animal->id}"],
            ['delete', "/api/animais/{$animal->id}"],
            ['get', "/api/lotes/{$lote->id}"],
            ['post', "/api/lotes/{$lote->id}/metas"],
            ['get', "/api/piquetes/{$piquete->id}"],
            ['get', "/api/estoque/itens/{$item->id}"],
            ['get', "/api/financeiro/lancamentos/{$lancamento->id}"],
            ['post', "/api/financeiro/lancamentos/{$lancamento->id}/baixa"],
        ];
        foreach ($pedidos as [$metodo, $url]) {
            $this->json($metodo, $url)->assertNotFound();
        }
        $this->assertSame('V-001', $this->naFazenda($this->outra, fn () => $animal->fresh()->brinco));
    }

    public function test_nao_grava_apontando_para_registro_de_outra_fazenda(): void
    {
        ['animal' => $animal, 'item' => $item, 'conta' => $conta] = $this->daOutra;
        $meuLote = Lote::factory()->create();

        $this->postJson('/api/pesagens', ['animal_id' => $animal->id, 'peso_kg' => 400, 'data_pesagem' => today()->toDateString()])
            ->assertStatus(422)->assertJsonPath('errors.0.source.pointer', '/registros/0/animal_id');
        $this->postJson("/api/lotes/{$meuLote->id}/animais", ['animal_ids' => [$animal->id], 'data_entrada' => today()->toDateString()])
            ->assertStatus(422)->assertJsonPath('errors.0.source.pointer', '/animal_ids/0');
        $this->postJson('/api/estoque/movimentos', ['item_estoque_id' => $item->id, 'tipo' => 'SAIDA', 'quantidade' => 1, 'data_movimento' => today()->toDateString()])
            ->assertStatus(422)->assertJsonPath('errors.0.source.pointer', '/item_estoque_id');
        $this->postJson('/api/financeiro/lancamentos', [
            'tipo' => 'DESPESA', 'descricao' => 'Sal', 'categoria_id' => CategoriaFinanceira::porChave('suplementacao_mineral')->id,
            'valor' => '10.00', 'data_competencia' => '2026-11-01', 'data_vencimento' => '2026-11-10',
            'data_pagamento' => '2026-11-01', 'conta_financeira_id' => $conta->id,
        ])->assertStatus(422)->assertJsonPath('errors.0.source.pointer', '/conta_financeira_id');

        $this->assertSame(0, $this->naFazenda($this->outra, fn () => $animal->pesagens()->count()));
    }

    public function test_registro_novo_vai_para_a_fazenda_ativa_e_unicidade_vale_por_fazenda(): void
    {
        $dados = ['brinco' => 'V-001', 'sexo' => 'M', 'data_nascimento' => '2024-01-10', 'aptidao' => 'CORTE', 'categoria' => 'GARROTE'];

        $id = $this->postJson('/api/animais', $dados)->assertCreated()->json('data.id');
        $this->assertSame(1, Animal::findOrFail($id)->fazenda_id);

        $this->postJson('/api/animais', $dados)->assertStatus(422)->assertJsonPath('errors.0.source.pointer', '/brinco');
    }

    public function test_usuario_com_varias_fazendas_escolhe_pelo_cabecalho_com_o_perfil_de_cada_uma(): void
    {
        $usuario = $this->usuarioLogado;
        $usuario->vincular($this->outra, Perfil::CASEIRO);

        $this->getJson('/api/animais')->assertStatus(400)->assertJsonPath('errors.0.code', 'FAZENDA_NAO_INFORMADA');

        $this->getJson('/api/animais', ['X-Fazenda' => $this->outra->id])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.brinco', 'V-001');
        $this->getJson('/api/usuarios', ['X-Fazenda' => $this->outra->id])->assertForbidden();
        $this->getJson('/api/usuarios', ['X-Fazenda' => 1])->assertOk();
    }

    public function test_fazenda_sem_vinculo_inativa_ou_desativada_nao_e_acessivel(): void
    {
        $this->getJson('/api/animais', ['X-Fazenda' => $this->outra->id])
            ->assertForbidden()->assertJsonPath('errors.0.code', 'SEM_ACESSO_FAZENDA');

        $this->usuarioLogado->vincular($this->outra, Perfil::GESTOR, ativo: false);
        $this->getJson('/api/animais', ['X-Fazenda' => $this->outra->id])->assertForbidden();

        $this->usuarioLogado->vincular($this->outra, Perfil::GESTOR);
        $this->outra->cliente->update(['ativo' => false]);
        $this->getJson('/api/animais', ['X-Fazenda' => $this->outra->id])->assertForbidden();
    }

    public function test_login_lista_as_fazendas_e_so_define_perfil_com_uma_fazenda(): void
    {
        $usuario = User::factory()->caseiro()->create(['usuario' => 'ana', 'password' => 'segredo1']);
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/login', ['usuario' => 'ana', 'senha' => 'segredo1'])
            ->assertOk()->assertJsonPath('data.usuario.perfil', 'CASEIRO')->assertJsonCount(1, 'data.fazendas');

        $usuario->vincular($this->outra, Perfil::GESTOR);
        app(ContextoFazenda::class)->limpar();
        $this->postJson('/api/login', ['usuario' => 'ana', 'senha' => 'segredo1'])
            ->assertOk()
            ->assertJsonPath('data.usuario.perfil', null)
            ->assertJsonPath('data.fazendas.0.nome', 'Fazenda Santa Bárbara')
            ->assertJsonPath('data.fazendas.0.perfil', 'CASEIRO')
            ->assertJsonPath('data.fazendas.1.nome', 'Fazenda Vizinha')
            ->assertJsonPath('data.fazendas.1.perfil', 'GESTOR');
    }

    public function test_gestor_so_ve_e_altera_usuarios_da_propria_fazenda(): void
    {
        $daVizinha = $this->naFazenda($this->outra, fn () => User::factory()->gestor()->create(['name' => 'Gestor Vizinho']));

        $this->getJson('/api/usuarios')->assertOk()->assertJsonMissing(['nome' => 'Gestor Vizinho']);
        $this->patchJson("/api/usuarios/{$daVizinha->id}", ['senha' => 'invadida1'])->assertNotFound();
    }

    public function test_usuario_que_atende_outro_cliente_so_tem_o_acesso_alterado(): void
    {
        $veterinario = User::factory()->caseiro()->create();
        $veterinario->vincular($this->outra, Perfil::GESTOR);
        $senhaAntes = $veterinario->password;

        $this->patchJson("/api/usuarios/{$veterinario->id}", ['senha' => 'trocada1'])
            ->assertForbidden()->assertJsonPath('errors.0.code', 'USUARIO_DE_OUTRO_CLIENTE');
        $this->assertSame($senhaAntes, $veterinario->fresh()->password);

        $this->patchJson("/api/usuarios/{$veterinario->id}", ['ativo' => false])->assertOk()->assertJsonPath('data.ativo', false);
        $this->assertTrue($veterinario->vinculos()->where('fazenda_id', $this->outra->id)->value('ativo'));
    }

    public function test_ultimo_gestor_e_contado_por_fazenda(): void
    {
        $this->naFazenda($this->outra, fn () => User::factory()->gestor()->create());

        $this->patchJson("/api/usuarios/{$this->usuarioLogado->id}", ['perfil' => 'CASEIRO'])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'ULTIMO_GESTOR');
    }

    public function test_desmame_roda_em_todas_as_fazendas(): void
    {
        $bezerro = ['categoria' => Categoria::BEZERRO, 'sexo' => Sexo::M, 'data_nascimento' => today()->subDays(400)];
        $meu = Animal::factory()->create($bezerro);
        $daVizinha = $this->naFazenda($this->outra, fn () => Animal::factory()->create($bezerro));

        $this->artisan('pecuaria:desmame')->assertSuccessful();

        $this->assertSame(Categoria::GARROTE, $meu->fresh()->categoria);
        $this->assertSame(Categoria::GARROTE, $this->naFazenda($this->outra, fn () => $daVizinha->fresh()->categoria));
    }

    public function test_fazenda_nova_nasce_com_o_proprio_catalogo(): void
    {
        $nova = app(ServicoFazenda::class)->criar(Cliente::factory()->create(), ['nome' => 'Fazenda Nova']);

        $this->naFazenda($nova, function () {
            $this->assertSame(count(ServicoFazenda::CATEGORIAS_FINANCEIRAS), CategoriaFinanceira::count());
            $this->assertSame(count(ServicoFazenda::CATEGORIAS_SANITARIAS), CategoriaSanitaria::count());
            $this->assertNotNull(CategoriaFinanceira::porChave('venda_bovinos'));
        });
    }

    public function test_consultar_sem_fazenda_ativa_e_erro(): void
    {
        app(ContextoFazenda::class)->limpar();

        $this->expectException(LogicException::class);
        Animal::count();
    }
}

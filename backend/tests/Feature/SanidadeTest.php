<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\CategoriaSanitaria;
use App\Models\ProdutoSanitario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SanidadeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    private function aplicar(array $dados): TestResponse
    {
        return $this->postJson('/api/vacinas', [
            'animal_id' => Animal::factory()->create()->id,
            'tipo' => 'VACINA',
            'data_aplicacao' => today()->toDateString(),
            'dias_carencia_leite_carne' => 21,
            ...$dados,
        ]);
    }

    public function test_categorias_padrao_existem_com_sugestao_de_carencia(): void
    {
        $this->getJson('/api/sanidade/categorias')->assertOk()
            ->assertJsonFragment(['chave' => 'aftosa', 'tipo' => 'VACINA', 'dias_carencia_padrao' => 21])
            ->assertJsonFragment(['chave' => 'antibiotico', 'tipo' => 'MEDICAMENTO', 'dias_carencia_padrao' => 30])
            ->assertJsonFragment(['chave' => 'outras_vacinas', 'dias_carencia_padrao' => null]);
    }

    public function test_gestor_cadastra_e_edita_categoria_mas_nao_troca_o_tipo(): void
    {
        $id = $this->postJson('/api/sanidade/categorias', ['nome' => 'Botulismo', 'tipo' => 'VACINA', 'dias_carencia_padrao' => 28])
            ->assertCreated()->json('data.id');

        $this->postJson('/api/sanidade/categorias', ['nome' => 'Botulismo', 'tipo' => 'VACINA'])->assertStatus(422);
        $this->postJson('/api/sanidade/categorias', ['nome' => 'Botulismo', 'tipo' => 'MEDICAMENTO'])->assertCreated();

        $this->patchJson("/api/sanidade/categorias/{$id}", ['dias_carencia_padrao' => null, 'ativa' => false])->assertOk()
            ->assertJsonPath('data.dias_carencia_padrao', null)
            ->assertJsonPath('data.ativa', false);
        $this->patchJson("/api/sanidade/categorias/{$id}", ['tipo' => 'MEDICAMENTO'])->assertStatus(422);
    }

    public function test_gestor_cadastra_produto_na_categoria(): void
    {
        $categoria = CategoriaSanitaria::porChave('antibiotico');

        $id = $this->postJson('/api/sanidade/produtos', ['categoria_sanitaria_id' => $categoria->id, 'nome' => 'Oxitetraciclina', 'dias_carencia' => 28])
            ->assertCreated()
            ->assertJsonPath('data.categoria.nome', 'Antibiótico')
            ->json('data.id');

        $this->postJson('/api/sanidade/produtos', ['categoria_sanitaria_id' => $categoria->id, 'nome' => 'Oxitetraciclina'])->assertStatus(422);
        $this->patchJson("/api/sanidade/produtos/{$id}", ['ativo' => false])->assertOk()->assertJsonPath('data.ativo', false);
    }

    public function test_caseiro_consulta_cadastros_mas_nao_altera(): void
    {
        Sanctum::actingAs(User::factory()->caseiro()->create());
        $categoria = CategoriaSanitaria::porChave('aftosa');

        $this->getJson('/api/sanidade/categorias')->assertOk();
        $this->getJson('/api/sanidade/produtos')->assertOk();
        $this->postJson('/api/sanidade/categorias', ['nome' => 'X', 'tipo' => 'VACINA'])->assertForbidden();
        $this->patchJson("/api/sanidade/categorias/{$categoria->id}", ['dias_carencia_padrao' => 0])->assertForbidden();
        $this->postJson('/api/sanidade/produtos', ['categoria_sanitaria_id' => $categoria->id, 'nome' => 'X'])->assertForbidden();
    }

    public function test_aplicacao_de_produto_cadastrado_copia_nome_e_categoria(): void
    {
        $categoria = CategoriaSanitaria::porChave('antibiotico');
        $produto = ProdutoSanitario::create(['categoria_sanitaria_id' => $categoria->id, 'nome' => 'Oxitetraciclina', 'dias_carencia' => 28]);

        $id = $this->aplicar(['tipo' => 'MEDICAMENTO', 'produto_sanitario_id' => $produto->id, 'nome_vacina' => 'ignorado', 'dias_carencia_leite_carne' => 40])
            ->assertCreated()
            ->assertJsonPath('data.nome_vacina', 'Oxitetraciclina')
            ->assertJsonPath('data.categoria_sanitaria_id', $categoria->id)
            ->assertJsonPath('data.tipo', 'MEDICAMENTO')
            // A sugestão não substitui o prazo informado.
            ->assertJsonPath('data.dias_carencia_leite_carne', 40)
            ->json('data.id');

        $produto->update(['nome' => 'Renomeado', 'dias_carencia' => 1]);
        $this->assertDatabaseHas('vacinas_sanidade', ['id' => $id, 'nome_vacina' => 'Oxitetraciclina', 'dias_carencia_leite_carne' => 40]);
    }

    public function test_aplicacao_com_nome_livre_com_e_sem_categoria(): void
    {
        $categoria = CategoriaSanitaria::porChave('aftosa');

        $this->aplicar(['nome_vacina' => 'Vacina X', 'categoria_sanitaria_id' => $categoria->id])->assertCreated()
            ->assertJsonPath('data.produto_sanitario_id', null)
            ->assertJsonPath('data.categoria.nome', 'Aftosa');
        $this->aplicar(['nome_vacina' => 'Vacina Y'])->assertCreated()->assertJsonPath('data.categoria_sanitaria_id', null);
        $this->aplicar([])->assertStatus(422)->assertJsonPath('errors.0.source.pointer', '/nome_vacina');
    }

    public function test_carencia_continua_obrigatoria_mesmo_com_sugestao(): void
    {
        $this->aplicar(['nome_vacina' => 'Vacina X', 'categoria_sanitaria_id' => CategoriaSanitaria::porChave('aftosa')->id, 'dias_carencia_leite_carne' => null])
            ->assertStatus(422)->assertJsonPath('errors.0.source.pointer', '/dias_carencia_leite_carne');
    }

    public function test_recusa_categoria_ou_produto_de_outro_tipo_ou_inativos(): void
    {
        $medicamento = CategoriaSanitaria::porChave('antibiotico');
        $produto = ProdutoSanitario::create(['categoria_sanitaria_id' => $medicamento->id, 'nome' => 'Oxitetraciclina']);
        $inativo = ProdutoSanitario::create(['categoria_sanitaria_id' => $medicamento->id, 'nome' => 'Antigo', 'ativo' => false]);
        $vacinaInativa = CategoriaSanitaria::porChave('raiva');
        $vacinaInativa->update(['ativa' => false]);

        $this->aplicar(['nome_vacina' => 'X', 'categoria_sanitaria_id' => $medicamento->id])->assertStatus(422);
        $this->aplicar(['nome_vacina' => 'X', 'categoria_sanitaria_id' => $vacinaInativa->id])->assertStatus(422);
        $this->aplicar(['produto_sanitario_id' => $produto->id])->assertStatus(422);
        $this->aplicar(['tipo' => 'MEDICAMENTO', 'produto_sanitario_id' => $inativo->id])->assertStatus(422);
    }

    public function test_lista_filtra_por_tipo_categoria_vigentes_e_busca(): void
    {
        $aftosa = CategoriaSanitaria::porChave('aftosa');
        $this->aplicar(['nome_vacina' => 'Vacina Aftosa', 'categoria_sanitaria_id' => $aftosa->id])->assertCreated();
        $this->aplicar(['tipo' => 'MEDICAMENTO', 'nome_vacina' => 'Vermífugo', 'data_aplicacao' => today()->subDays(10)->toDateString(), 'dias_carencia_leite_carne' => 1])->assertCreated();

        $this->getJson('/api/vacinas')->assertJsonCount(2, 'data');
        $this->getJson('/api/vacinas?tipo=MEDICAMENTO')->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome_vacina', 'Vermífugo');
        $this->getJson("/api/vacinas?categoria_sanitaria_id={$aftosa->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.categoria.nome', 'Aftosa');
        $this->getJson('/api/vacinas?vigentes=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome_vacina', 'Vacina Aftosa');
        $this->getJson('/api/vacinas?busca=Verm')->assertJsonCount(1, 'data');
    }

    public function test_linha_do_tempo_mostra_o_tipo_e_preserva_registros_antigos(): void
    {
        $animal = Animal::factory()->create();
        $this->aplicar(['animal_id' => $animal->id, 'tipo' => 'MEDICAMENTO', 'nome_vacina' => 'Vermífugo'])->assertCreated();
        $animal->vacinas()->create(['nome_vacina' => 'Antigo', 'data_aplicacao' => today()->subDay(), 'dias_carencia_leite_carne' => 0]);

        $this->getJson("/api/animais/{$animal->id}/timeline")->assertOk()
            ->assertJsonFragment(['titulo' => 'Medicamento: Vermífugo'])
            ->assertJsonFragment(['titulo' => 'Aplicação: Antigo']);
    }

    public function test_reenvio_com_mesmo_uuid_nao_duplica_a_aplicacao(): void
    {
        $uuid = '2f1d9c4e-8a7b-4c3d-9e2f-1a2b3c4d5e6f';
        $animal = Animal::factory()->create();

        $id = $this->aplicar(['uuid' => $uuid, 'animal_id' => $animal->id, 'nome_vacina' => 'Aftosa'])->assertCreated()->json('data.id');
        $this->aplicar(['uuid' => $uuid, 'animal_id' => $animal->id, 'nome_vacina' => 'Aftosa'])->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame(1, $animal->vacinas()->count());
    }
}

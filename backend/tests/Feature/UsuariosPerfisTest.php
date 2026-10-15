<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsuariosPerfisTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_por_usuario_gera_token_e_retorna_perfil(): void
    {
        User::factory()->caseiro()->create(['usuario' => 'joao', 'password' => 'segredo1']);
        $this->app['auth']->forgetGuards();

        $token = $this->postJson('/api/login', ['usuario' => ' JOAO ', 'senha' => 'segredo1'])
            ->assertOk()
            ->assertJsonPath('data.usuario.perfil', 'CASEIRO')
            ->json('data.token');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/eu')->assertOk()->assertJsonPath('data.usuario', 'joao');
    }

    public function test_login_invalido_e_usuario_desativado(): void
    {
        User::factory()->caseiro()->create(['usuario' => 'joao', 'password' => 'segredo1', 'ativo' => false]);

        $this->postJson('/api/login', ['usuario' => 'joao', 'senha' => 'errada'])
            ->assertStatus(422)->assertJsonPath('errors.0.detail', 'Usuário ou senha inválidos.');
        $this->postJson('/api/login', ['usuario' => 'joao', 'senha' => 'segredo1'])
            ->assertStatus(422)->assertJsonPath('errors.0.detail', 'Este usuário está desativado. Fale com o gestor.');
    }

    public function test_sem_token_retorna_401_legivel(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/animais', ['Authorization' => ''])->assertStatus(401);
        $this->refreshApplication();
        $this->getJson('/api/opcoes')->assertStatus(401)->assertJsonPath('errors.0.code', 'NAO_AUTENTICADO');
    }

    public function test_caseiro_nao_acessa_dados_gerais_nem_cadastros(): void
    {
        $this->entrarComo(User::factory()->caseiro()->create());
        $animal = Animal::factory()->create();

        foreach (['/api/painel/leite', '/api/manejo/abate', '/api/usuarios'] as $url) {
            $this->getJson($url)->assertStatus(403)->assertJsonPath('errors.0.code', 'SEM_PERMISSAO');
        }
        $this->postJson('/api/animais', [])->assertStatus(403);
        $this->deleteJson("/api/animais/{$animal->id}")->assertStatus(403);
        $this->postJson('/api/piquetes', [])->assertStatus(403);
        $this->putJson('/api/eu/senha', ['senha_atual' => 'x', 'nova_senha' => 'nova123', 'nova_senha_confirmation' => 'nova123'])
            ->assertStatus(403);
        $this->getJson('/api/eu')->assertOk(); // vê o próprio perfil
    }

    public function test_caseiro_faz_o_operacional(): void
    {
        $this->entrarComo(User::factory()->caseiro()->create());
        $vaca = Animal::factory()->create();

        $this->getJson('/api/animais')->assertOk();
        $this->getJson('/api/manejo/ordenha')->assertOk();
        $this->putJson('/api/producoes-leite/turno', [
            'data_pesagem' => today()->toDateString(), 'periodo' => 'MANHA',
            'registros' => [['animal_id' => $vaca->id, 'quantidade_litros' => 12]],
        ])->assertOk();
        $this->postJson('/api/eventos-reprodutivos', ['animal_id' => $vaca->id, 'tipo_evento' => 'INSEMINACAO', 'data_evento' => today()->toDateString()])
            ->assertCreated();
    }

    public function test_caseiro_so_lanca_dados_recentes(): void
    {
        config(['pecuaria.dias_retroativos_caseiro' => 3]);
        $this->entrarComo(User::factory()->caseiro()->create());
        $vaca = Animal::factory()->create();
        $turno = fn (int $diasAtras) => $this->putJson('/api/producoes-leite/turno', [
            'data_pesagem' => today()->subDays($diasAtras)->toDateString(), 'periodo' => 'TARDE',
            'registros' => [['animal_id' => $vaca->id, 'quantidade_litros' => 10]],
        ]);

        $turno(3)->assertOk();
        $turno(4)->assertStatus(422)
            ->assertJsonPath('errors.0.detail', 'Você só pode lançar dados dos últimos 3 dias. Para datas anteriores, fale com o gestor.');

        $this->entrarComo(User::factory()->gestor()->create());
        $turno(40)->assertOk(); // gestor não tem limite
    }

    public function test_gestor_gerencia_usuarios(): void
    {
        $id = $this->postJson('/api/usuarios', ['nome' => 'Maria', 'usuario' => 'maria', 'perfil' => 'CASEIRO', 'senha' => 'abc123'])
            ->assertCreated()->assertJsonPath('data.perfil_label', 'Caseiro')->json('data.id');

        $this->postJson('/api/usuarios', ['nome' => 'X', 'usuario' => 'maria silva', 'perfil' => 'CASEIRO', 'senha' => 'abc123'])
            ->assertStatus(422)->assertJsonFragment(['pointer' => '/usuario']);

        $maria = User::find($id);
        $maria->createToken('web');
        $this->patchJson("/api/usuarios/{$id}", ['ativo' => false])->assertOk()->assertJsonPath('data.ativo', false);
        $this->assertSame(0, $maria->tokens()->count()); // sessões encerradas

        $this->getJson('/api/usuarios')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_nao_remove_o_ultimo_gestor(): void
    {
        $this->patchJson("/api/usuarios/{$this->usuarioLogado->id}", ['perfil' => 'CASEIRO'])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'ULTIMO_GESTOR');
    }

    public function test_troca_a_propria_senha(): void
    {
        $this->usuarioLogado->update(['password' => 'antiga1']);

        $this->putJson('/api/eu/senha', ['senha_atual' => 'errada', 'nova_senha' => 'nova123', 'nova_senha_confirmation' => 'nova123'])
            ->assertStatus(422)->assertJsonPath('errors.0.detail', 'A senha atual está incorreta.');
        $this->putJson('/api/eu/senha', ['senha_atual' => 'antiga1', 'nova_senha' => 'nova123', 'nova_senha_confirmation' => 'nova123'])
            ->assertNoContent();
    }

    public function test_gestor_edita_o_proprio_perfil(): void
    {
        User::factory()->create(['usuario' => 'ocupado', 'email' => 'ocupado@fazenda.com']);

        $this->putJson('/api/eu', ['nome' => 'Ana Souza', 'usuario' => 'Ana.Souza', 'email' => 'ana@fazenda.com', 'telefone' => '(11) 98765-4321'])
            ->assertOk()
            ->assertJsonPath('data.nome', 'Ana Souza')
            ->assertJsonPath('data.usuario', 'ana.souza')
            ->assertJsonPath('data.telefone', '11987654321')
            ->assertJsonPath('data.perfil', 'GESTOR');

        $this->putJson('/api/eu', ['usuario' => 'ocupado', 'email' => 'ocupado@fazenda.com', 'telefone' => '1234'])
            ->assertStatus(422)
            ->assertJsonFragment(['detail' => 'Este nome de usuário já está em uso.'])
            ->assertJsonFragment(['detail' => 'Este e-mail já está em uso por outro usuário.'])
            ->assertJsonFragment(['detail' => 'Informe o telefone com DDD, por exemplo (11) 98765-4321.']);

        // perfil e situação não mudam por esta rota
        $this->putJson('/api/eu', ['perfil' => 'CASEIRO', 'ativo' => false])->assertOk()->assertJsonPath('data.perfil', 'GESTOR');
        $this->putJson('/api/eu', ['telefone' => null])->assertOk()->assertJsonPath('data.telefone', null);
    }

    public function test_caseiro_nao_edita_o_proprio_perfil(): void
    {
        $this->entrarComo(User::factory()->caseiro()->create());

        $this->putJson('/api/eu', ['nome' => 'Outro'])->assertStatus(403);
    }

    public function test_gestor_define_telefone_ao_cadastrar(): void
    {
        $this->postJson('/api/usuarios', ['nome' => 'José', 'usuario' => 'jose', 'perfil' => 'CASEIRO', 'senha' => 'abc123', 'telefone' => '+55 (35) 3333-4444'])
            ->assertCreated()->assertJsonPath('data.telefone', '+553533334444');
    }
}

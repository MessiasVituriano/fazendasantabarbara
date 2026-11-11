<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\AcessoSuporte;
use App\Models\Animal;
use App\Models\CategoriaFinanceira;
use App\Models\Cliente;
use App\Models\Convite;
use App\Models\Fazenda;
use App\Models\User;
use App\Notifications\ConviteRecebido;
use App\Notifications\LinkNovaSenha;
use App\Services\ServicoFazenda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Produto vendável: administração da plataforma, convites, conta do produtor (dono), nova senha por link,
 * suporte e LGPD (exportação e exclusão). O usuário padrão dos testes é gestor da fazenda 1.
 */
class PlataformaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['usuario' => 'suporte']);
        $admin->vinculos()->delete(); // da plataforma, sem fazenda própria
        $admin->forceFill(['admin_plataforma' => true])->save();

        return $this->entrarComo($admin->fresh());
    }

    /** Token do link, a partir da URL devolvida pela API. */
    private function tokenDo(string $link): string
    {
        return basename($link);
    }

    public function test_admin_cadastra_cliente_e_o_dono_aceita_o_convite_criando_o_acesso(): void
    {
        $this->admin();

        $resposta = $this->postJson('/api/admin/clientes', [
            'nome' => 'Agropecuária Boa Vista', 'documento' => '12.345.678/0001-90',
            'fazenda' => ['nome' => 'Fazenda Boa Vista', 'municipio' => 'Uberaba', 'uf' => 'mg'],
            'dono' => ['nome' => 'Ana Souza', 'email' => 'ana@exemplo.com', 'telefone' => '(34) 99999-1234'],
        ])->assertCreated();
        $fazenda = Fazenda::findOrFail($resposta->json('data.fazenda.id'));
        $this->assertSame('MG', $fazenda->uf);
        $this->assertStringStartsWith('https://wa.me/5534999991234?text=', $resposta->json('data.convite.whatsapp'));
        Notification::assertSentOnDemand(ConviteRecebido::class, fn ($n, $canais, $destino) => $destino->routes['mail'] === 'ana@exemplo.com');
        $this->naFazenda($fazenda, fn () => $this->assertSame(count(ServicoFazenda::CATEGORIAS_FINANCEIRAS), CategoriaFinanceira::count()));

        $token = $this->tokenDo($resposta->json('data.convite.link'));
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/convites/{$token}")->assertOk()
            ->assertJsonPath('data.fazenda', 'Fazenda Boa Vista')->assertJsonPath('data.perfil', 'GESTOR');

        $sessao = $this->postJson("/api/convites/{$token}/aceitar", [
            'modo' => 'novo', 'usuario' => 'ana.souza', 'senha' => 'segredo1', 'senha_confirmation' => 'segredo1',
        ])->assertOk()->assertJsonPath('data.fazenda_id', $fazenda->id)->assertJsonPath('data.usuario.perfil', 'GESTOR');

        $ana = User::firstWhere('usuario', 'ana.souza');
        $this->assertSame('ana@exemplo.com', $ana->email);
        $this->assertTrue($ana->ehDonoDe($fazenda->cliente_id));
        $this->assertNotEmpty($sessao->json('data.token'));

        $this->getJson("/api/convites/{$token}")->assertNotFound()->assertJsonPath('errors.0.code', 'CONVITE_INVALIDO');

        $this->entrarComo(User::firstWhere('usuario', 'suporte'));
        $this->getJson('/api/admin/clientes')->assertOk()
            ->assertJsonPath('data.0.nome', 'Agropecuária Boa Vista')
            ->assertJsonPath('data.0.donos.0.usuario', 'ana.souza')
            ->assertJsonPath('data.0.fazendas.0.usuarios', 1)
            ->assertJsonCount(0, 'data.0.convites_dono');
    }

    public function test_admin_ve_o_convite_pendente_do_responsavel(): void
    {
        $this->admin();
        $this->postJson('/api/admin/clientes', ['nome' => 'Sítio Novo', 'fazenda' => ['nome' => 'Sítio Novo'], 'dono' => ['nome' => 'Beto']])->assertCreated();

        $this->getJson('/api/admin/clientes')->assertOk()
            ->assertJsonPath('data.1.nome', 'Sítio Novo')->assertJsonPath('data.1.convites_dono.0.nome', 'Beto');
    }

    public function test_quem_ja_tem_acesso_aceita_com_o_proprio_login_e_ganha_a_segunda_fazenda(): void
    {
        $veterinario = User::factory()->caseiro()->create(['usuario' => 'vet', 'password' => 'senha123']);
        $outra = Fazenda::factory()->comCatalogo()->create();
        $this->admin();
        $token = $this->tokenDo($this->postJson("/api/admin/fazendas/{$outra->id}/convites", ['nome' => 'Vet', 'perfil' => 'GESTOR'])->json('data.link'));
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/convites/{$token}/aceitar", ['modo' => 'existente', 'usuario' => 'vet', 'senha' => 'errada'])
            ->assertStatus(422)->assertJsonPath('errors.0.detail', 'Usuário ou senha inválidos.');
        $this->postJson("/api/convites/{$token}/aceitar", ['modo' => 'existente', 'usuario' => 'vet', 'senha' => 'senha123'])
            ->assertOk()->assertJsonCount(2, 'data.fazendas');

        $this->assertSame(Perfil::GESTOR, $veterinario->vinculos()->where('fazenda_id', $outra->id)->value('perfil'));
    }

    public function test_convite_expirado_nao_vale(): void
    {
        $token = $this->tokenDo($this->postJson('/api/usuarios/convites', ['nome' => 'Zé', 'perfil' => 'CASEIRO'])->json('data.link'));
        $this->travel(8)->days();

        $this->getJson("/api/convites/{$token}")->assertNotFound();
    }

    public function test_gestor_convida_lista_e_cancela_convites_da_fazenda(): void
    {
        $criado = $this->postJson('/api/usuarios/convites', ['nome' => 'Zé', 'perfil' => 'CASEIRO', 'telefone' => '(34) 98888-0000'])
            ->assertCreated()->assertJsonPath('data.perfil', 'CASEIRO');
        $this->assertStringStartsWith('https://wa.me/5534988880000?text=', $criado->json('data.whatsapp'));
        Notification::assertNothingSent();

        $this->getJson('/api/usuarios/convites')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nome', 'Zé');
        $this->postJson("/api/usuarios/convites/{$criado->json('data.id')}/cancelamento")->assertNoContent();

        $this->getJson('/api/usuarios/convites')->assertJsonCount(0, 'data');
        $this->getJson('/api/convites/'.$this->tokenDo($criado->json('data.link')))->assertNotFound();
    }

    public function test_caseiro_nao_convida(): void
    {
        $this->entrarComo(User::factory()->caseiro()->create());

        $this->postJson('/api/usuarios/convites', ['nome' => 'Zé', 'perfil' => 'GESTOR'])->assertForbidden();
    }

    public function test_esqueci_a_senha_manda_link_so_para_quem_tem_email_sem_revelar_quem_existe(): void
    {
        $comEmail = User::factory()->create(['usuario' => 'maria', 'email' => 'maria@exemplo.com']);
        User::factory()->caseiro()->create(['usuario' => 'joao']);
        $this->app['auth']->forgetGuards();

        foreach (['maria', 'joao', 'ninguem'] as $usuario) {
            $this->postJson('/api/senha/esqueci', ['usuario' => $usuario])->assertStatus(202)
                ->assertJsonPath('data.mensagem', 'Se houver um e-mail cadastrado para este acesso, o link para criar uma nova senha foi enviado. Sem e-mail, peça o link ao gestor da fazenda.');
        }

        Notification::assertSentTo($comEmail, LinkNovaSenha::class);
        Notification::assertCount(1);
    }

    public function test_link_de_senha_do_gestor_troca_a_senha_uma_vez_e_encerra_as_sessoes(): void
    {
        $caseiro = User::factory()->caseiro()->create(['telefone' => '34977776666']);
        $caseiro->createToken('celular');

        $link = $this->postJson("/api/usuarios/{$caseiro->id}/link-senha")->assertOk()
            ->assertJsonPath('data.whatsapp', fn ($w) => str_starts_with($w, 'https://wa.me/5534977776666?text='))->json('data.link');
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/senha/redefinir', ['token' => $this->tokenDo($link), 'senha' => 'nova1234', 'senha_confirmation' => 'nova1234'])->assertOk();
        $this->assertTrue(Hash::check('nova1234', $caseiro->fresh()->password));
        $this->assertSame(0, $caseiro->tokens()->count());

        $this->postJson('/api/senha/redefinir', ['token' => $this->tokenDo($link), 'senha' => 'outra123', 'senha_confirmation' => 'outra123'])
            ->assertNotFound()->assertJsonPath('errors.0.code', 'LINK_INVALIDO');
    }

    public function test_gestor_nao_gera_link_de_senha_para_quem_nao_e_so_da_fazenda_dele(): void
    {
        $outra = Fazenda::factory()->create();
        $deFora = $this->naFazenda($outra, fn () => User::factory()->create());
        $deDoisClientes = User::factory()->caseiro()->create();
        $deDoisClientes->vincular($outra, Perfil::GESTOR);

        $this->postJson("/api/usuarios/{$deFora->id}/link-senha")->assertNotFound();
        $this->postJson("/api/usuarios/{$deDoisClientes->id}/link-senha")->assertForbidden()->assertJsonPath('errors.0.code', 'USUARIO_DE_OUTRO_CLIENTE');
    }

    public function test_suporte_entra_na_fazenda_so_com_acesso_registrado_e_ate_encerrar(): void
    {
        Animal::factory()->create(['brinco' => 'SB-1']);
        $this->admin();
        $cabecalho = ['X-Fazenda' => 1];

        $this->getJson('/api/animais', $cabecalho)->assertForbidden();

        $this->postJson('/api/admin/fazendas/1/suporte', ['motivo' => 'Cliente pediu ajuda no fechamento do mês'])
            ->assertCreated()->assertJsonPath('data.suporte', true);
        $this->getJson('/api/animais', $cabecalho)->assertOk()->assertJsonPath('data.0.brinco', 'SB-1');
        $this->getJson('/api/eu')->assertJsonPath('fazendas.0.suporte', true)->assertJsonPath('admin', true);
        $this->assertSame('Cliente pediu ajuda no fechamento do mês', AcessoSuporte::sole()->motivo);

        $this->deleteJson('/api/admin/fazendas/1/suporte')->assertNoContent();
        $this->getJson('/api/animais', $cabecalho)->assertForbidden();
    }

    public function test_suporte_expira_sozinho(): void
    {
        $this->admin();
        $this->postJson('/api/admin/fazendas/1/suporte', ['motivo' => 'Conferir estoque'])->assertCreated();

        $this->travel(AcessoSuporte::HORAS * 60 + 1)->minutes();

        $this->getJson('/api/animais', ['X-Fazenda' => 1])->assertForbidden();
    }

    public function test_so_o_admin_acessa_a_administracao(): void
    {
        $this->getJson('/api/admin/clientes')->assertForbidden();
        $this->postJson('/api/admin/fazendas/1/suporte', ['motivo' => 'tentativa'])->assertForbidden();
    }

    public function test_cliente_suspenso_fica_sem_acesso_ate_reativar(): void
    {
        $gestor = User::factory()->create(['usuario' => 'gestor1', 'password' => 'senha123']);
        $this->admin();

        $this->patchJson('/api/admin/clientes/1', ['ativo' => false])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['usuario' => 'gestor1', 'senha' => 'senha123'])->assertStatus(422);

        Cliente::whereKey(1)->update(['ativo' => true]);
        $this->postJson('/api/login', ['usuario' => 'gestor1', 'senha' => 'senha123'])->assertOk();
        $this->assertNotNull($gestor);
    }

    public function test_admin_entra_sem_fazenda(): void
    {
        $admin = User::factory()->create(['usuario' => 'adm', 'password' => 'senha123']);
        $admin->vinculos()->delete();
        $admin->forceFill(['admin_plataforma' => true])->save();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/login', ['usuario' => 'adm', 'senha' => 'senha123'])
            ->assertOk()->assertJsonPath('data.admin', true)->assertJsonCount(0, 'data.fazendas');
    }

    public function test_dono_ve_as_fazendas_do_cliente_e_cria_outra(): void
    {
        $this->usuarioLogado->clientesGerenciados()->attach(1, ['papel' => 'DONO']);

        $this->getJson('/api/eu')->assertJsonPath('dono', true);
        $this->getJson('/api/cliente')->assertOk()->assertJsonCount(1, 'data.fazendas')->assertJsonPath('data.fazendas.0.usuarios', 1);

        $id = $this->postJson('/api/cliente/fazendas', ['nome' => 'Retiro Novo', 'uf' => 'go'])->assertCreated()
            ->assertJsonPath('data.uf', 'GO')->assertJsonPath('data.perfil', 'GESTOR')->json('data.id');
        $this->assertSame(Perfil::GESTOR, $this->usuarioLogado->vinculos()->where('fazenda_id', $id)->value('perfil'));
        $this->getJson('/api/animais', ['X-Fazenda' => $id])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_gestor_que_nao_e_dono_nao_mexe_na_conta_e_dono_nao_altera_fazenda_de_outro_cliente(): void
    {
        $this->getJson('/api/cliente')->assertForbidden();

        $this->usuarioLogado->clientesGerenciados()->attach(1, ['papel' => 'DONO']);
        $deOutroCliente = Fazenda::factory()->create();
        $this->patchJson("/api/cliente/fazendas/{$deOutroCliente->id}", ['nome' => 'Invadida'])->assertNotFound();
    }

    public function test_dono_exporta_todos_os_dados_do_cliente_sem_senhas(): void
    {
        Storage::fake('public');
        $this->usuarioLogado->clientesGerenciados()->attach(1, ['papel' => 'DONO']);
        Animal::factory()->create(['brinco' => 'EXP-1']);
        Storage::disk('public')->put('fazendas/1/animais/1/foto.webp', 'imagem');

        $resposta = $this->get('/api/cliente/exportacao')->assertOk()->assertDownload();
        $zip = new ZipArchive;
        $zip->open($resposta->baseResponse->getFile()->getPathname());

        $this->assertSame('EXP-1', json_decode($zip->getFromName('fazenda-1/animais.json'), true)[0]['brinco']);
        $this->assertSame('imagem', $zip->getFromName('fazenda-1/arquivos/fotos/animais/1/foto.webp'));
        $this->assertStringNotContainsString('password', $zip->getFromName('usuarios.json'));
        $zip->close();
    }

    public function test_excluir_cliente_apaga_tudo_dele_e_preserva_os_outros(): void
    {
        $cliente = Cliente::factory()->create(['nome' => 'Cancelado', 'ativo' => false]);
        $fazenda = Fazenda::factory()->comCatalogo()->create(['cliente_id' => $cliente->id]);
        $soDele = $this->naFazenda($fazenda, function () {
            $mae = Animal::factory()->create();
            Animal::factory()->create(['mae_id' => $mae->id]);

            return User::factory()->create();
        });
        $compartilhado = User::factory()->create();
        $compartilhado->vincular($fazenda, Perfil::CASEIRO);
        $daFazenda1 = Animal::factory()->create();

        $this->artisan('pecuaria:excluir-cliente', ['cliente' => $cliente->id])
            ->expectsQuestion('Digite o nome do cliente para confirmar', 'Cancelado')->assertSuccessful();

        $this->assertModelMissing($cliente);
        $this->assertModelMissing($fazenda);
        $this->assertModelMissing($soDele);
        $this->assertModelExists($compartilhado);
        $this->assertDatabaseMissing('animais', ['fazenda_id' => $fazenda->id]);
        $this->assertDatabaseMissing('categorias_financeiras', ['fazenda_id' => $fazenda->id]);
        $this->assertModelExists($daFazenda1);
    }

    public function test_excluir_cliente_exige_suspensao_e_o_nome_certo(): void
    {
        $this->artisan('pecuaria:excluir-cliente', ['cliente' => 1])->assertFailed();

        Cliente::whereKey(1)->update(['ativo' => false]);
        $this->artisan('pecuaria:excluir-cliente', ['cliente' => 1])
            ->expectsQuestion('Digite o nome do cliente para confirmar', 'outro nome')->assertFailed();

        $this->assertModelExists(Cliente::find(1));
    }

    public function test_paginas_publicas_tem_limite_de_tentativas(): void
    {
        $this->app['auth']->forgetGuards();
        foreach (range(1, 10) as $i) {
            $this->getJson('/api/convites/token-errado-'.$i)->assertNotFound();
        }

        $this->getJson('/api/convites/mais-um')->assertStatus(429);
        $this->assertSame(0, Convite::count());
    }
}

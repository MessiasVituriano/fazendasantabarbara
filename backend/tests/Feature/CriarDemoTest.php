<?php

namespace Tests\Feature;

use App\Enums\Perfil;
use App\Models\Animal;
use App\Models\Cliente;
use App\Models\Fazenda;
use App\Models\Lancamento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Fazenda de demonstração criada por comando (também em produção), isolada das fazendas reais. */
class CriarDemoTest extends TestCase
{
    use RefreshDatabase;

    private function demo(): Fazenda
    {
        return Cliente::where('demonstracao', true)->sole()->fazendas()->sole();
    }

    public function test_cria_a_demonstracao_com_dados_e_da_acesso_de_gestor_sem_tocar_na_fazenda_real(): void
    {
        $real = Animal::factory()->create(['brinco' => 'REAL-1']);
        $eu = User::factory()->create(['usuario' => 'messias']);

        $this->artisan('pecuaria:criar-demo', ['--usuario' => ['messias']])->assertSuccessful();

        $demo = $this->demo();
        $this->assertSame(Perfil::GESTOR, $eu->vinculos()->where('fazenda_id', $demo->id)->value('perfil'));
        $this->naFazenda($demo, function () {
            $this->assertGreaterThan(20, Animal::count());
            $this->assertGreaterThan(0, Lancamento::count());
        });
        $this->assertSame(1, Animal::count()); // a fazenda 1 continua só com o animal real
        $this->assertModelExists($real);
        $this->assertDatabaseMissing('users', ['usuario' => 'gestor']);
    }

    public function test_rodar_de_novo_so_da_acesso_e_recriar_gera_outra_mantendo_quem_tinha_acesso(): void
    {
        User::factory()->create(['usuario' => 'messias']);
        $vendedor = User::factory()->caseiro()->create(['usuario' => 'vendedor']);
        $this->artisan('pecuaria:criar-demo', ['--usuario' => ['messias']])->assertSuccessful();
        $primeira = $this->demo();

        $this->artisan('pecuaria:criar-demo', ['--usuario' => ['vendedor']])->expectsOutputToContain('já existe')->assertSuccessful();
        $this->assertSame($primeira->id, $this->demo()->id);

        $this->artisan('pecuaria:criar-demo', ['--recriar' => true])->assertSuccessful();

        $nova = $this->demo();
        $this->assertNotSame($primeira->id, $nova->id);
        $this->assertModelMissing($primeira);
        $this->assertTrue($vendedor->vinculos()->where('fazenda_id', $nova->id)->exists());
        $this->assertModelExists($vendedor);
    }

    public function test_usuario_inexistente_ou_ausente_nao_cria_nada(): void
    {
        $this->artisan('pecuaria:criar-demo', ['--usuario' => ['ninguem']])->expectsOutputToContain('não encontrado')->assertFailed();
        $this->artisan('pecuaria:criar-demo')->assertFailed();

        $this->assertSame(0, Cliente::where('demonstracao', true)->count());
    }
}

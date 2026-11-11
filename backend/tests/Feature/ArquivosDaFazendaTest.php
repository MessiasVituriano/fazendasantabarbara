<?php

namespace Tests\Feature;

use App\Models\AnexoLancamento;
use App\Models\Animal;
use App\Models\CategoriaFinanceira;
use App\Models\FotoAnimal;
use App\Models\Lancamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Fotos e anexos ficam na pasta da fazenda; os gravados antes da multipropriedade são movidos para lá sem perda. */
class ArquivosDaFazendaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function lancamento(): Lancamento
    {
        return Lancamento::create([
            'tipo' => 'DESPESA', 'descricao' => 'Sal', 'categoria_id' => CategoriaFinanceira::porChave('suplementacao_mineral')->id,
            'valor' => 100, 'data_competencia' => '2026-11-01', 'data_vencimento' => '2026-11-10',
        ]);
    }

    public function test_foto_e_anexo_novos_vao_para_a_pasta_da_fazenda(): void
    {
        $animal = Animal::factory()->create();
        $lancamento = $this->lancamento();

        $this->post("/api/animais/{$animal->id}/fotos", ['foto' => UploadedFile::fake()->image('vaca.jpg', 800, 600)], ['Accept' => 'application/json'])
            ->assertCreated();
        $this->post("/api/financeiro/lancamentos/{$lancamento->id}/anexos", ['arquivo' => UploadedFile::fake()->create('nota.pdf', 50, 'application/pdf')])
            ->assertCreated();

        $this->assertStringStartsWith("fazendas/1/animais/{$animal->id}/", FotoAnimal::sole()->caminho);
        $this->assertStringStartsWith("fazendas/1/lancamentos/{$lancamento->id}/", AnexoLancamento::sole()->caminho);
    }

    public function test_organizar_move_arquivos_antigos_e_pode_rodar_de_novo(): void
    {
        $animal = Animal::factory()->create();
        Storage::disk('public')->put('animais/7/a.webp', 'imagem');
        Storage::disk('public')->put('animais/7/a_mini.webp', 'mini');
        $foto = $animal->fotos()->create([
            'caminho' => 'animais/7/a.webp', 'caminho_miniatura' => 'animais/7/a_mini.webp',
            'largura' => 10, 'altura' => 10, 'data_registro' => today(), 'principal' => true,
        ]);
        Storage::disk('local')->put('lancamentos/3/nota.pdf', 'pdf');
        $anexo = AnexoLancamento::create([
            'lancamento_id' => $this->lancamento()->id, 'nome_original' => 'nota.pdf', 'caminho' => 'lancamentos/3/nota.pdf', 'mime' => 'application/pdf', 'tamanho' => 3,
        ]);

        $this->artisan('pecuaria:organizar-arquivos')->assertSuccessful();

        $this->assertSame('fazendas/1/animais/7/a.webp', $foto->fresh()->caminho);
        $this->assertSame('fazendas/1/animais/7/a_mini.webp', $foto->fresh()->caminho_miniatura);
        $this->assertSame('fazendas/1/lancamentos/3/nota.pdf', $anexo->fresh()->caminho);
        $this->assertSame('imagem', Storage::disk('public')->get('fazendas/1/animais/7/a.webp'));
        $this->assertSame('pdf', Storage::disk('local')->get('fazendas/1/lancamentos/3/nota.pdf'));
        Storage::disk('public')->assertMissing(['animais/7/a.webp', 'animais/7/a_mini.webp']);
        Storage::disk('local')->assertMissing('lancamentos/3/nota.pdf');

        $this->artisan('pecuaria:organizar-arquivos')->expectsOutputToContain('0 registro(s) movido(s)')->assertSuccessful();
    }

    public function test_arquivo_ausente_mantem_o_registro_e_desfaz_a_copia(): void
    {
        $animal = Animal::factory()->create();
        Storage::disk('public')->put('animais/7/b.webp', 'imagem');
        $foto = $animal->fotos()->create([
            'caminho' => 'animais/7/b.webp', 'caminho_miniatura' => 'animais/7/b_mini.webp',
            'largura' => 10, 'altura' => 10, 'data_registro' => today(), 'principal' => true,
        ]);

        $this->artisan('pecuaria:organizar-arquivos')->assertFailed();

        $this->assertSame('animais/7/b.webp', $foto->fresh()->caminho);
        Storage::disk('public')->assertExists('animais/7/b.webp');
        Storage::disk('public')->assertMissing('fazendas/1/animais/7/b.webp');
    }

    public function test_nome_do_produto_e_publico(): void
    {
        config(['pecuaria.produto' => 'Gestão Rural Teste']);
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/sistema', ['Authorization' => ''])->assertOk()->assertJsonPath('data.produto', 'Gestão Rural Teste');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\FotoAnimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FotoAnimalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function enviar(Animal $animal, array $extra = [])
    {
        return $this->post("/api/animais/{$animal->id}/fotos", [
            'foto' => UploadedFile::fake()->image('vaca.jpg', 3000, 2000),
            ...$extra,
        ], ['Accept' => 'application/json']);
    }

    public function test_upload_reduz_imagem_gera_miniatura_e_vira_principal(): void
    {
        $vaca = Animal::factory()->create();

        $resposta = $this->enviar($vaca, ['legenda' => 'Perfil esquerdo'])
            ->assertCreated()
            ->assertJsonPath('data.principal', true)
            ->assertJsonPath('data.largura', 1600)
            ->assertJsonPath('data.altura', 1067)
            ->assertJsonPath('data.legenda', 'Perfil esquerdo');

        $foto = FotoAnimal::first();
        Storage::disk('public')->assertExists([$foto->caminho, $foto->caminho_miniatura]);
        $this->assertSame([320, 320], array_slice(getimagesizefromstring(Storage::disk('public')->get($foto->caminho_miniatura)), 0, 2));
        $this->assertStringEndsWith('_mini.webp', $resposta->json('data.miniatura_url'));

        $this->getJson("/api/animais/{$vaca->id}")->assertJsonPath('data.foto.id', $foto->id);
    }

    public function test_segunda_foto_nao_substitui_a_principal_a_menos_que_pedido(): void
    {
        $vaca = Animal::factory()->create();
        $this->enviar($vaca);
        $this->enviar($vaca)->assertJsonPath('data.principal', false);
        $terceira = $this->enviar($vaca, ['principal' => 1])->assertJsonPath('data.principal', true)->json('data.id');

        $this->assertSame(1, $vaca->fotos()->where('principal', true)->count());
        $this->getJson("/api/animais/{$vaca->id}/fotos")->assertJsonCount(3, 'data')->assertJsonPath('data.0.id', $terceira);
    }

    public function test_definir_principal_e_excluir_promove_outra(): void
    {
        $vaca = Animal::factory()->create();
        $primeira = $this->enviar($vaca)->json('data.id');
        $segunda = $this->enviar($vaca)->json('data.id');

        $this->patchJson("/api/animais/{$vaca->id}/fotos/{$segunda}", ['principal' => true])->assertJsonPath('data.principal', true);
        $this->assertFalse(FotoAnimal::find($primeira)->principal);

        $caminho = FotoAnimal::find($segunda)->caminho;
        $this->deleteJson("/api/animais/{$vaca->id}/fotos/{$segunda}")->assertNoContent();

        Storage::disk('public')->assertMissing($caminho);
        $this->assertTrue(FotoAnimal::find($primeira)->principal);
    }

    public function test_foto_de_outro_animal_retorna_404(): void
    {
        $vaca = Animal::factory()->create();
        $outra = Animal::factory()->create();
        $foto = $this->enviar($vaca)->json('data.id');

        $this->deleteJson("/api/animais/{$outra->id}/fotos/{$foto}")->assertNotFound();
    }

    public function test_arquivo_que_nao_e_imagem_e_rejeitado(): void
    {
        $vaca = Animal::factory()->create();

        $this->post("/api/animais/{$vaca->id}/fotos", ['foto' => UploadedFile::fake()->create('planilha.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.source.pointer', '/foto');
    }

    public function test_excluir_animal_remove_arquivos_das_fotos(): void
    {
        $vaca = Animal::factory()->create();
        $this->enviar($vaca);
        $foto = FotoAnimal::first();

        $this->deleteJson("/api/animais/{$vaca->id}")->assertNoContent();

        Storage::disk('public')->assertMissing([$foto->caminho, $foto->caminho_miniatura]);
    }
}

<?php

namespace App\Services;

use App\Exceptions\RegraNegocioException;
use App\Models\FotoAnimal;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Normaliza fotos de celular com GD: corrige a rotação (EXIF), limita o tamanho
 * e gera a miniatura quadrada usada no avatar. Ambas são gravadas em WebP.
 */
class ProcessadorFoto
{
    /** @return array{caminho: string, caminho_miniatura: string, largura: int, altura: int} */
    public function salvar(UploadedFile $arquivo, string $pasta): array
    {
        $imagem = @imagecreatefromstring((string) file_get_contents($arquivo->getRealPath()));
        if (! $imagem instanceof GdImage) {
            throw new RegraNegocioException('Não foi possível ler a imagem enviada. Use JPG, PNG ou WebP.', 'FOTO_INVALIDA', 422, '/foto');
        }
        $imagem = $this->corrigirOrientacao($imagem, $arquivo);

        $config = config('pecuaria.fotos');
        $principal = $this->redimensionar($imagem, $config['lado_maximo']);
        $miniatura = $this->recortarQuadrado($imagem, $config['miniatura']);

        $nome = Str::uuid();
        $resultado = [
            'caminho' => "{$pasta}/{$nome}.webp",
            'caminho_miniatura' => "{$pasta}/{$nome}_mini.webp",
            'largura' => imagesx($principal),
            'altura' => imagesy($principal),
        ];

        $disco = FotoAnimal::disco();
        $disco->put($resultado['caminho'], $this->webp($principal, 82), 'public');
        $disco->put($resultado['caminho_miniatura'], $this->webp($miniatura, 80), 'public');

        return $resultado;
    }

    private function corrigirOrientacao(GdImage $imagem, UploadedFile $arquivo): GdImage
    {
        if (! function_exists('exif_read_data') || ! in_array($arquivo->getMimeType(), ['image/jpeg', 'image/jpg'])) {
            return $imagem;
        }
        $orientacao = @exif_read_data($arquivo->getRealPath())['Orientation'] ?? 1;

        return match ((int) $orientacao) {
            3 => imagerotate($imagem, 180, 0),
            6 => imagerotate($imagem, -90, 0),
            8 => imagerotate($imagem, 90, 0),
            default => $imagem,
        };
    }

    private function redimensionar(GdImage $imagem, int $ladoMaximo): GdImage
    {
        [$l, $a] = [imagesx($imagem), imagesy($imagem)];
        $fator = min(1, $ladoMaximo / max($l, $a));

        return $fator < 1 ? imagescale($imagem, (int) round($l * $fator), (int) round($a * $fator)) : $imagem;
    }

    private function recortarQuadrado(GdImage $imagem, int $lado): GdImage
    {
        [$l, $a] = [imagesx($imagem), imagesy($imagem)];
        $menor = min($l, $a);
        $destino = imagecreatetruecolor($lado, $lado);
        imagecopyresampled($destino, $imagem, 0, 0, (int) (($l - $menor) / 2), (int) (($a - $menor) / 2), $lado, $lado, $menor, $menor);

        return $destino;
    }

    private function webp(GdImage $imagem, int $qualidade): string
    {
        ob_start();
        imagewebp($imagem, null, $qualidade);

        return (string) ob_get_clean();
    }
}

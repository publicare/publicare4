<?php

declare(strict_types=1);

namespace Pbl\Arquivos;

use Pbl\Caminhos;

/**
 * Miniaturas de imagens, geradas sob demanda e guardadas em `dados/cache/miniaturas/` (nunca no `docroot`, como o resto do
 * cache). Só existe se o GD estiver disponível; sem ele, a tela mostra o arquivo original (sem miniatura). Tamanho só de uma
 * lista fechada (nunca o que vier na URL): evita gerar um arquivo por pixel pedido.
 */
final class ServicoDeMiniaturas
{
    /** @var list<int> */
    public const TAMANHOS = [64, 150, 400, 800];

    public function __construct(private readonly Caminhos $caminhos, private readonly ArmazenamentoDeArquivos $armazenamento)
    {
    }

    public static function disponivel(): bool
    {
        return extension_loaded('gd');
    }

    public static function tamanhoValido(int $tamanho): bool
    {
        return in_array($tamanho, self::TAMANHOS, true);
    }

    /** Caminho da miniatura em cache (gera se ainda não existir). `null` se não for imagem, o GD não estiver disponível ou falhar. */
    public function caminho(Arquivo $arquivo, int $tamanho): ?string
    {
        if (!$arquivo->ehImagem() || !self::disponivel() || !self::tamanhoValido($tamanho)) {
            return null;
        }
        $destino = $this->pastaCache() . "/{$arquivo->chave}-{$tamanho}.jpg";
        if (is_file($destino)) {
            return $destino;
        }
        $original = $this->armazenamento->caminho($arquivo->chave, $arquivo->extensao);
        if (!is_file($original)) {
            return null;
        }
        $conteudo = file_get_contents($original);
        $imagem = $conteudo === false ? false : @imagecreatefromstring($conteudo);
        if ($imagem === false) {
            return null;
        }
        $larguraOriginal = imagesx($imagem);
        $alturaOriginal = imagesy($imagem);
        $escala = min(1.0, $tamanho / max($larguraOriginal, $alturaOriginal));
        $larguraNova = max(1, (int) round($larguraOriginal * $escala));
        $alturaNova = max(1, (int) round($alturaOriginal * $escala));
        $miniatura = imagecreatetruecolor($larguraNova, $alturaNova);
        // Fundo branco: imagens com transparência (PNG/GIF) não viram preto ao virar JPEG.
        $branco = imagecolorallocate($miniatura, 255, 255, 255);
        imagefill($miniatura, 0, 0, $branco === false ? 0 : $branco);
        imagecopyresampled($miniatura, $imagem, 0, 0, 0, 0, $larguraNova, $alturaNova, $larguraOriginal, $alturaOriginal);
        imagedestroy($imagem);

        if (!is_dir($this->pastaCache()) && !mkdir($this->pastaCache(), 0775, true) && !is_dir($this->pastaCache())) {
            imagedestroy($miniatura);

            return null;
        }
        $temporario = $this->pastaCache() . '/.tmp-' . bin2hex(random_bytes(8));
        $ok = imagejpeg($miniatura, $temporario, 82);
        imagedestroy($miniatura);
        if (!$ok || !rename($temporario, $destino)) {
            @unlink($temporario);

            return null;
        }

        return $destino;
    }

    private function pastaCache(): string
    {
        return $this->caminhos->cache() . '/miniaturas';
    }
}

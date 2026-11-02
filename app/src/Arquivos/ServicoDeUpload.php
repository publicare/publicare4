<?php

declare(strict_types=1);

namespace Pbl\Arquivos;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Valida e guarda um arquivo enviado por `multipart/form-data` numa propriedade `arquivo`/`imagem` (fluxo 7, ADR 0014).
 *
 * Ordem das defesas: erro de envio do PHP (`upload_max_filesize`/`post_max_size`, mensagem clara) → tamanho configurado da
 * propriedade → **tipo real do conteúdo** por `finfo` (nunca a extensão do nome enviado) contra a whitelist da propriedade →
 * para imagem, decodificação de verdade pelo GD (um `.jpg` que não é uma imagem de verdade é recusado) e **recodificação**
 * (recria os pixels; o payload escondido e o EXIF somem) → sem GD, só confere os bytes mágicos do formato (mais fraco: fica
 * registrado em `sanitizada = false`). O nome enviado nunca é usado como caminho: o arquivo em disco leva uma chave aleatória
 * e a extensão vem sempre da whitelist, nunca do nome do cliente.
 */
final class ServicoDeUpload
{
    public function __construct(
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly ArquivoRepositorio $arquivos,
    ) {
    }

    /**
     * @param array{nome: string, tipo: string, tipos?: list<string>, tamanho_max_mb?: int} $propriedade
     * @throws ErroDeUpload com uma mensagem segura para mostrar a quem enviou
     */
    public function enviar(UploadedFile $arquivo, array $propriedade, int $siteId, int $objetoId, ?int $criadoPor): Arquivo
    {
        $this->confirmarEnvio($arquivo);
        $limite = TiposDeArquivo::limiteBytes($propriedade);
        $tamanho = $arquivo->getSize() ?: 0;
        if ($tamanho <= 0) {
            throw new ErroDeUpload('O arquivo está vazio.');
        }
        if ($tamanho > $limite) {
            throw new ErroDeUpload('O arquivo tem ' . $this->mb($tamanho) . ' MB; o máximo para este campo é ' . intdiv($limite, 1024 * 1024) . ' MB.');
        }

        $conteudo = file_get_contents($arquivo->getPathname());
        if ($conteudo === false || $conteudo === '') {
            throw new ErroDeUpload('Não foi possível ler o arquivo enviado.');
        }

        $aceitas = TiposDeArquivo::aceitasPor($propriedade);
        if ($aceitas === []) {
            throw new ErroDeUpload('Este campo não aceita arquivos.');
        }
        $extensao = $this->detectarExtensao($conteudo, $aceitas);
        if ($extensao === null) {
            throw new ErroDeUpload('Tipo de arquivo não permitido. Os tipos aceitos aqui são: ' . implode(', ', $aceitas) . '.');
        }

        $tipo = TiposDeArquivo::da($extensao) ?? throw new ErroDeUpload('Tipo de arquivo não permitido.');
        $largura = null;
        $altura = null;
        $sanitizada = false;
        if ($tipo['imagem']) {
            [$conteudo, $largura, $altura, $sanitizada] = $this->tratarImagem($conteudo, $extensao);
        }

        $sha256 = hash('sha256', $conteudo);
        $chave = $this->arquivos->chaveExistente($sha256, $extensao) ?? ArmazenamentoDeArquivos::novaChave();
        $this->armazenamento->gravar($chave, $extensao, $conteudo);

        return $this->arquivos->criar(
            $siteId,
            $objetoId,
            (string) $propriedade['nome'],
            $chave,
            $this->nomeSaneado($arquivo->getClientOriginalName()),
            $extensao,
            $tipo['mime'],
            strlen($conteudo),
            $sha256,
            $largura,
            $altura,
            $sanitizada,
            $criadoPor,
        );
    }

    private function confirmarEnvio(UploadedFile $arquivo): void
    {
        $erro = $arquivo->getError();
        if ($erro === \UPLOAD_ERR_OK) {
            return;
        }
        throw new ErroDeUpload(match ($erro) {
            \UPLOAD_ERR_INI_SIZE => 'O arquivo é maior do que o servidor aceita (upload_max_filesize).',
            \UPLOAD_ERR_FORM_SIZE => 'O arquivo é maior do que o formulário aceita.',
            \UPLOAD_ERR_PARTIAL => 'O envio foi interrompido. Tente de novo.',
            \UPLOAD_ERR_NO_TMP_DIR, \UPLOAD_ERR_CANT_WRITE, \UPLOAD_ERR_EXTENSION => 'O servidor não conseguiu guardar o arquivo. Tente de novo em instantes.',
            default => 'Não foi possível enviar o arquivo.',
        });
    }

    /** @param list<string> $aceitas */
    private function detectarExtensao(string $conteudo, array $aceitas): ?string
    {
        $finfo = new \finfo(\FILEINFO_MIME_TYPE);
        $detectado = $finfo->buffer($conteudo) ?: 'application/octet-stream';
        foreach ($aceitas as $extensao) {
            $tipo = TiposDeArquivo::da($extensao);
            if ($tipo === null || !in_array($detectado, $tipo['detectados'], true)) {
                continue;
            }
            // Para os formatos que são contêineres ZIP (docx, xlsx...), o finfo às vezes só diz "application/zip": confirma
            // os bytes mágicos do ZIP para não aceitar qualquer coisa só porque a lista de MIME inclui o genérico.
            if (str_contains($detectado, 'zip') || $detectado === 'application/octet-stream') {
                if (!str_starts_with($conteudo, "PK\x03\x04")) {
                    continue;
                }
            }

            return $extensao;
        }

        return null;
    }

    /** @return array{0: string, 1: int|null, 2: int|null, 3: bool} [conteúdo final, largura, altura, sanitizada] */
    private function tratarImagem(string $conteudo, string $extensao): array
    {
        if (!extension_loaded('gd')) {
            // Sem GD: só confere os bytes mágicos do formato (mais fraco que decodificar de verdade).
            if (!$this->cabecalhoDeImagemValido($conteudo, $extensao)) {
                throw new ErroDeUpload('O arquivo não parece ser uma imagem válida.');
            }
            $info = @getimagesizefromstring($conteudo);

            return [$conteudo, is_array($info) ? $info[0] : null, is_array($info) ? $info[1] : null, false];
        }

        $imagem = @imagecreatefromstring($conteudo);
        if ($imagem === false) {
            throw new ErroDeUpload('Não foi possível processar a imagem: o arquivo está corrompido ou não é uma imagem de verdade.');
        }
        $largura = imagesx($imagem);
        $altura = imagesy($imagem);
        // Recodificar (em vez de copiar os bytes enviados) recria só os pixels: qualquer payload escondido no arquivo
        // original e os metadados EXIF somem.
        ob_start();
        $ok = match ($extensao) {
            'jpg' => imagejpeg($imagem, null, 85),
            'png' => imagepng($imagem),
            'gif' => imagegif($imagem),
            'webp' => function_exists('imagewebp') && imagewebp($imagem, null, 85),
            default => false,
        };
        $novo = ob_get_clean();
        imagedestroy($imagem);
        if (!$ok || !is_string($novo) || $novo === '') {
            throw new ErroDeUpload('Não foi possível processar a imagem.');
        }

        return [$novo, $largura, $altura, true];
    }

    private function cabecalhoDeImagemValido(string $conteudo, string $extensao): bool
    {
        return match ($extensao) {
            'jpg' => str_starts_with($conteudo, "\xFF\xD8\xFF"),
            'png' => str_starts_with($conteudo, "\x89PNG\r\n\x1a\n"),
            'gif' => str_starts_with($conteudo, 'GIF87a') || str_starts_with($conteudo, 'GIF89a'),
            'webp' => str_starts_with($conteudo, 'RIFF') && substr($conteudo, 8, 4) === 'WEBP',
            default => false,
        };
    }

    private function nomeSaneado(?string $nomeOriginal): string
    {
        $nome = basename(trim((string) $nomeOriginal));
        $nome = preg_replace('/[\x00-\x1F\x7F]/', '', $nome) ?? '';
        $nome = mb_substr($nome, 0, 150);

        return $nome === '' ? 'arquivo' : $nome;
    }

    private function mb(int $bytes): string
    {
        return rtrim(rtrim(sprintf('%.2f', $bytes / (1024 * 1024)), '0'), '.');
    }
}

<?php

declare(strict_types=1);

namespace Pbl\Arquivos;

/**
 * A lista fechada de tipos que o PBL4 aceita guardar (ADR 0014). Cada tipo tem a extensão que o arquivo guardado leva, o tipo MIME
 * que o servidor entrega (sempre o desta tabela, nunca o que o navegador disse) e os tipos MIME que o `finfo` pode devolver ao
 * ler o conteúdo. Fora daqui nada entra: não há `php`, `html`, `svg`, `js` nem qualquer formato que um navegador execute.
 *
 * @phpstan-type Tipo array{mime: string, imagem: bool, detectados: list<string>, inline: bool}
 */
final class TiposDeArquivo
{
    /** Tamanho máximo padrão de uma propriedade `arquivo`/`imagem`, se a classe não configurar `tamanho_max_mb`. */
    public const PADRAO_MB = 10;
    /** Teto absoluto: nenhuma classe pode configurar mais que isto, mesmo que `upload_max_filesize`/`post_max_size` permitam. */
    public const TETO_MB = 100;

    /**
     * MIMEs genéricos de contêiner (nunca dizem, por si só, qual é o formato de verdade): o `finfo` às vezes devolve um
     * destes para `docx`/`xlsx`/`pptx`/`odt`/`ods`/`odp`, que por dentro são um ZIP. Quem recebe um destes precisa olhar
     * dentro do pacote (`ServicoDeUpload::identificarPacoteZip`) antes de aceitar.
     */
    public const MIME_GENERICO_DE_CONTEINER = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'];
    /** Extensões cujo formato por dentro é um ZIP (Office/OpenDocument): precisam da verificação extra acima. */
    public const BASEADOS_EM_ZIP = ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'];

    private const ZIP = self::MIME_GENERICO_DE_CONTEINER;

    /** @var array<string, Tipo> por extensão (minúscula, sem ponto) */
    private const TODOS = [
        'jpg' => ['mime' => 'image/jpeg', 'imagem' => true, 'detectados' => ['image/jpeg'], 'inline' => true],
        'png' => ['mime' => 'image/png', 'imagem' => true, 'detectados' => ['image/png'], 'inline' => true],
        'gif' => ['mime' => 'image/gif', 'imagem' => true, 'detectados' => ['image/gif'], 'inline' => true],
        'webp' => ['mime' => 'image/webp', 'imagem' => true, 'detectados' => ['image/webp'], 'inline' => true],
        'pdf' => ['mime' => 'application/pdf', 'imagem' => false, 'detectados' => ['application/pdf'], 'inline' => true],
        'txt' => ['mime' => 'text/plain; charset=utf-8', 'imagem' => false, 'detectados' => ['text/plain'], 'inline' => false],
        'csv' => ['mime' => 'text/csv; charset=utf-8', 'imagem' => false, 'detectados' => ['text/plain', 'text/csv', 'application/csv'], 'inline' => false],
        'docx' => ['mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'imagem' => false,
            'detectados' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', ...self::ZIP], 'inline' => false],
        'xlsx' => ['mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'imagem' => false,
            'detectados' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ...self::ZIP], 'inline' => false],
        'pptx' => ['mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'imagem' => false,
            'detectados' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', ...self::ZIP], 'inline' => false],
        'odt' => ['mime' => 'application/vnd.oasis.opendocument.text', 'imagem' => false,
            'detectados' => ['application/vnd.oasis.opendocument.text', ...self::ZIP], 'inline' => false],
        'ods' => ['mime' => 'application/vnd.oasis.opendocument.spreadsheet', 'imagem' => false,
            'detectados' => ['application/vnd.oasis.opendocument.spreadsheet', ...self::ZIP], 'inline' => false],
        'odp' => ['mime' => 'application/vnd.oasis.opendocument.presentation', 'imagem' => false,
            'detectados' => ['application/vnd.oasis.opendocument.presentation', ...self::ZIP], 'inline' => false],
    ];

    /** @return list<string> extensões de imagem (o padrão da propriedade `imagem`) */
    public static function imagens(): array
    {
        return array_keys(array_filter(self::TODOS, static fn (array $t): bool => $t['imagem']));
    }

    /** @return list<string> extensões de documento, isto é, tudo que não é imagem (o padrão da propriedade `arquivo`) */
    public static function documentos(): array
    {
        return array_keys(array_filter(self::TODOS, static fn (array $t): bool => !$t['imagem']));
    }

    /** @return list<string> todas as extensões aceitas pelo PBL4, imagem ou documento */
    public static function todas(): array
    {
        return array_keys(self::TODOS);
    }

    /** @return Tipo|null */
    public static function da(string $extensao): ?array
    {
        return self::TODOS[$extensao] ?? null;
    }

    /** Extensão do tipo MIME entregue (ao contrário do que se guardou), ou null. */
    public static function extensaoDoMime(string $mime): ?string
    {
        foreach (self::TODOS as $extensao => $t) {
            if ($t['mime'] === $mime) {
                return $extensao;
            }
        }

        return null;
    }

    /**
     * As extensões que uma propriedade aceita: a lista `tipos` da propriedade (cortada pela whitelist e, em `imagem`, só pelas
     * imagens), ou o padrão do tipo da propriedade.
     *
     * @param array<string, mixed> $propriedade
     * @return list<string>
     */
    public static function aceitasPor(array $propriedade): array
    {
        $base = ($propriedade['tipo'] ?? '') === 'imagem' ? self::imagens() : self::documentos();
        $escolhidas = $propriedade['tipos'] ?? null;
        if (!is_array($escolhidas) || $escolhidas === []) {
            return $base;
        }

        return array_values(array_filter($base, static fn (string $e): bool => in_array($e, $escolhidas, true)));
    }

    /**
     * Tamanho máximo em bytes para a propriedade: `tamanho_max_mb` da classe (até `TETO_MB`), ou `PADRAO_MB`.
     *
     * @param array<string, mixed> $propriedade
     */
    public static function limiteBytes(array $propriedade): int
    {
        $mb = $propriedade['tamanho_max_mb'] ?? self::PADRAO_MB;
        $mb = is_int($mb) && $mb > 0 ? min($mb, self::TETO_MB) : self::PADRAO_MB;

        return $mb * 1024 * 1024;
    }
}

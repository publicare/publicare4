<?php

declare(strict_types=1);

namespace Pbl\Arquivos;

/** Metadados de um arquivo guardado (a tabela `arquivo`). `chave` nomeia o arquivo em disco; nunca é derivada do nome enviado. */
final class Arquivo
{
    public function __construct(
        public readonly int $id,
        public readonly int $siteId,
        /** Objeto ao qual o arquivo foi anexado quando enviado; decide a visibilidade pública (ver `ArquivoController`). */
        public readonly int $objetoId,
        /** Nome técnico da propriedade da classe à qual o arquivo foi enviado (auditoria e filtro de `limpar-uploads`). */
        public readonly string $propriedade,
        public readonly string $chave,
        public readonly string $nome,
        public readonly string $extensao,
        public readonly string $mime,
        public readonly int $tamanho,
        public readonly string $sha256,
        public readonly ?int $largura,
        public readonly ?int $altura,
        public readonly bool $sanitizada,
        public readonly string $criadoEm,
        public readonly ?int $criadoPor,
    ) {
    }

    public function ehImagem(): bool
    {
        return (TiposDeArquivo::da($this->extensao)['imagem'] ?? false) === true;
    }
}

<?php

declare(strict_types=1);

namespace Pbl\Backup;

/** Uma cópia no disco, como o catálogo a descreve. `integro` é null quando a conferência não foi pedida. */
final class InfoDeBackup
{
    public const TIPOS = ['manual', 'agendada', 'pre-atualizacao', 'pre-restauracao'];

    public function __construct(
        public readonly string $arquivo,
        public readonly string $tipo,
        public readonly int $criadoEm,
        public readonly int $tamanho,
        public readonly string $versao,
        public readonly ?int $schema,
        public readonly ?bool $integro,
        public readonly bool $legado = false,
        public readonly bool $protegida = false,
    ) {
    }

    public function comProtecao(bool $protegida): self
    {
        return new self($this->arquivo, $this->tipo, $this->criadoEm, $this->tamanho, $this->versao, $this->schema, $this->integro, $this->legado, $protegida);
    }
}

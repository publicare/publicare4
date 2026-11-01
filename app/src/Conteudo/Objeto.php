<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

final class Objeto
{
    /**
     * @param array<string, string|int|float> $dados
     */
    public function __construct(
        public readonly int $id,
        public readonly int $siteId,
        public readonly int $classeId,
        public readonly ?int $paiId,
        public readonly string $caminho,
        public readonly string $titulo,
        public readonly string $urlAmigavel,
        public readonly string $status,
        public readonly array $dados,
        public readonly int $peso = 0,
        public readonly string $descricao = '',
        public readonly ?string $dataPublicacao = null,
        public readonly ?string $dataValidade = null,
        public readonly int $versao = 1,
        public readonly ?int $criadoPor = null,
    ) {
    }

    /** Publicado e dentro da vigência (`data_publicacao` já chegou, `data_validade` ainda não passou). */
    public function visivel(string $agoraIso): bool
    {
        return $this->status === 'publicado'
            && ($this->dataPublicacao === null || $this->dataPublicacao <= $agoraIso)
            && ($this->dataValidade === null || $this->dataValidade >= $agoraIso);
    }
}

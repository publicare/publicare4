<?php

declare(strict_types=1);

namespace Pbl\Acesso;

use Doctrine\DBAL\Connection;

/** Registro de ações sensíveis. Só acrescenta: a aplicação não tem como editar nem apagar uma linha. */
final class Auditoria
{
    public function __construct(private readonly Connection $conexao)
    {
    }

    /**
     * @param string $ator quem fez: `usuario:5`, `cli` ou `sistema`
     * @param array<string, scalar|null> $detalhe nunca coloque senha, token ou dado pessoal aqui
     */
    public function registrar(string $acao, string $ator, ?int $usuarioId = null, ?int $objetoId = null, array $detalhe = [], ?string $ipHash = null): void
    {
        $this->conexao->insert('auditoria', [
            'quando' => gmdate('c'),
            'usuario_id' => $usuarioId,
            'ator' => mb_substr($ator, 0, 100),
            'acao' => mb_substr($acao, 0, 60),
            'objeto_id' => $objetoId,
            'detalhe' => $detalhe === [] ? null : json_encode($detalhe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'ip_hash' => $ipHash,
        ]);
    }

    /** @return list<array{quando: string, ator: string, acao: string, objeto_id: ?int, detalhe: ?string}> o que foi guardado deste objeto, mais recentes primeiro */
    public function doObjeto(int $objetoId, int $limite = 100): array
    {
        $linhas = $this->conexao->fetchAllAssociative('SELECT quando, ator, acao, objeto_id, detalhe FROM auditoria WHERE objeto_id = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limite)), [$objetoId]);

        return array_map(static fn (array $l): array => [
            'quando' => (string) $l['quando'], 'ator' => (string) $l['ator'], 'acao' => (string) $l['acao'],
            'objeto_id' => (int) $l['objeto_id'], 'detalhe' => $l['detalhe'] === null ? null : (string) $l['detalhe'],
        ], $linhas);
    }

    /** @return list<array{quando: string, ator: string, acao: string, objeto_id: ?int, detalhe: ?string}> mais recentes primeiro */
    public function recentes(int $limite = 50): array
    {
        $linhas = $this->conexao->fetchAllAssociative('SELECT quando, ator, acao, objeto_id, detalhe FROM auditoria ORDER BY id DESC LIMIT ' . max(1, min(500, $limite)));

        return array_map(static fn (array $l): array => [
            'quando' => (string) $l['quando'], 'ator' => (string) $l['ator'], 'acao' => (string) $l['acao'],
            'objeto_id' => $l['objeto_id'] === null ? null : (int) $l['objeto_id'], 'detalhe' => $l['detalhe'] === null ? null : (string) $l['detalhe'],
        ], $linhas);
    }
}

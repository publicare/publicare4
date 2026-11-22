<?php

declare(strict_types=1);

namespace Pbl\Acesso;

use Doctrine\DBAL\Connection;

/** Registro de ações sensíveis. Só acrescenta: a aplicação não tem como editar nem apagar uma linha. */
final class Auditoria
{
    /** @param string|null $canal origem da ação (ex.: `cli`); vira `via` no detalhe (ADR 0032). O formato do ator não muda. */
    public function __construct(private readonly Connection $conexao, private readonly ?string $canal = null)
    {
    }

    /**
     * @param string $ator quem fez: `usuario:5`, `cli` ou `sistema`
     * @param array<string, scalar|null> $detalhe nunca coloque senha, token ou dado pessoal aqui
     */
    public function registrar(string $acao, string $ator, ?int $usuarioId = null, ?int $objetoId = null, array $detalhe = [], ?string $ipHash = null): void
    {
        if ($this->canal !== null) {
            $detalhe['via'] = $this->canal;
        }
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

    /**
     * Ids dos conteúdos que a conta criou ou editou por último (mais recente primeiro, sem repetir), a partir do registro que já existe;
     * é o que o Início usa em "Continuar de onde parou". Só ids: quem chama confere se ainda existe e se a pessoa ainda pode editar.
     *
     * @return list<int>
     */
    public function objetosEditadosPor(int $usuarioId, int $limite = 10): array
    {
        $linhas = $this->conexao->fetchFirstColumn("SELECT objeto_id FROM auditoria WHERE usuario_id = ? AND objeto_id IS NOT NULL AND acao IN ('objeto.atualizar', 'objeto.criar') ORDER BY id DESC LIMIT 60", [$usuarioId]);
        $ids = [];
        foreach ($linhas as $id) {
            $ids[(int) $id] = true;
            if (count($ids) >= max(1, $limite)) {
                break;
            }
        }

        return array_keys($ids);
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

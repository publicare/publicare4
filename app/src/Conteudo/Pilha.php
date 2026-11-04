<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

use Doctrine\DBAL\Connection;

/**
 * A pilha de cada conta (fase 5r, ADR 0025): objetos separados com "copiar" ou "recortar" para colar depois em outro lugar.
 * Guarda só referências (nada é copiado ou movido até colar). Uma pilha tem um único site e um único modo: separar algo de
 * outro site, ou no outro modo, começa uma pilha nova (o que já estava nela sai). Guarda a versão do objeto no momento em que
 * entrou, para o recorte recusar um objeto alterado por outra pessoa desde então.
 */
final class Pilha
{
    public const COPIAR = 'copiar';
    public const RECORTAR = 'recortar';
    /** Itens que cabem na pilha de uma conta (e que podem ser colados num único pedido). */
    public const LIMITE = 200;

    public function __construct(private readonly Connection $conexao)
    {
    }

    /**
     * Separa os objetos para colar. Esvazia a pilha antes se ela era de outro site ou de outro modo. Devolve quantos entraram
     * (os que já estavam são atualizados com a versão nova, sem contar duas vezes).
     *
     * @param list<Objeto> $objetos todos do mesmo site
     * @param array<int, int> $versoes objeto => versão que a pessoa estava vendo (o padrão é a versão de agora)
     * @throws \InvalidArgumentException modo inválido, site misto ou mais itens do que cabem
     */
    public function adicionar(int $usuarioId, string $modo, array $objetos, array $versoes = []): int
    {
        if (!in_array($modo, [self::COPIAR, self::RECORTAR], true)) {
            throw new \InvalidArgumentException('Modo da pilha inválido');
        }
        if ($objetos === []) {
            return 0;
        }
        $siteId = $objetos[0]->siteId;
        foreach ($objetos as $o) {
            if ($o->siteId !== $siteId) {
                throw new \InvalidArgumentException('Os itens da pilha precisam ser do mesmo site');
            }
        }

        return $this->conexao->transactional(function () use ($usuarioId, $modo, $objetos, $siteId, $versoes): int {
            $atual = $this->conexao->fetchAssociative('SELECT site_id, modo FROM pilha WHERE usuario_id = ? LIMIT 1', [$usuarioId]);
            if ($atual !== false && ((int) $atual['site_id'] !== $siteId || $atual['modo'] !== $modo)) {
                $this->esvaziar($usuarioId);
            }
            $agora = gmdate('c');
            foreach ($objetos as $o) {
                if ($this->conexao->fetchOne('SELECT 1 FROM pilha WHERE usuario_id = ? AND objeto_id = ?', [$usuarioId, $o->id]) !== false) {
                    $this->conexao->update('pilha', ['versao' => $versoes[$o->id] ?? $o->versao, 'criado_em' => $agora], ['usuario_id' => $usuarioId, 'objeto_id' => $o->id]);
                    continue;
                }
                if ($this->contar($usuarioId) >= self::LIMITE) {
                    throw new \InvalidArgumentException('A pilha comporta até ' . self::LIMITE . ' itens');
                }
                $this->conexao->insert('pilha', ['usuario_id' => $usuarioId, 'site_id' => $siteId, 'objeto_id' => $o->id, 'modo' => $modo, 'versao' => $versoes[$o->id] ?? $o->versao, 'criado_em' => $agora]);
            }

            return count($objetos);
        });
    }

    /**
     * Os itens da pilha na ordem em que entraram. Linhas de objetos que sumiram do banco ficam de fora.
     *
     * @return list<array{objeto_id: int, site_id: int, modo: string, versao: int}>
     */
    public function itens(int $usuarioId): array
    {
        $itens = [];
        foreach ($this->conexao->fetchAllAssociative('SELECT p.objeto_id, p.site_id, p.modo, p.versao FROM pilha p JOIN objeto o ON o.id = p.objeto_id WHERE p.usuario_id = ? ORDER BY p.id', [$usuarioId]) as $l) {
            $itens[] = ['objeto_id' => (int) $l['objeto_id'], 'site_id' => (int) $l['site_id'], 'modo' => (string) $l['modo'], 'versao' => (int) $l['versao']];
        }

        return $itens;
    }

    public function contar(int $usuarioId): int
    {
        return (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM pilha WHERE usuario_id = ?', [$usuarioId]);
    }

    /** O modo da pilha (`copiar`/`recortar`), ou null se está vazia. */
    public function modo(int $usuarioId): ?string
    {
        $m = $this->conexao->fetchOne('SELECT modo FROM pilha WHERE usuario_id = ? LIMIT 1', [$usuarioId]);

        return $m === false ? null : (string) $m;
    }

    public function remover(int $usuarioId, int $objetoId): void
    {
        $this->conexao->delete('pilha', ['usuario_id' => $usuarioId, 'objeto_id' => $objetoId]);
    }

    public function esvaziar(int $usuarioId): void
    {
        $this->conexao->delete('pilha', ['usuario_id' => $usuarioId]);
    }
}

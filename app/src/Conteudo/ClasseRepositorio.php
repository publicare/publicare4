<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

use Doctrine\DBAL\Connection;

final class ClasseRepositorio
{
    public const TIPOS = ['texto', 'numero', 'html'];

    public function __construct(private readonly Connection $conexao)
    {
    }

    /** @param list<array{nome: string, tipo: string, indexavel?: bool}> $propriedades */
    public function criar(string $nome, array $propriedades): Classe
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $nome) !== 1) {
            throw new \InvalidArgumentException("Nome de classe inválido: {$nome}");
        }
        $normalizadas = [];
        foreach ($propriedades as $p) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $p['nome']) !== 1 || in_array($p['nome'], ['titulo', 'url', 'filhos'], true)) {
                throw new \InvalidArgumentException("Nome de propriedade inválido: {$p['nome']}");
            }
            if (!in_array($p['tipo'], self::TIPOS, true)) {
                throw new \InvalidArgumentException("Tipo de propriedade inválido: {$p['tipo']}");
            }
            $normalizadas[] = ['nome' => $p['nome'], 'tipo' => $p['tipo'], 'indexavel' => $p['indexavel'] ?? false];
        }
        $this->conexao->insert('classe', ['nome' => $nome, 'propriedades' => json_encode($normalizadas, JSON_THROW_ON_ERROR)]);

        return new Classe((int) $this->conexao->lastInsertId(), $nome, $normalizadas);
    }

    public function porNome(string $nome): ?Classe
    {
        $linha = $this->conexao->fetchAssociative('SELECT * FROM classe WHERE nome = ?', [$nome]);

        return $linha === false ? null : $this->hidratar($linha);
    }

    public function porId(int $id): ?Classe
    {
        $linha = $this->conexao->fetchAssociative('SELECT * FROM classe WHERE id = ?', [$id]);

        return $linha === false ? null : $this->hidratar($linha);
    }

    /** @param array<string, mixed> $linha */
    private function hidratar(array $linha): Classe
    {
        /** @var list<array{nome: string, tipo: string, indexavel: bool}> $props */
        $props = json_decode((string) $linha['propriedades'], true, 512, JSON_THROW_ON_ERROR);

        return new Classe((int) $linha['id'], (string) $linha['nome'], $props);
    }
}

<?php

declare(strict_types=1);

namespace Pbl\Importacao;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** O mapa "id no PBL3 => id aqui" (tabela `importacao_pbl3`, migração 25) de uma `fonte`: é o que torna a importação retomável. */
final class MapaDeIds
{
    public const CLASSE = 'classe';
    public const USUARIO = 'usuario';
    public const OBJETO = 'objeto';

    public function __construct(private readonly Connection $conexao, private readonly string $fonte)
    {
    }

    public function obter(string $entidade, int $origem): ?int
    {
        $v = $this->conexao->fetchOne('SELECT id_destino FROM importacao_pbl3 WHERE fonte = ? AND entidade = ? AND id_origem = ?', [$this->fonte, $entidade, $origem]);

        return $v === false ? null : (int) $v;
    }

    /**
     * @param list<int> $origens
     * @return array<int, int> origem => destino (só os que já foram importados)
     */
    public function varios(string $entidade, array $origens): array
    {
        $saida = [];
        foreach (array_chunk(array_values(array_unique($origens)), 500) as $parte) {
            $linhas = $this->conexao->executeQuery(
                'SELECT id_origem, id_destino FROM importacao_pbl3 WHERE fonte = ? AND entidade = ? AND id_origem IN (?)',
                [$this->fonte, $entidade, $parte],
                [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, ArrayParameterType::INTEGER],
            )->fetchAllAssociative();
            foreach ($linhas as $l) {
                $saida[(int) $l['id_origem']] = (int) $l['id_destino'];
            }
        }

        return $saida;
    }

    public function gravar(string $entidade, int $origem, int $destino): void
    {
        $this->conexao->insert('importacao_pbl3', ['fonte' => $this->fonte, 'entidade' => $entidade, 'id_origem' => $origem, 'id_destino' => $destino, 'importado_em' => gmdate('c')]);
    }

    /**
     * Páginas do mapa de uma entidade (por faixa do id da própria tabela), para varrer tudo o que já foi importado.
     *
     * @return list<array{id: int, origem: int, destino: int}>
     */
    public function pagina(string $entidade, int $aposId, int $limite): array
    {
        $saida = [];
        foreach ($this->conexao->fetchAllAssociative('SELECT id, id_origem, id_destino FROM importacao_pbl3 WHERE fonte = ? AND entidade = ? AND id > ? ORDER BY id LIMIT ' . max(1, $limite), [$this->fonte, $entidade, $aposId]) as $l) {
            $saida[] = ['id' => (int) $l['id'], 'origem' => (int) $l['id_origem'], 'destino' => (int) $l['id_destino']];
        }

        return $saida;
    }
}

<?php

declare(strict_types=1);

namespace Pbl\Busca;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;

/**
 * A mesma condição de visibilidade pública (ADR 0010: "o índice guarda objetos em qualquer estado e a visibilidade é
 * aplicada na consulta") usada em toda consulta pública (fluxo 9, `MotorDeConsulta`) e, aqui, na sugestão de grafia
 * parecida (nível C): status `publicado` e dentro de `data_publicacao`/`data_validade`. Centralizada para que o
 * vocabulário de sugestão nunca inclua termo de conteúdo privado, não publicado ou na lixeira — o mesmo filtro, um só
 * lugar, nunca dois textos que podem desalinhar.
 */
final class Visibilidade
{
    /**
     * @param array<string, mixed> $valores preenchido com os parâmetros desta condição
     * @param array<string, ParameterType|ArrayParameterType> $tipos preenchido com os tipos dos mesmos parâmetros
     */
    public static function condicao(string $alias, array &$valores, array &$tipos): string
    {
        $valores['status'] = 'publicado';
        $valores['agora'] = gmdate('c');
        $tipos['status'] = ParameterType::STRING;
        $tipos['agora'] = ParameterType::STRING;

        return "({$alias}.status = :status AND ({$alias}.data_publicacao IS NULL OR {$alias}.data_publicacao <= :agora)"
            . " AND ({$alias}.data_validade IS NULL OR {$alias}.data_validade >= :agora))";
    }
}

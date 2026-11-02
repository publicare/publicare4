<?php

declare(strict_types=1);

namespace Pbl\Busca;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Driver padrão da busca (nível B): SQL puro sobre `objeto_termo (objeto_id, termo, pontos)`, igual em SQLite, MySQL e
 * PostgreSQL e sem extensão.
 *
 * - **Várias palavras**: todas precisam aparecer (E), em qualquer ordem e em qualquer campo.
 * - **Prefixo**: cada palavra da consulta casa com o começo do termo (`presid` acha `presidência`).
 * - **Plural**: gravação e consulta usam `Termos::singular` (`casas`→`casa`).
 * - **Relevância**: cada termo guarda os pontos do melhor campo onde aparece (título 300, descrição 200, texto 100) mais 30
 *   se é a primeira palavra do campo; na consulta soma-se 50 por palavra igual (não só prefixo), 200 se o título começa pela
 *   frase digitada e 400 se é igual a ela.
 *
 * Segurança: nenhum texto do usuário entra no SQL (parâmetros nomeados), `%`, `_` e `!` são escapados no `LIKE`, e o número
 * e o tamanho dos termos têm teto (`Termos`).
 */
final class BuscaSql implements BuscaInterface
{
    public const PONTOS_TITULO = 300;
    public const PONTOS_DESCRICAO = 200;
    public const PONTOS_TEXTO = 100;
    public const BONUS_INICIAL = 30;
    public const BONUS_PALAVRA_IGUAL = 50;
    public const BONUS_TITULO_COMECA = 200;
    public const BONUS_TITULO_IGUAL = 400;

    public function __construct(private readonly Connection $conexao)
    {
    }

    public function indexar(int $objetoId, array $campos): void
    {
        $limite = Termos::MAX_TEXTO_INDEXADO;
        $pontos = [];
        foreach ([
            [$campos['corpo'], self::PONTOS_TEXTO],
            [$campos['descricao'], self::PONTOS_DESCRICAO],
            [$campos['titulo'], self::PONTOS_TITULO],
        ] as [$texto, $base]) {
            foreach (Termos::de(mb_substr($texto, 0, $limite)) as $i => $termo) {
                $p = $base + ($i === 0 ? self::BONUS_INICIAL : 0);
                if (($pontos[$termo] ?? 0) < $p) {
                    $pontos[$termo] = $p;
                }
            }
        }
        // O teto vale depois de priorizar por pontos: título e descrição nunca são cortados por causa do corpo.
        arsort($pontos);
        $this->conexao->transactional(function () use ($objetoId, $pontos): void {
            $this->conexao->delete('objeto_termo', ['objeto_id' => $objetoId]);
            foreach (array_slice($pontos, 0, Termos::MAX_TERMOS_POR_OBJETO, true) as $termo => $p) {
                $this->conexao->insert('objeto_termo', ['objeto_id' => $objetoId, 'termo' => (string) $termo, 'pontos' => $p]);
            }
        });
    }

    public function remover(int $objetoId): void
    {
        $this->conexao->delete('objeto_termo', ['objeto_id' => $objetoId]);
    }

    public function fragmento(string $consulta, string $alias, string $prefixo): ?FragmentoDeBusca
    {
        if (trim($consulta) === '') {
            return null;
        }
        $termos = Termos::daConsulta($consulta);
        if ($termos === []) {
            return FragmentoDeBusca::nada();
        }
        $onde = [];
        $pontos = [];
        $v = [];
        $t = [];
        $vr = [];
        $tr = [];
        foreach ($termos as $i => $termo) {
            $like = $this->escaparLike($termo) . '%';
            $a = "{$prefixo}t{$i}";
            $onde[] = "EXISTS (SELECT 1 FROM objeto_termo {$prefixo}b{$i} WHERE {$prefixo}b{$i}.objeto_id = {$alias}.id AND {$prefixo}b{$i}.termo LIKE :{$a} ESCAPE '!')";
            $v[$a] = $like;
            $t[$a] = ParameterType::STRING;

            $ar = "{$prefixo}r{$i}";
            $ae = "{$prefixo}e{$i}";
            $pontos[] = "(SELECT COALESCE(MAX({$prefixo}p{$i}.pontos + CASE WHEN {$prefixo}p{$i}.termo = :{$ae} THEN " . self::BONUS_PALAVRA_IGUAL
                . " ELSE 0 END), 0) FROM objeto_termo {$prefixo}p{$i} WHERE {$prefixo}p{$i}.objeto_id = {$alias}.id AND {$prefixo}p{$i}.termo LIKE :{$ar} ESCAPE '!')";
            $vr[$ar] = $like;
            $vr[$ae] = $termo;
            $tr[$ar] = $tr[$ae] = ParameterType::STRING;
        }
        // Título começando pela frase (ou igual a ela): é o que a pessoa provavelmente queria.
        $frase = Texto::normalizar(mb_substr($consulta, 0, Termos::MAX_TAMANHO_CONSULTA));
        $pf = "{$prefixo}fl";
        $pe = "{$prefixo}fe";
        $pontos[] = "(CASE WHEN {$alias}.titulo_norm = :{$pe} THEN " . self::BONUS_TITULO_IGUAL
            . " WHEN {$alias}.titulo_norm LIKE :{$pf} ESCAPE '!' THEN " . self::BONUS_TITULO_COMECA . ' ELSE 0 END)';
        $vr[$pf] = $this->escaparLike($frase) . '%';
        $vr[$pe] = $frase;
        $tr[$pf] = $tr[$pe] = ParameterType::STRING;

        return new FragmentoDeBusca('(' . implode(' AND ', $onde) . ')', $v, $t, '(' . implode(' + ', $pontos) . ')', $vr, $tr);
    }

    private function escaparLike(string $texto): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $texto);
    }
}

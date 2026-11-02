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
 *
 * **Nível C** (`sugerir`): sugestão de grafia parecida, sem tabela nova — usa o mesmo `objeto_termo`, filtrado pela
 * mesma visibilidade pública (`Visibilidade`), com candidatos limitados por tamanho e distância de edição por
 * caractere (não por byte) em PHP.
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

    /** Nível C: abaixo deste tamanho não tenta corrigir (palavra curta demais, erro vira outra palavra válida fácil demais). */
    public const SUGESTAO_TAMANHO_MINIMO = 3;
    /** Nível C: teto de candidatos avaliados por palavra (custo do Levenshtein, calculado em PHP). */
    public const SUGESTAO_MAX_CANDIDATOS = 300;
    /** Nível C: só considera termos do vocabulário com até esta diferença de tamanho da palavra digitada. */
    public const SUGESTAO_JANELA_TAMANHO = 2;

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

    /**
     * Sugestão de grafia parecida (nível C): cada palavra da consulta que não é prefixo de nenhum termo do vocabulário
     * visível vira o candidato mais próximo (Levenshtein, limitado); palavras que já acham algo ficam como estão. Sem
     * nenhuma troca, ou com alguma palavra sem candidato confiável, devolve null (nenhuma sugestão é melhor que uma errada).
     */
    public function sugerir(int $siteId, string $consulta): ?string
    {
        $termos = Termos::daConsulta($consulta);
        if ($termos === []) {
            return null;
        }
        $corrigidos = [];
        $mudou = false;
        foreach ($termos as $termo) {
            if (mb_strlen($termo) < self::SUGESTAO_TAMANHO_MINIMO || $this->existeNoVocabulario($siteId, $termo)) {
                $corrigidos[] = $termo;
                continue;
            }
            $candidato = $this->candidatoMaisProximo($siteId, $termo);
            if ($candidato === null) {
                return null;
            }
            $corrigidos[] = $candidato;
            $mudou = true;
        }

        return $mudou ? implode(' ', $corrigidos) : null;
    }

    /** Já existe, no vocabulário visível ao público, algum termo que comece por `$termo` (igual à condição de `fragmento`). */
    private function existeNoVocabulario(int $siteId, string $termo): bool
    {
        $valores = ['site' => $siteId, 'termo' => $this->escaparLike($termo) . '%'];
        $tipos = ['site' => ParameterType::INTEGER, 'termo' => ParameterType::STRING];
        $visivel = Visibilidade::condicao('obj', $valores, $tipos);

        return $this->conexao->fetchOne(
            "SELECT 1 FROM objeto_termo bt JOIN objeto obj ON obj.id = bt.objeto_id WHERE obj.site_id = :site AND {$visivel} AND bt.termo LIKE :termo ESCAPE '!' LIMIT 1",
            $valores,
            $tipos,
        ) !== false;
    }

    /**
     * Entre os termos do vocabulário visível com tamanho parecido (teto de candidatos), o mais próximo de `$termo` por
     * distância de edição, dentro do limiar (1 letra para termos curtos, 2 para os longos); desempate pelos pontos
     * (mais frequente no site). Null se nenhum candidato está perto o bastante.
     *
     * `LENGTH()` conta **bytes** no MySQL e **caracteres** no SQLite e no PostgreSQL — depois de `Texto::normalizar`
     * os termos costumam ser ASCII (acentos latinos já viram letra base), mas uma letra que sobrevive multibyte (de
     * um alfabeto que o mapa de acentos não cobre) teria tamanho diferente a cada banco. Em vez de escolher a função
     * certa por plataforma, a janela em `LENGTH()` fica **larga o bastante para nunca excluir um candidato de verdade**
     * (até 4 bytes por caractere em UTF-8): é só um corte grosso para não escanear o vocabulário inteiro: a decisão
     * final de tamanho e de distância usa caracteres, em PHP (`self::distancia`), não bytes.
     */
    private function candidatoMaisProximo(int $siteId, string $termo): ?string
    {
        $tamanho = mb_strlen($termo);
        $valores = ['site' => $siteId, 'min' => max(1, $tamanho - self::SUGESTAO_JANELA_TAMANHO), 'max' => ($tamanho + self::SUGESTAO_JANELA_TAMANHO) * 4];
        $tipos = ['site' => ParameterType::INTEGER, 'min' => ParameterType::INTEGER, 'max' => ParameterType::INTEGER];
        $visivel = Visibilidade::condicao('obj', $valores, $tipos);
        $sql = 'SELECT ct.termo AS termo, SUM(ct.pontos) AS freq FROM objeto_termo ct JOIN objeto obj ON obj.id = ct.objeto_id'
            . " WHERE obj.site_id = :site AND {$visivel} AND LENGTH(ct.termo) BETWEEN :min AND :max"
            . ' GROUP BY ct.termo ORDER BY freq DESC LIMIT ' . self::SUGESTAO_MAX_CANDIDATOS;
        $limiar = $tamanho <= 5 ? 1 : 2;
        $melhorTermo = null;
        $melhorDistancia = $limiar + 1;
        $melhorFrequencia = -1;
        foreach ($this->conexao->fetchAllAssociative($sql, $valores, $tipos) as $linha) {
            $candidato = (string) $linha['termo'];
            $distancia = self::distancia($termo, $candidato, $limiar);
            if ($distancia === 0 || $distancia > $limiar) {
                continue;
            }
            $frequencia = (int) $linha['freq'];
            if ($distancia < $melhorDistancia || ($distancia === $melhorDistancia && $frequencia > $melhorFrequencia)) {
                $melhorTermo = $candidato;
                $melhorDistancia = $distancia;
                $melhorFrequencia = $frequencia;
            }
        }

        return $melhorTermo;
    }

    /**
     * Distância de edição (Levenshtein) por **caractere**, não por byte: `levenshtein()` do PHP é por byte e erraria em
     * qualquer termo com letra multibyte que sobreviva a `Texto::normalizar` (acentos latinos já viram ASCII; outros
     * alfabetos, não). `$limiar` poda a conta: uma diferença de tamanho maior que ele já descarta sem montar a matriz
     * toda, e cada linha para assim que o menor valor já passou do limiar (não dá para melhorar depois disso).
     */
    private static function distancia(string $a, string $b, int $limiar): int
    {
        $ca = mb_str_split($a);
        $cb = mb_str_split($b);
        $m = count($ca);
        $n = count($cb);
        if (abs($m - $n) > $limiar) {
            return $limiar + 1;
        }
        $linha = range(0, $n);
        for ($i = 1; $i <= $m; $i++) {
            $nova = [$i];
            $minimoDaLinha = $i;
            for ($j = 1; $j <= $n; $j++) {
                $custo = $ca[$i - 1] === $cb[$j - 1] ? 0 : 1;
                $valor = min($linha[$j] + 1, $nova[$j - 1] + 1, $linha[$j - 1] + $custo);
                $nova[] = $valor;
                $minimoDaLinha = min($minimoDaLinha, $valor);
            }
            $linha = $nova;
            if ($minimoDaLinha > $limiar) {
                return $limiar + 1;
            }
        }

        return $linha[$n];
    }
}

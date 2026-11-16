<?php

declare(strict_types=1);

namespace Pbl\Testes\Arquitetura;

use PHPUnit\Framework\TestCase;

/**
 * ADR 0028 §1: SQL específico de banco só existe em `Banco/Dialeto` e `Banco/Copia`. O resto do código fica no subconjunto
 * comum. A regra distingue caixa: `AUTOINCREMENT` em maiúsculas é SQL cru; `'autoincrement' => true` é a API do DBAL (portável).
 */
final class SqlPortavelTest extends TestCase
{
    private const PERMITIDOS = ['Banco/Dialeto/', 'Banco/Copia/'];

    /** Padrão => por que é proibido. */
    private const PROIBIDOS = [
        '/\bPRAGMA\b/' => 'PRAGMA é do SQLite',
        '/\bINSERT\s+OR\s+(?:REPLACE|IGNORE|ROLLBACK|ABORT|FAIL)\b/i' => 'INSERT OR … é do SQLite',
        '/\bON\s+CONFLICT\b/i' => 'ON CONFLICT não existe no MySQL',
        '/\bjson_extract\s*\(/i' => 'json_extract diverge entre bancos (JSON é tratado em PHP)',
        '/\bAUTOINCREMENT\b/' => 'AUTOINCREMENT é do SQLite',
        '/\bRETURNING\b/' => 'RETURNING não existe no MySQL',
        '/\bsqlite_[a-z]/i' => 'tabelas e funções sqlite_* são do SQLite',
        '/\bVACUUM\b/i' => 'VACUUM é do SQLite/PostgreSQL',
    ];

    /** @return list<string> ocorrências "arquivo:linha: motivo" */
    public static function varrer(string $texto, string $nome): array
    {
        $achados = [];
        foreach (explode("\n", $texto) as $i => $linha) {
            foreach (self::PROIBIDOS as $padrao => $motivo) {
                if (preg_match($padrao, $linha) === 1) {
                    $achados[] = sprintf('%s:%d: %s', $nome, $i + 1, $motivo);
                }
            }
        }

        return $achados;
    }

    public function testVarreduraReconheceOQueProibe(): void
    {
        $ruins = "PRAGMA x;\nINSERT OR REPLACE INTO t;\nON CONFLICT (a)\nSELECT json_extract(a,'\$.b')\nid INTEGER PRIMARY KEY AUTOINCREMENT\nINSERT ... RETURNING id\nFROM sqlite_master\nVACUUM INTO 'x'";
        self::assertCount(8, self::varrer($ruins, 'x.php'));
        self::assertSame([], self::varrer("['autoincrement' => true]\n// pragmático, returning o valor", 'x.php'), 'API do DBAL e prosa não contam');
    }

    public function testSqlEspecificoDeBancoSoExisteNosDialetosECopias(): void
    {
        $base = dirname(__DIR__, 2) . '/app/src/';
        $achados = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $arquivo) {
            if (!$arquivo instanceof \SplFileInfo || $arquivo->getExtension() !== 'php') {
                continue;
            }
            $relativo = substr($arquivo->getPathname(), strlen($base));
            foreach (self::PERMITIDOS as $permitido) {
                if (str_starts_with($relativo, $permitido)) {
                    continue 2;
                }
            }
            $achados = [...$achados, ...self::varrer((string) file_get_contents($arquivo->getPathname()), $relativo)];
        }

        self::assertSame([], $achados, "SQL específico de banco fora de Banco/Dialeto e Banco/Copia:\n" . implode("\n", $achados));
    }
}

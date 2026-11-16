<?php

declare(strict_types=1);

namespace Pbl\Testes\Banco;

use Pbl\Banco\Conexao;
use Pbl\Banco\Copia\CopiaSqlite;
use Pbl\Testes\Ajuda;
use PHPUnit\Framework\TestCase;

final class CopiaSqliteTest extends TestCase
{
    public function testCopiaConsistenteEIntegridadeEFechamentoDoWal(): void
    {
        $pasta = Ajuda::pastaTemporaria();
        try {
            $origem = $pasta . '/o.sqlite';
            $c = Conexao::sqlite($origem);
            $c->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
            $c->executeStatement("INSERT INTO t (v) VALUES ('a')");
            CopiaSqlite::fecharWal($c);

            CopiaSqlite::copiarConsistente($origem, $pasta . '/d.sqlite');

            self::assertTrue(CopiaSqlite::integro($pasta . '/d.sqlite'));
            self::assertSame('a', Conexao::sqlite($pasta . '/d.sqlite')->fetchOne('SELECT v FROM t'));
            file_put_contents($pasta . '/ruim.sqlite', 'isto não é um banco');
            self::assertFalse(CopiaSqlite::integro($pasta . '/ruim.sqlite'));
        } finally {
            Ajuda::apagar($pasta);
        }
    }
}

<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Banco\Migrador;
use Pbl\Conteudo\ClassesPadrao;
use PHPUnit\Framework\TestCase;

/** M021: completa campos novos das classes fixas em instalações antigas (só aditiva); `garantirFixas` recria `formulario`. */
final class M021Test extends TestCase
{
    use AdminAuxiliar;

    public function testCompletaCamposFaltantesSemDuplicarNemCriarClasse(): void
    {
        $c = $this->s->conexao();
        $linha = $c->fetchAssociative("SELECT id, propriedades FROM classe WHERE nome = 'formulario'");
        self::assertIsArray($linha);
        $props = json_decode((string) $linha['propriedades'], true);
        self::assertIsArray($props);
        $removida = array_pop($props);
        $c->executeStatement('UPDATE classe SET propriedades = ? WHERE id = ?', [json_encode($props), $linha['id']]);
        $c->executeStatement('DELETE FROM schema_versao WHERE versao >= 21');

        $n = (int) $c->fetchOne('SELECT COUNT(*) FROM classe');
        self::assertSame([21, 22, 23, 24], (new Migrador($c))->migrar());
        self::assertSame($n, (int) $c->fetchOne('SELECT COUNT(*) FROM classe'), 'não cria classe');
        $depois = json_decode((string) $c->fetchOne("SELECT propriedades FROM classe WHERE nome = 'formulario'"), true);
        $nomes = array_column($depois, 'nome');
        self::assertContains($removida['nome'], $nomes);
        self::assertSame(count($nomes), count(array_unique($nomes)));

        $c->executeStatement('DELETE FROM schema_versao WHERE versao >= 21');
        self::assertSame([21, 22, 23, 24], (new Migrador($c))->migrar());
        self::assertSame($depois, json_decode((string) $c->fetchOne("SELECT propriedades FROM classe WHERE nome = 'formulario'"), true), 'idempotente');
    }

    public function testGarantirFixasRecriaFormulario(): void
    {
        $c = $this->s->conexao();
        $c->executeStatement("DELETE FROM classe WHERE nome = 'formulario'");
        self::assertContains('formulario', ClassesPadrao::garantirFixas($this->s->classes));
        self::assertSame(1, (int) $c->fetchOne("SELECT COUNT(*) FROM classe WHERE nome = 'formulario'"));
    }
}

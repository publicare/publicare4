<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Admin\IconesDeClasse;
use PHPUnit\Framework\TestCase;

/** Ícone por tipo de conteúdo (ADR 0026): campo da classe, só admin altera, aparece na listagem. */
final class AdminIconeDeClasseTest extends TestCase
{
    use AdminAuxiliar;

    public function testRepositorioGuardaEValidaOIcone(): void
    {
        $classe = $this->s->classes->porNome('pagina') ?? self::fail();
        self::assertSame('', $classe->icone);

        $nova = $this->s->classes->definirIcone($classe->id, 'documento');
        self::assertSame('documento', $nova->icone);
        self::assertSame($classe->versao, $nova->versao, 'só apresentação: não mexe na versão');
        self::assertSame('', $this->s->classes->definirIcone($classe->id, '')->icone);
        $this->expectException(\InvalidArgumentException::class);
        $this->s->classes->definirIcone($classe->id, '<script>');
    }

    public function testAdminDefineIconeEAparecemNaListaEAuditoria(): void
    {
        $classe = $this->s->classes->porNome('pagina') ?? self::fail();
        $resposta = $this->post($this->token('admin'), '/_pbl/classe/' . $classe->id . '/icone', ['icone' => 'calendario']);

        self::assertSame(303, $resposta->getStatusCode());
        self::assertSame('calendario', $this->s->classes->porId($classe->id)?->icone);
        self::assertNotEmpty($this->auditoria('classe.icone'));
        self::assertStringContainsString('pbl-icone-classe', $this->html($this->req('GET', '/_pbl/classes', $this->token('admin'))));
        self::assertStringContainsString('Ícone atualizado', $this->html($this->req('GET', '/_pbl/classe/' . $classe->id . '/editar?ok=icone', $this->token('admin'))));
    }

    public function testIconeDesconhecidoEhRecusadoEEditorNaoAltera(): void
    {
        $classe = $this->s->classes->porNome('pagina') ?? self::fail();

        self::assertSame(422, $this->post($this->token('admin'), '/_pbl/classe/' . $classe->id . '/icone', ['icone' => 'nao-existe'])->getStatusCode());
        self::assertSame(403, $this->post($this->token('editor'), '/_pbl/classe/' . $classe->id . '/icone', ['icone' => 'pasta'])->getStatusCode());
        self::assertSame(403, $this->post($this->token('admin'), '/_pbl/classe/' . $classe->id . '/icone', ['icone' => 'pasta'], false)->getStatusCode(), 'sem CSRF');
        self::assertSame('', $this->s->classes->porId($classe->id)?->icone);
    }

    public function testConjuntoFechadoDeSvgs(): void
    {
        foreach (array_keys(IconesDeClasse::opcoes()) as $chave) {
            $svg = IconesDeClasse::svg($chave);
            self::assertStringStartsWith('<svg', $svg);
            self::assertStringNotContainsString('<script', $svg);
        }
        self::assertSame('', IconesDeClasse::svg('xyz'));
    }
}

<?php

declare(strict_types=1);

namespace Pbl\Testes;

use PHPUnit\Framework\TestCase;

/** QR code do endereço público do conteúdo (ADR 0026): gerado no servidor, só para quem vê e só se há endereço público. */
final class AdminQrDoObjetoTest extends TestCase
{
    use AdminAuxiliar;

    public function testMostraOQrDeConteudoPublicadoEBaixaOSvg(): void
    {
        $classe = $this->s->classes->porNome('pagina') ?? self::fail();
        $o = $this->s->objetos->criar($this->siteId, $classe, $this->inicioId, 'Para imprimir', 'para-imprimir', ['corpo' => 'x'], 'publicado');

        $html = $this->html($this->req('GET', '/_pbl/objeto/' . $o->id . '/qr', $this->token('leitor')));
        self::assertStringContainsString('<svg', $html);
        self::assertStringContainsString('https://www.exemplo.org/para-imprimir', $html);
        self::assertStringContainsString('/_pbl/objeto/' . $o->id . '/qr?formato=svg', $html);

        $baixa = $this->req('GET', '/_pbl/objeto/' . $o->id . '/qr?formato=svg', $this->token('leitor'));
        self::assertSame(200, $baixa->getStatusCode());
        self::assertStringStartsWith('image/svg+xml', (string) $baixa->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $baixa->headers->get('Content-Disposition'));
        self::assertStringStartsWith('<svg ', (string) $baixa->getContent());
        self::assertStringContainsString('/qr', $this->html($this->req('GET', '/_pbl/objeto/' . $o->id, $this->token('admin'))), 'link na tela do objeto');
    }

    public function testSemEnderecoPublicoAvisaEPermissaoEhExigida(): void
    {
        $classe = $this->s->classes->porNome('pagina') ?? self::fail();
        $o = $this->s->objetos->criar($this->siteId, $classe, $this->inicioId, 'Rascunho', 'rascunho-qr', ['corpo' => 'x'], 'privado');

        $html = $this->html($this->req('GET', '/_pbl/objeto/' . $o->id . '/qr', $this->token('admin')));
        self::assertStringContainsString('ainda não tem endereço público', $html);
        self::assertStringNotContainsString('<svg class="', $html);

        $this->ids['estranho'] = $this->s->usuarios->criar('estranho', 'estranho@exemplo.org', 'Estranho', self::SENHA)->id;
        self::assertSame(403, $this->req('GET', '/_pbl/objeto/' . $o->id . '/qr', $this->token('estranho'))->getStatusCode());
        self::assertSame(303, $this->req('GET', '/_pbl/objeto/' . $o->id . '/qr')->getStatusCode(), 'sem sessão');
        self::assertSame(404, $this->post($this->token('admin'), '/_pbl/objeto/' . $o->id . '/qr', [])->getStatusCode());
    }
}

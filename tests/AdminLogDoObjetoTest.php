<?php

declare(strict_types=1);

namespace Pbl\Testes;

use PHPUnit\Framework\TestCase;

/** Registro de atividade por objeto (ADR 0026): auditoria filtrada pelo objeto mais o fluxo de revisão, só leitura. */
final class AdminLogDoObjetoTest extends TestCase
{
    use AdminAuxiliar;

    public function testMostraAuditoriaDoObjetoComNomeDeQuemFezEFluxoDeRevisao(): void
    {
        $classe = $this->s->classes->porNome('pagina') ?? self::fail();
        $o = $this->s->objetos->criar($this->siteId, $classe, $this->inicioId, 'Com histórico', 'com-historico', ['corpo' => 'x'], 'privado');
        $outro = $this->s->objetos->criar($this->siteId, $classe, $this->inicioId, 'Outro objeto', 'outro-obj', ['corpo' => 'x'], 'privado');
        $o = $this->s->objetos->atualizar($o->id, ['titulo' => 'Com histórico 2'], 'ajuste', null, false, $this->ids['editor']);
        $this->s->objetos->atualizar($outro->id, ['titulo' => 'Mudou o outro'], 'ajuste', null, false, $this->ids['editor']);
        $editor = $this->s->usuarios->porId($this->ids['editor']) ?? self::fail();
        $admin = $this->s->usuarios->porId($this->ids['admin']) ?? self::fail();
        $this->s->revisao->enviar($editor, $o->id);
        $this->s->revisao->devolver($admin, $o->id, 'Falta a foto de capa');

        $html = $this->html($this->req('GET', '/_pbl/objeto/' . $o->id . '/log', $this->token('admin')));

        self::assertStringContainsString('Registro de atividade', $html);
        self::assertStringContainsString('<code>objeto.', $html);
        self::assertStringContainsString('Editor', $html, 'nome, não usuario:N');
        self::assertStringNotContainsString('usuario:' . $this->ids['editor'], $html);
        self::assertStringContainsString('Falta a foto de capa', $html, 'fluxo de revisão');
        self::assertStringNotContainsString('Mudou o outro', $html, 'só deste objeto');
        self::assertStringContainsString('/_pbl/objeto/' . $o->id . '/log', $this->html($this->req('GET', '/_pbl/objeto/' . $o->id, $this->token('admin'))), 'link na tela do objeto');
    }

    public function testExigeVerEEhSomenteLeitura(): void
    {
        $classe = $this->s->classes->porNome('pagina') ?? self::fail();
        $o = $this->s->objetos->criar($this->siteId, $classe, $this->inicioId, 'Sem fluxo', 'sem-fluxo', ['corpo' => 'x'], 'privado');

        self::assertStringContainsString('nunca passou pela revisão', $this->html($this->req('GET', '/_pbl/objeto/' . $o->id . '/log', $this->token('leitor'))));
        $this->ids['estranho'] = $this->s->usuarios->criar('estranho', 'estranho@exemplo.org', 'Estranho', self::SENHA)->id;
        self::assertSame(403, $this->req('GET', '/_pbl/objeto/' . $o->id . '/log', $this->token('estranho'))->getStatusCode());
        self::assertSame(404, $this->post($this->token('admin'), '/_pbl/objeto/' . $o->id . '/log', [])->getStatusCode());
        self::assertSame(303, $this->req('GET', '/_pbl/objeto/' . $o->id . '/log')->getStatusCode(), 'sem sessão');
    }
}

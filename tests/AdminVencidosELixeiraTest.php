<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\Perfil;
use Pbl\Conteudo\ConflitoDeArvore;
use Pbl\Conteudo\ConflitoDeVersao;
use Pbl\Conteudo\Objeto;
use PHPUnit\Framework\TestCase;

/** Telas "Objetos vencidos" e "Lixeira" (ADR 0026): recorte por seção, permissões, apagar de vez com senha e auditoria. */
final class AdminVencidosELixeiraTest extends TestCase
{
    use AdminAuxiliar;

    /** @param array<string, mixed> $meta */
    private function pagina(string $titulo, ?int $paiId = null, string $status = 'publicado', array $meta = []): Objeto
    {
        static $n = 0;
        ++$n;
        $classe = $this->s->classes->porNome('pagina') ?? self::fail();

        return $this->s->objetos->criar($this->siteId, $classe, $paiId ?? $this->inicioId, $titulo, 'pg-' . $n . '-' . bin2hex(random_bytes(2)), ['corpo' => 'x'], $status, $meta);
    }

    private function lixo(Objeto $o): Objeto
    {
        return $this->s->objetos->mandarParaLixeira($o->id, $o->versao, 'teste', $this->ids['admin']);
    }

    // ---------------------------------------------------------------- vencidos

    public function testVencidosListaSoPublicadoComValidadePassada(): void
    {
        $vencido = $this->pagina('Promoção velha', null, 'publicado', ['data_validade' => '2020-01-01T00:00:00+00:00']);
        $this->pagina('Ainda vale', null, 'publicado', ['data_validade' => '2999-01-01T00:00:00+00:00']);
        $this->pagina('Sem validade');
        $this->pagina('Privado vencido', null, 'privado', ['data_validade' => '2020-01-01T00:00:00+00:00']);

        $html = $this->html($this->req('GET', '/_pbl/vencidos', $this->token('admin')));

        self::assertStringContainsString('Promoção velha', $html);
        self::assertStringContainsString('/_pbl/objeto/' . $vencido->id . '/estado', $html, 'prorrogar ou despublicar');
        self::assertStringNotContainsString('Ainda vale', $html);
        self::assertStringNotContainsString('Sem validade', $html);
        self::assertStringNotContainsString('Privado vencido', $html);
        self::assertStringContainsString('31/12/2019', $html);
    }

    public function testVencidosFiltraPorSecaoERespeitaPermissao(): void
    {
        $outra = $this->pagina('Outra seção');
        $dentro = $this->pagina('Vencido dentro', $outra->id, 'publicado', ['data_validade' => '2020-01-01T00:00:00+00:00']);
        $fora = $this->pagina('Vencido fora', null, 'publicado', ['data_validade' => '2020-01-01T00:00:00+00:00']);
        $this->s->usuarios->conceder($this->ids['leitor'], $outra->id, Perfil::Leitor);
        $admin = $this->token('admin');

        $filtrado = $this->html($this->req('GET', '/_pbl/vencidos?secao=' . $outra->id, $admin));
        self::assertStringContainsString('Vencido dentro', $filtrado);
        self::assertStringNotContainsString('Vencido fora', $filtrado);

        $leitor = $this->html($this->req('GET', '/_pbl/vencidos', $this->token('leitor')));
        self::assertStringContainsString('Vencido dentro', $leitor);
        self::assertStringNotContainsString('/_pbl/objeto/' . $dentro->id . '/estado', $leitor, 'leitor não prorroga');
        self::assertStringNotContainsString('/_pbl/objeto/' . $dentro->id . '/editar', $leitor);

        $editor = $this->html($this->req('GET', '/_pbl/vencidos', $this->token('editor')));
        self::assertStringContainsString('/_pbl/objeto/' . $fora->id . '/estado', $editor);

        $semAcesso = $this->s->usuarios->criar('semacesso', 'sem@exemplo.org', 'Sem Acesso', self::SENHA);
        $this->ids['semacesso'] = $semAcesso->id;
        $nada = $this->html($this->req('GET', '/_pbl/vencidos?secao=' . $outra->id, $this->token('semacesso')));
        self::assertStringNotContainsString('Vencido dentro', $nada);
        self::assertStringContainsString('Nenhum conteúdo publicado', $nada);
    }

    public function testVencidosExigeSessao(): void
    {
        self::assertSame(303, $this->req('GET', '/_pbl/vencidos')->getStatusCode());
        self::assertSame(404, $this->req('POST', '/_pbl/vencidos', $this->token('admin'))->getStatusCode());
    }

    // ---------------------------------------------------------------- lixeira (repositório)

    public function testApagarDefinitivamenteLimpaTudoDoObjetoEDosDeDentro(): void
    {
        $pai = $this->pagina('Pai');
        $filho = $this->pagina('Filho', $pai->id);
        $this->s->tags->definir($this->siteId, $filho->id, ['pauta']);
        $filho = $this->lixo($filho);
        $pai = $this->lixo($this->s->objetos->porId($pai->id) ?? self::fail());

        $n = $this->s->objetos->apagarDefinitivamente($pai->id, $pai->versao, $this->ids['admin']);

        self::assertSame(2, $n);
        self::assertNull($this->s->objetos->porId($pai->id));
        self::assertNull($this->s->objetos->porId($filho->id));
        $c = $this->s->conexao();
        foreach (['objeto_versao', 'objeto_indice', 'objeto_termo', 'url_historico', 'acesso', 'revisao', 'pilha', 'objeto_tag'] as $tabela) {
            self::assertSame(0, (int) $c->fetchOne("SELECT COUNT(*) FROM {$tabela} WHERE objeto_id IN (?, ?)", [$pai->id, $filho->id]), $tabela);
        }
        $log = array_filter($this->auditoria('objeto.apagar_definitivo'), static fn (array $l): bool => in_array($l['objeto_id'], [$pai->id, $filho->id], true));
        self::assertCount(2, $log);
        self::assertGreaterThan(0, (int) $c->fetchOne('SELECT COUNT(*) FROM auditoria WHERE objeto_id = ?', [$pai->id]), 'a auditoria fica');
    }

    public function testApagarDefinitivamenteRecusaForaDaLixeiraRaizEVersaoVelha(): void
    {
        $vivo = $this->pagina('Vivo');
        try {
            $this->s->objetos->apagarDefinitivamente($vivo->id, $vivo->versao);
            self::fail('apagou o que não está na lixeira');
        } catch (ConflitoDeArvore) {
            self::assertNotNull($this->s->objetos->porId($vivo->id));
        }
        try {
            $this->s->objetos->apagarDefinitivamente($this->inicioId, 1);
            self::fail('apagou a raiz');
        } catch (ConflitoDeArvore) {
            self::assertNotNull($this->s->objetos->porId($this->inicioId));
        }
        $noLixo = $this->lixo($vivo);
        $this->expectException(ConflitoDeVersao::class);
        $this->s->objetos->apagarDefinitivamente($noLixo->id, $noLixo->versao - 1);
    }

    // ---------------------------------------------------------------- lixeira (telas)

    public function testListaDaLixeiraFiltraEMostraAcoesPorPermissao(): void
    {
        $outra = $this->pagina('Seção B');
        $a = $this->lixo($this->pagina('Lixo da raiz'));
        $b = $this->lixo($this->pagina('Lixo da B', $outra->id));
        $this->pagina('Vivo');

        $todos = $this->html($this->req('GET', '/_pbl/lixeira', $this->token('admin')));
        self::assertStringContainsString('Lixo da raiz', $todos);
        self::assertStringContainsString('Lixo da B', $todos);
        self::assertStringNotContainsString('>Vivo<', $todos);
        self::assertStringContainsString('/_pbl/objeto/' . $a->id . '/recuperar', $todos);
        self::assertStringContainsString('/_pbl/lixeira/apagar-de-vez/' . $a->id, $todos);

        $filtrado = $this->html($this->req('GET', '/_pbl/lixeira?secao=' . $outra->id, $this->token('admin')));
        self::assertStringContainsString('Lixo da B', $filtrado);
        self::assertStringNotContainsString('Lixo da raiz', $filtrado);

        $leitor = $this->html($this->req('GET', '/_pbl/lixeira', $this->token('leitor')));
        self::assertStringContainsString('Lixo da raiz', $leitor);
        self::assertStringNotContainsString('apagar-de-vez/' . $b->id, $leitor, 'leitor só olha');
        self::assertStringNotContainsString('Esvaziar', $leitor);
    }

    public function testApagarDeVezPedeSenhaCsrfEAudita(): void
    {
        $item = $this->lixo($this->pagina('Para sumir'));
        $caminho = '/_pbl/lixeira/apagar-de-vez/' . $item->id;
        $token = $this->tokenSemElevar('admin');

        $tela = $this->html($this->req('GET', $caminho, $token));
        self::assertStringContainsString('não há como desfazer', $tela);
        self::assertStringContainsString('name="senha"', $tela);

        self::assertSame(403, $this->post($token, $caminho, ['versao' => (string) $item->versao, 'senha' => self::SENHA], false)->getStatusCode(), 'sem CSRF');
        $semSenha = $this->post($token, $caminho, ['versao' => (string) $item->versao]);
        self::assertSame(422, $semSenha->getStatusCode());
        $errada = $this->post($token, $caminho, ['versao' => (string) $item->versao, 'senha' => 'senha errada demais 99']);
        self::assertGreaterThanOrEqual(400, $errada->getStatusCode());
        self::assertNotNull($this->s->objetos->porId($item->id), 'nada foi apagado');

        $ok = $this->post($token, $caminho, ['versao' => (string) $item->versao, 'senha' => self::SENHA]);
        self::assertSame(303, $ok->getStatusCode());
        self::assertStringContainsString('/_pbl/lixeira', (string) $ok->headers->get('Location'));
        self::assertNull($this->s->objetos->porId($item->id));
        self::assertCount(1, $this->auditoria('objeto.apagar_definitivo'));
    }

    public function testApagarDeVezNegaQuemNaoPodeEQuemNaoEstaNaLixeira(): void
    {
        $item = $this->lixo($this->pagina('Do leitor não'));
        $vivo = $this->pagina('Vivo');

        $leitor = $this->token('leitor');
        self::assertSame(403, $this->req('GET', '/_pbl/lixeira/apagar-de-vez/' . $item->id, $leitor)->getStatusCode());
        self::assertSame(403, $this->post($leitor, '/_pbl/lixeira/apagar-de-vez/' . $item->id, ['versao' => (string) $item->versao])->getStatusCode());
        self::assertNotNull($this->s->objetos->porId($item->id));

        $admin = $this->token('admin');
        self::assertSame(403, $this->post($admin, '/_pbl/lixeira/apagar-de-vez/' . $vivo->id, ['versao' => (string) $vivo->versao])->getStatusCode());
        self::assertSame(403, $this->req('GET', '/_pbl/lixeira/apagar-de-vez/' . $this->inicioId, $admin)->getStatusCode(), 'raiz do site');
        self::assertNotNull($this->s->objetos->porId($vivo->id));
    }

    public function testApagarDeVezComVersaoVelhaDa409(): void
    {
        $item = $this->lixo($this->pagina('Mudou'));
        $r = $this->post($this->token('admin'), '/_pbl/lixeira/apagar-de-vez/' . $item->id, ['versao' => (string) ($item->versao + 5), 'senha' => self::SENHA]);
        self::assertSame(409, $r->getStatusCode());
        self::assertNotNull($this->s->objetos->porId($item->id));
    }

    public function testEsvaziarApagaSoOQueAPessoaPodeEDentroDoRecorte(): void
    {
        $outra = $this->pagina('Seção B');
        $a = $this->lixo($this->pagina('Lixo A'));
        $b = $this->lixo($this->pagina('Lixo B', $outra->id));
        $admin = $this->token('admin');

        $confirma = $this->html($this->req('GET', '/_pbl/lixeira/esvaziar?secao=' . $outra->id, $admin));
        self::assertStringContainsString('Lixo B', $confirma);
        self::assertStringNotContainsString('Lixo A', $confirma);

        self::assertSame(403, $this->post($admin, '/_pbl/lixeira/esvaziar', ['secao' => (string) $outra->id], false)->getStatusCode(), 'sem CSRF');
        $r = $this->post($admin, '/_pbl/lixeira/esvaziar', ['secao' => (string) $outra->id]);
        self::assertSame(303, $r->getStatusCode());
        self::assertStringContainsString('ok=esvaziada', (string) $r->headers->get('Location'));
        self::assertNull($this->s->objetos->porId($b->id));
        self::assertNotNull($this->s->objetos->porId($a->id), 'fora do recorte fica');

        $this->post($admin, '/_pbl/lixeira/esvaziar', []);
        self::assertNull($this->s->objetos->porId($a->id));
        self::assertCount(2, $this->auditoria('lixeira.esvaziar'));
    }

    public function testEsvaziarSemSenhaNaoApaga(): void
    {
        $a = $this->lixo($this->pagina('Lixo A'));
        $r = $this->post($this->tokenSemElevar('admin'), '/_pbl/lixeira/esvaziar', []);
        self::assertSame(422, $r->getStatusCode());
        self::assertNotNull($this->s->objetos->porId($a->id));
    }
}

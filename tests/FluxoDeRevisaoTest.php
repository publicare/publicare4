<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\Perfil;
use Pbl\Banco\Migracoes\M019FluxoDeRevisao;
use Pbl\Conteudo\ErroDeRevisao;
use Pbl\Conteudo\FluxoDeRevisao;
use Pbl\Conteudo\Objeto;
use Pbl\Console\DemoPopularComando;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Fluxo de revisão (fase 6): enviar, retirar, aprovar, devolver, reenviar; permissões por papel e por nível; telas Pendências e Meus envios. */
final class FluxoDeRevisaoTest extends TestCase
{
    use AdminAuxiliar {
        setUp as private setUpBase;
    }

    private int $saude;
    private int $educacao;
    private int $vigilancia;
    private int $zoonoses;

    protected function setUp(): void
    {
        $this->setUpBase();
        $this->saude = $this->secao('Saúde', 'saude', $this->inicioId);
        $this->educacao = $this->secao('Educação', 'educacao', $this->inicioId);
        $this->vigilancia = $this->secao('Vigilância', 'vigilancia', $this->saude);
        $this->zoonoses = $this->secao('Zoonoses', 'zoonoses', $this->saude);
        $u = $this->s->usuarios;
        foreach ([
            'autor_saude' => [$this->saude, Perfil::Autor],
            'autor_saude2' => [$this->saude, Perfil::Autor],
            'editor_saude' => [$this->saude, Perfil::Editor],
            'editor_edu' => [$this->educacao, Perfil::Editor],
            'editor_vigilancia' => [$this->vigilancia, Perfil::Editor],
            'autor_edu' => [$this->educacao, Perfil::Autor],
        ] as $login => [$secao, $perfil]) {
            $this->ids[$login] = $u->criar($login, $login . '@exemplo.org', 'Conta ' . $login, self::SENHA)->id;
            $u->conceder($this->ids[$login], $secao, $perfil);
        }
    }

    private function secao(string $titulo, string $url, int $pai): int
    {
        $classe = $this->s->classes->porNome('pagina') ?? self::fail();

        return $this->s->objetos->criar($this->siteId, $classe, $pai, $titulo, $url, [], 'publicado')->id;
    }

    private function conteudo(string $titulo, int $pai, string $autor = 'autor_saude', string $status = 'privado'): Objeto
    {
        $classe = $this->s->classes->porNome('pagina') ?? self::fail();

        return $this->s->objetos->criar($this->siteId, $classe, $pai, $titulo, 'c-' . bin2hex(random_bytes(3)), [], $status, ['criado_por' => $this->ids[$autor]]);
    }

    private function estado(Objeto $o): string
    {
        return ($this->s->objetos->porId($o->id) ?? self::fail())->status;
    }

    /** @param array<string, mixed> $extra */
    private function passo(string $login, Objeto $o, string $passo, array $extra = [], string $voltar = 'objeto'): int
    {
        $atual = $this->s->objetos->porId($o->id) ?? self::fail();

        return $this->post($this->token($login), '/_pbl/revisao/' . $o->id . '/' . $passo, ['versao' => (string) $atual->versao, 'voltar' => $voltar] + $extra)->getStatusCode();
    }

    private function submetido(string $titulo, int $pai, string $autor = 'autor_saude'): Objeto
    {
        $o = $this->conteudo($titulo, $pai, $autor);
        self::assertSame(303, $this->passo($autor, $o, 'enviar'));

        return $this->s->objetos->porId($o->id) ?? self::fail();
    }

    public function testAutorEnviaERevisorDaAreaAprova(): void
    {
        $o = $this->conteudo('Texto da Saúde', $this->saude);
        self::assertSame(303, $this->passo('autor_saude', $o, 'enviar'));
        self::assertSame('submetido', $this->estado($o));
        $fila = $this->html($this->req('GET', '/_pbl/pendencias', $this->token('editor_saude')));
        self::assertStringContainsString('Texto da Saúde', $fila);
        self::assertSame(303, $this->passo('editor_saude', $o, 'aprovar', [], 'pendencias'));
        self::assertSame('publicado', $this->estado($o));
        $h = $this->s->revisao->historico($o->id);
        self::assertSame(['aprovou', 'enviou'], array_column($h, 'acao'));
        self::assertSame('Conta editor_saude', $h[0]['quem']);
        self::assertNotEmpty($this->auditoria('revisao.aprovou'));
        self::assertNotEmpty($this->auditoria('revisao.enviou'));
    }

    public function testRevisorEhEditorDaAreaDeCimaOuAdminENuncaDeOutraSecretaria(): void
    {
        $o = $this->submetido('Alerta de vigilância', $this->vigilancia);
        $quem = fn (string $login): bool => str_contains($this->html($this->req('GET', '/_pbl/pendencias', $this->token($login))), 'Alerta de vigilância');
        self::assertTrue($quem('editor_vigilancia'), 'editor do setor');
        self::assertTrue($quem('editor_saude'), 'editor da secretaria (cascata)');
        self::assertTrue($quem('editor'), 'editor da raiz (cascata)');
        self::assertTrue($quem('admin'), 'administrador');
        self::assertFalse($quem('editor_edu'), 'outra secretaria');
        self::assertSame(403, $this->req('GET', '/_pbl/pendencias', $this->token('autor_saude'))->getStatusCode(), 'autor não revisa');
        self::assertSame(403, $this->req('GET', '/_pbl/pendencias', $this->token('leitor'))->getStatusCode());
        // E o editor de outro setor da mesma secretaria não vê o que é do setor ao lado.
        $z = $this->submetido('Zoonose suspeita', $this->zoonoses);
        self::assertFalse(str_contains($this->html($this->req('GET', '/_pbl/pendencias', $this->token('editor_vigilancia'))), 'Zoonose suspeita'));
        self::assertSame(403, $this->passo('editor_vigilancia', $z, 'aprovar'));
        self::assertSame(403, $this->passo('editor_edu', $o, 'aprovar'));
        self::assertSame(403, $this->passo('editor_edu', $o, 'devolver', ['motivo' => 'não é minha área']));
        self::assertSame('submetido', $this->estado($o));
        self::assertSame('submetido', $this->estado($z));
        self::assertSame(303, $this->passo('editor_vigilancia', $o, 'aprovar'));
    }

    public function testAutorNaoAprovaNemDevolveNemEnviaForaDaSuaArea(): void
    {
        $o = $this->submetido('Do autor', $this->saude);
        self::assertSame(403, $this->passo('autor_saude', $o, 'aprovar'));
        self::assertSame(403, $this->passo('autor_saude', $o, 'devolver', ['motivo' => 'eu mesmo']));
        self::assertSame(403, $this->passo('leitor', $o, 'aprovar'));
        self::assertNotEmpty($this->auditoria('revisao.negado'), 'tentativa negada fica registrada');
        $fora = $this->conteudo('Em Educação', $this->educacao, 'autor_saude');
        self::assertSame(403, $this->passo('autor_saude', $fora, 'enviar'), 'autor de Saúde não envia em Educação');
        self::assertSame('privado', $this->estado($fora));
        $alheio = $this->conteudo('De outra autora', $this->saude, 'autor_saude2');
        self::assertSame(403, $this->passo('autor_saude', $alheio, 'enviar'), 'autor só envia o que é seu');
    }

    public function testDevolverExigeMotivoEAutorReenvia(): void
    {
        $o = $this->submetido('Para devolver', $this->saude);
        self::assertSame(422, $this->passo('editor_saude', $o, 'devolver', ['motivo' => '']));
        self::assertSame(422, $this->passo('editor_saude', $o, 'devolver', ['motivo' => '  ab ']));
        self::assertSame(422, $this->passo('editor_saude', $o, 'devolver', ['motivo' => str_repeat('x', 501)]));
        self::assertSame(422, $this->passo('editor_saude', $o, 'devolver', []));
        self::assertSame('submetido', $this->estado($o));
        self::assertSame(303, $this->passo('editor_saude', $o, 'devolver', ['motivo' => 'Falta a fonte dos números.'], 'pendencias'));
        self::assertSame('rejeitado', $this->estado($o));

        $meus = $this->html($this->req('GET', '/_pbl/meus-envios', $this->token('autor_saude')));
        self::assertStringContainsString('Para devolver', $meus);
        self::assertStringContainsString('devolvido', $meus);
        self::assertStringContainsString('Falta a fonte dos números.', $meus);
        self::assertStringContainsString('/reenviar', $meus);
        self::assertStringContainsString('/editar', $meus);
        // A mensagem de devolução nunca vai para a auditoria.
        self::assertStringNotContainsString('Falta a fonte', json_encode($this->s->auditoria->recentes(500), JSON_UNESCAPED_UNICODE) ?: '');

        self::assertSame(303, $this->passo('autor_saude', $o, 'reenviar', [], 'meus-envios'));
        self::assertSame('submetido', $this->estado($o));
        self::assertSame(['reenviou', 'devolveu', 'enviou'], array_column($this->s->revisao->historico($o->id), 'acao'));
        // Outra autora não reenvia o que não é dela.
        self::assertSame(1, $this->s->revisao->contarPendencias($this->usuario('editor_saude')));
    }

    public function testAutorRetiraOEnvioMasSoQuemEnviou(): void
    {
        $o = $this->submetido('Para retirar', $this->saude);
        self::assertSame(403, $this->passo('autor_saude2', $o, 'retirar'));
        self::assertSame('submetido', $this->estado($o));
        self::assertSame(303, $this->passo('autor_saude', $o, 'retirar', [], 'meus-envios'));
        self::assertSame('privado', $this->estado($o));
        self::assertSame(0, $this->s->revisao->contarPendencias($this->usuario('editor_saude')));
        self::assertSame('retirou', $this->s->revisao->historico($o->id)[0]['acao']);
    }

    public function testTransicoesInvalidas(): void
    {
        $privado = $this->conteudo('Privado', $this->saude);
        $publicado = $this->conteudo('Publicado', $this->saude, 'autor_saude', 'publicado');
        self::assertSame(409, $this->passo('editor_saude', $privado, 'aprovar'));
        self::assertSame(409, $this->passo('editor_saude', $privado, 'devolver', ['motivo' => 'motivo válido']));
        self::assertSame(409, $this->passo('autor_saude', $privado, 'retirar'));
        self::assertSame(409, $this->passo('editor_saude', $publicado, 'enviar'));
        self::assertSame(409, $this->passo('editor_saude', $publicado, 'aprovar'));
        $sub = $this->submetido('Já enviado', $this->saude);
        self::assertSame(409, $this->passo('autor_saude', $sub, 'enviar'), 'enviar duas vezes');
        self::assertSame(409, $this->passo('autor_saude', $sub, 'reenviar'));
        $this->passo('editor_saude', $sub, 'aprovar');
        self::assertSame(409, $this->passo('editor_saude', $sub, 'aprovar'), 'aprovar duas vezes');
        self::assertSame(403, $this->post($this->token('editor_saude'), '/_pbl/revisao/999999/aprovar', ['versao' => '1'])->getStatusCode());
        self::assertSame(404, $this->post($this->token('editor_saude'), '/_pbl/revisao/' . $sub->id . '/apagar', [])->getStatusCode());
        self::assertSame(404, $this->req('GET', '/_pbl/revisao/' . $sub->id . '/aprovar', $this->token('editor_saude'))->getStatusCode());
    }

    public function testVersaoDesatualizadaEhConflito(): void
    {
        $o = $this->submetido('Mudou no caminho', $this->saude);
        $r = $this->post($this->token('editor_saude'), '/_pbl/revisao/' . $o->id . '/aprovar', ['versao' => (string) ($o->versao - 1), 'voltar' => 'pendencias']);
        self::assertSame(409, $r->getStatusCode());
        self::assertSame('submetido', $this->estado($o));
    }

    public function testCsrfEOrigemSaoConferidosAntesDeTudo(): void
    {
        $o = $this->submetido('Protegido', $this->saude);
        $t = $this->token('editor_saude');
        self::assertSame(403, $this->post($t, '/_pbl/revisao/' . $o->id . '/aprovar', ['versao' => (string) $o->versao], false)->getStatusCode());
        self::assertSame(403, $this->post($t, '/_pbl/revisao/' . $o->id . '/aprovar', ['versao' => (string) $o->versao, '_csrf' => 'errado'], false)->getStatusCode());
        $r = $this->req('POST', '/_pbl/revisao/' . $o->id . '/aprovar', $t, ['_csrf' => $this->csrf($t), 'versao' => (string) $o->versao], ['HTTP_ORIGIN' => 'https://evil.example']);
        self::assertSame(403, $r->getStatusCode());
        self::assertSame('submetido', $this->estado($o));
        // Sem sessão: vai para a tela de entrar e não grava nada.
        self::assertSame(303, $this->req('POST', '/_pbl/revisao/' . $o->id . '/aprovar', null, ['versao' => (string) $o->versao], self::ORIGEM)->getStatusCode());
        self::assertSame('submetido', $this->estado($o));
    }

    public function testAutorNaoEditaEnquantoPendente(): void
    {
        $o = $this->submetido('Congelado', $this->saude);
        $t = $this->token('autor_saude');
        self::assertSame(403, $this->req('GET', '/_pbl/objeto/' . $o->id . '/editar', $t)->getStatusCode());
        self::assertSame(403, $this->post($t, '/_pbl/objeto/' . $o->id . '/editar', ['versao' => (string) $o->versao, 'titulo' => 'Trocado'])->getStatusCode());
        self::assertSame('Congelado', ($this->s->objetos->porId($o->id) ?? self::fail())->titulo);
        // Quem revisa continua podendo editar; ao retirar, o autor volta a editar.
        self::assertSame(200, $this->req('GET', '/_pbl/objeto/' . $o->id . '/editar', $this->token('editor_saude'))->getStatusCode());
        $this->passo('autor_saude', $o, 'retirar');
        self::assertSame(200, $this->req('GET', '/_pbl/objeto/' . $o->id . '/editar', $t)->getStatusCode());
    }

    public function testContadorNoMenuEAvisosNaTelaInicial(): void
    {
        $this->submetido('Primeiro', $this->saude);
        $this->submetido('Segundo', $this->vigilancia);
        $devolvido = $this->submetido('Terceiro', $this->saude, 'autor_saude2');
        $this->passo('editor_saude', $devolvido, 'devolver', ['motivo' => 'Ajustar o título.']);

        $editor = $this->html($this->req('GET', '/_pbl/', $this->token('editor_saude')));
        self::assertStringContainsString('/_pbl/pendencias', $editor);
        self::assertMatchesRegularExpression('#Pendências <span class="badge text-bg-warning"[^>]*>2</span>#', $editor);
        self::assertStringContainsString('<strong>2</strong> envios aguardam', $editor);
        self::assertStringNotContainsString('Meus envios', $this->html($this->req('GET', '/_pbl/', $this->token('leitor'))));

        $autor = $this->html($this->req('GET', '/_pbl/', $this->token('autor_saude2')));
        self::assertStringContainsString('Meus envios', $autor);
        self::assertStringNotContainsString('>Pendências', $autor);
        self::assertStringContainsString('1</strong> conteúdo seu foi devolvido', $autor);

        $outra = $this->html($this->req('GET', '/_pbl/', $this->token('editor_edu')));
        self::assertDoesNotMatchRegularExpression('#Pendências <span class="badge#', $outra);
        // Contagem e fila vêm da mesma regra.
        self::assertSame(2, $this->s->revisao->contarPendencias($this->usuario('editor_saude')));
        self::assertCount(2, $this->s->revisao->pendencias($this->usuario('editor_saude')));
        self::assertSame(1, $this->s->revisao->contarPendencias($this->usuario('editor_vigilancia')));
        self::assertSame(0, $this->s->revisao->contarPendencias($this->usuario('editor_edu')));
    }

    public function testFiltroPorAreaEMeusEnviosPorAba(): void
    {
        $this->submetido('Na vigilância', $this->vigilancia);
        $this->submetido('Nas zoonoses', $this->zoonoses);
        $t = $this->token('editor_saude');
        $todas = $this->html($this->req('GET', '/_pbl/pendencias', $t));
        self::assertStringContainsString('Na vigilância', $todas);
        self::assertStringContainsString('Nas zoonoses', $todas);
        $so = $this->html($this->req('GET', '/_pbl/pendencias?area=' . $this->vigilancia, $t));
        self::assertStringContainsString('Na vigilância', $so);
        self::assertStringNotContainsString('Nas zoonoses', $so);
        // Área fora das opções da conta é ignorada (não vaza nada).
        $fora = $this->html($this->req('GET', '/_pbl/pendencias?area=' . $this->educacao, $t));
        self::assertStringContainsString('Na vigilância', $fora);
        // Meus envios: o autor vê só o que é dele, por aba.
        $meus = $this->html($this->req('GET', '/_pbl/meus-envios?estado=revisao', $this->token('autor_saude')));
        self::assertStringContainsString('Na vigilância', $meus);
        self::assertStringContainsString('/retirar', $meus);
        self::assertStringNotContainsString('/aprovar', $meus);
        $outro = $this->html($this->req('GET', '/_pbl/meus-envios', $this->token('autor_saude2')));
        self::assertStringNotContainsString('Na vigilância', $outro);
        self::assertSame(200, $this->req('GET', '/_pbl/meus-envios?estado=qualquer', $this->token('autor_saude'))->getStatusCode());
    }

    public function testBotoesPorPapelNaPaginaDoConteudo(): void
    {
        $o = $this->conteudo('Página de teste', $this->saude);
        $autor = $this->html($this->req('GET', '/_pbl/objeto/' . $o->id, $this->token('autor_saude')));
        self::assertStringContainsString('Enviar para revisão', $autor);
        self::assertStringNotContainsString('Aprovar', $autor);
        $this->passo('autor_saude', $o, 'enviar');
        $autor = $this->html($this->req('GET', '/_pbl/objeto/' . $o->id, $this->token('autor_saude')));
        self::assertStringContainsString('Retirar envio', $autor);
        self::assertStringNotContainsString('Aprovar e publicar', $autor);
        $editor = $this->html($this->req('GET', '/_pbl/objeto/' . $o->id, $this->token('editor_saude')));
        self::assertStringContainsString('Aprovar e publicar', $editor);
        self::assertStringContainsString('Devolver', $editor);
        self::assertStringNotContainsString('Retirar envio', $editor);
        self::assertStringContainsString('enviou para revisão', $editor);
        // Redireciona de volta com o aviso.
        $r = $this->post($this->token('editor_saude'), '/_pbl/revisao/' . $o->id . '/aprovar', ['versao' => (string) ($this->s->objetos->porId($o->id)?->versao), 'voltar' => 'objeto']);
        self::assertSame('/_pbl/objeto/' . $o->id . '/previa?salvo=aprovado', $r->headers->get('Location'));
        self::assertStringContainsString('Aprovado e publicado', $this->html($this->req('GET', '/_pbl/objeto/' . $o->id . '?salvo=aprovado', $this->token('editor_saude'))));
    }

    public function testServicoValidaMotivoEPermissaoDiretamente(): void
    {
        $o = $this->submetido('Direto', $this->saude);
        try {
            $this->s->revisao->devolver($this->usuario('editor_saude'), $o->id, "   \n ");
            self::fail('motivo vazio deveria falhar');
        } catch (ErroDeRevisao $e) {
            self::assertSame(ErroDeRevisao::MOTIVO, $e->codigo);
        }
        try {
            $this->s->revisao->aprovar($this->usuario('autor_saude'), $o->id);
            self::fail('autor não aprova');
        } catch (ErroDeRevisao $e) {
            self::assertSame(ErroDeRevisao::NEGADO, $e->codigo);
        }
        self::assertNull(FluxoDeRevisao::problemaNoMotivo('Motivo bom'));
        self::assertNotNull(FluxoDeRevisao::problemaNoMotivo("com\x00controle"));
        $this->s->revisao->aprovar($this->usuario('admin'), $o->id);
        self::assertSame('publicado', $this->estado($o));
    }

    public function testMigracaoEhIdempotente(): void
    {
        $m = new M019FluxoDeRevisao();
        self::assertSame(19, $m->versao());
        $m->aplicar($this->s->conexao());
        $m->aplicar($this->s->conexao());
        self::assertTrue($this->s->conexao()->createSchemaManager()->tablesExist(['revisao']));
    }

    public function testDemoCriaAutorEEditorComUmPendenteEUmDevolvido(): void
    {
        $this->s->conexao()->delete('acesso', ['usuario_id' => $this->ids['editor']]);
        $this->s->conexao()->delete('usuario', ['id' => $this->ids['editor']]);
        $t = new CommandTester(new DemoPopularComando($this->caminhos));
        self::assertSame(0, $t->execute(['--site' => (string) $this->siteId, '--sem-publicar' => true, '--forcar' => true]), $t->getDisplay());
        self::assertStringContainsString(DemoPopularComando::SENHA_DEMO, $t->getDisplay());
        $s = \Pbl\Servicos::doBancoInstalado($this->caminhos);
        $autor = $s->usuarios->porLogin('autor') ?? self::fail();
        $editor = $s->usuarios->porLogin('editor') ?? self::fail();
        self::assertNotNull($s->usuarios->autenticar('autor', DemoPopularComando::SENHA_DEMO));
        self::assertCount(1, $s->usuarios->acessosDe($autor->id));
        self::assertCount(1, $s->usuarios->acessosDe($editor->id));
        $fila = $s->revisao->pendencias($editor);
        self::assertCount(1, $fila);
        self::assertSame('submetido', $fila[0]->status);
        $meus = array_map(static fn (Objeto $o): string => $o->status, $s->revisao->meusEnvios($autor));
        sort($meus);
        self::assertSame(['rejeitado', 'submetido'], $meus);
        $devolvido = array_values(array_filter($s->revisao->meusEnvios($autor), static fn (Objeto $o): bool => $o->status === 'rejeitado'))[0];
        self::assertStringContainsString('Faltou citar a fonte', (string) $s->revisao->historico($devolvido->id)[0]['motivo']);
        // Idempotente: rodar de novo não duplica contas, conteúdo nem histórico.
        $antes = (int) $s->conexao()->fetchOne('SELECT COUNT(*) FROM revisao');
        self::assertSame(0, $t->execute(['--site' => (string) $this->siteId, '--sem-publicar' => true, '--forcar' => true]), $t->getDisplay());
        self::assertSame($antes, (int) $s->conexao()->fetchOne('SELECT COUNT(*) FROM revisao'));
        self::assertSame(1, (int) $s->conexao()->fetchOne("SELECT COUNT(*) FROM usuario WHERE login = 'autor'"));
    }

    private function usuario(string $login): \Pbl\Acesso\Usuario
    {
        return $this->s->usuarios->porId($this->ids[$login]) ?? self::fail();
    }
}

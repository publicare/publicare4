<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Email\ConfigDeEmail;
use Pbl\Email\Transporte;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/** Telas de recuperação de acesso, convite, perfil e e-mail, de ponta a ponta com o driver `arquivo` (ADR 0033 a 0038). */
final class TelasDeContaTest extends TestCase
{
    use AdminAuxiliar;

    private const NOVA = 'outra senha ainda mais longa 2027';

    private function ligarEmail(string $driver = Transporte::ARQUIVO): void
    {
        $this->s->configDeEmail()->salvar(new ConfigDeEmail($driver, remetente: 'Site <nao-responda@exemplo.org>'));
    }

    /** @return list<string> */
    private function caixa(): array
    {
        $a = glob($this->caminhos->dados() . '/email/caixa/*.eml') ?: [];
        sort($a);

        return array_map(static fn (string $f): string => (string) file_get_contents($f), $a);
    }

    private function tokenDoEmail(string $trecho): string
    {
        $achou = null;
        foreach ($this->caixa() as $e) {
            if (preg_match('#/_pbl/' . $trecho . '/([0-9a-f]{64})#', $e, $m) === 1) {
                $achou = $m[1];
            }
        }
        self::assertNotNull($achou, 'link no e-mail');

        return $achou;
    }

    /** @param array<string, mixed> $campos */
    private function publico(string $caminho, array $campos, bool $comCsrf = true): Response
    {
        return $this->req('POST', $caminho, null, ($comCsrf ? ['_csrf' => $this->s->csrf()->paraEntrar()] : []) + $campos, self::ORIGEM);
    }

    public function testEntrarTemOsLinksDeRecuperacao(): void
    {
        $h = $this->html($this->req('GET', '/_pbl/entrar'));
        self::assertStringContainsString('href="/_pbl/esqueci"', $h);
        self::assertStringContainsString('href="/_pbl/esqueci-usuario"', $h);
    }

    public function testSemEmailAMensagemEClaraEONadaEEnviado(): void
    {
        foreach (['/_pbl/esqueci', '/_pbl/esqueci-usuario'] as $c) {
            $r = $this->req('GET', $c);
            self::assertSame(200, $r->getStatusCode());
            self::assertStringContainsString('não está ativada', $this->html($r));
            self::assertStringNotContainsString('<form method="post" action="' . $c, $this->html($r));
        }
        self::assertSame([], $this->caixa());
    }

    public function testRespostaDeEsqueciEIdenticaParaContaExistenteEInexistente(): void
    {
        $this->ligarEmail();
        foreach (['/_pbl/esqueci', '/_pbl/esqueci-usuario'] as $caminho) {
            $respostas = [];
            foreach (['editor', 'editor@exemplo.org', 'ninguem', 'ninguem@exemplo.org', ''] as $ident) {
                $r = $this->publico($caminho, ['identificador' => $ident]);
                $respostas[] = [$r->getStatusCode(), $this->html($r), $r->headers->get('Content-Type'), $r->headers->get('Cache-Control')];
            }
            self::assertSame(200, $respostas[0][0]);
            foreach ($respostas as $r) {
                self::assertSame($respostas[0], $r, $caminho);
            }
        }
        self::assertSame([], $this->caixa(), 'nada é enviado dentro da requisição');
        $this->s->recuperacao()->processarFila();
        self::assertCount(3, $this->caixa(), 'editor (login), editor (e-mail) e o usuário do e-mail');
    }

    public function testPostPublicoSemCsrfOuOrigemRecusado(): void
    {
        $this->ligarEmail();
        self::assertSame(403, $this->publico('/_pbl/esqueci', ['identificador' => 'editor'], false)->getStatusCode());
        $r = $this->req('POST', '/_pbl/esqueci', null, ['_csrf' => $this->s->csrf()->paraEntrar(), 'identificador' => 'editor'], ['HTTP_ORIGIN' => 'https://mal.example']);
        self::assertSame(403, $r->getStatusCode());
        $this->s->recuperacao()->processarFila();
        self::assertSame([], $this->caixa());
    }

    public function testFluxoCompletoDeRecuperacaoDeSenha(): void
    {
        $this->ligarEmail();
        $outra = $this->token('editor');
        $this->publico('/_pbl/esqueci', ['identificador' => 'editor']);
        $this->s->recuperacao()->processarFila();
        $token = $this->tokenDoEmail('redefinir-senha');
        $rota = '/_pbl/redefinir-senha/' . $token;

        $g = $this->req('GET', $rota);
        self::assertSame(200, $g->getStatusCode());
        self::assertSame('no-referrer', $g->headers->get('Referrer-Policy'));
        self::assertStringContainsString("script-src 'self'", (string) $g->headers->get('Content-Security-Policy'));
        self::assertStringNotContainsString('<script>', $this->html($g));
        self::assertSame(200, $this->req('GET', $rota)->getStatusCode(), 'o GET não consome o link');

        $fraca = $this->publico($rota, ['senha' => 'curta', 'senha2' => 'curta']);
        self::assertSame(422, $fraca->getStatusCode());
        $difere = $this->publico($rota, ['senha' => self::NOVA, 'senha2' => self::NOVA . 'x']);
        self::assertSame(422, $difere->getStatusCode());
        self::assertSame(200, $this->req('GET', $rota)->getStatusCode(), 'erro de senha não queima o link');

        $ok = $this->publico($rota, ['senha' => self::NOVA, 'senha2' => self::NOVA]);
        self::assertSame(200, $ok->getStatusCode());
        self::assertFalse($this->sessaoViva($outra), 'as outras sessões caem');
        self::assertNotNull($this->s->usuarios->autenticar('editor', self::NOVA));
        self::assertNull($this->s->usuarios->autenticar('editor', self::SENHA));
        self::assertStringContainsString('acabou de ser alterada', implode("\n", $this->caixa()), 'aviso enviado');
        self::assertGreaterThanOrEqual(2, count($this->caixa()), 'link + aviso de senha alterada');

        $reuso = $this->publico($rota, ['senha' => 'mais uma senha longa 2028', 'senha2' => 'mais uma senha longa 2028']);
        self::assertSame(400, $reuso->getStatusCode());
        self::assertSame(400, $this->req('GET', $rota)->getStatusCode());
        self::assertNull($this->s->usuarios->autenticar('editor', 'mais uma senha longa 2028'));
        self::assertSame(400, $this->req('GET', '/_pbl/redefinir-senha/' . str_repeat('a', 64))->getStatusCode(), 'token inventado');
        self::assertSame(404, $this->req('GET', '/_pbl/redefinir-senha/curto')->getStatusCode());
    }

    public function testTokenDoBancoEApenasHashENaoAbreComoConvite(): void
    {
        $this->ligarEmail();
        $this->publico('/_pbl/esqueci', ['identificador' => 'editor']);
        $this->s->recuperacao()->processarFila();
        $token = $this->tokenDoEmail('redefinir-senha');
        self::assertFalse($this->s->conexao()->fetchOne('SELECT 1 FROM token_conta WHERE token_hash = ?', [$token]), 'o token em claro não está no banco');
        self::assertSame(400, $this->req('GET', '/_pbl/convite/' . $token)->getStatusCode(), 'tipo errado');
    }

    public function testEsqueciUsuarioEnviaOsLoginsDoEmail(): void
    {
        $this->ligarEmail();
        $this->publico('/_pbl/esqueci-usuario', ['identificador' => 'leitor@exemplo.org']);
        $this->s->recuperacao()->processarFila();
        self::assertStringContainsString('leitor', implode("\n", $this->caixa()));
    }

    public function testConviteDeUsuarioDoPedidoAoAceite(): void
    {
        $this->ligarEmail();
        $adm = $this->token('admin');
        self::assertStringContainsString('action="/_pbl/contas/convidar"', $this->html($this->req('GET', '/_pbl/contas/convidar', $adm)));
        $dados = ['login' => 'nova.pessoa', 'nome' => 'Nova Pessoa', 'email' => 'nova@exemplo.org'];
        self::assertSame(303, $this->post($adm, '/_pbl/contas/convidar', $dados)->getStatusCode());
        $id = $this->s->usuarios->porLogin('nova.pessoa')?->id;
        self::assertNotNull($id);
        self::assertStringContainsString('Convite pendente', $this->html($this->req('GET', '/_pbl/contas', $adm)));
        self::assertNull($this->s->usuarios->autenticar('nova.pessoa', ''), 'não entra antes de aceitar');

        $primeiro = $this->tokenDoEmail('convite');
        self::assertSame(303, $this->post($adm, "/_pbl/conta/{$id}/reenviar-convite", [])->getStatusCode());
        self::assertSame(2, count($this->caixa()));
        $segundo = $this->tokenDoEmail('convite');
        self::assertNotSame($primeiro, $segundo);
        self::assertSame(400, $this->req('GET', '/_pbl/convite/' . $primeiro)->getStatusCode(), 'o link antigo morreu');

        $r = $this->publico('/_pbl/convite/' . $segundo, ['senha' => self::NOVA, 'senha2' => self::NOVA]);
        self::assertSame(200, $r->getStatusCode());
        self::assertNotNull($this->s->usuarios->autenticar('nova.pessoa', self::NOVA));
        self::assertSame(400, $this->publico('/_pbl/convite/' . $segundo, ['senha' => self::NOVA, 'senha2' => self::NOVA])->getStatusCode());
    }

    public function testCancelarConviteApagaAContaPendente(): void
    {
        $this->ligarEmail();
        $adm = $this->token('admin');
        $this->post($adm, '/_pbl/contas/convidar', ['login' => 'cancelada', 'nome' => 'C', 'email' => 'c@exemplo.org']);
        $id = (int) $this->s->usuarios->porLogin('cancelada')?->id;
        $token = $this->tokenDoEmail('convite');
        self::assertSame(303, $this->post($adm, "/_pbl/conta/{$id}/cancelar-convite", [])->getStatusCode());
        self::assertNull($this->s->usuarios->porLogin('cancelada'));
        self::assertSame(400, $this->req('GET', '/_pbl/convite/' . $token)->getStatusCode());
        self::assertSame(404, $this->post($adm, "/_pbl/conta/{$id}/cancelar-convite", [])->getStatusCode());
    }

    public function testConviteSemEmailAvisaEQuemNaoEAdminNaoConvida(): void
    {
        $adm = $this->token('admin');
        self::assertStringContainsString('não está configurado', $this->html($this->req('GET', '/_pbl/contas/convidar', $adm)));
        $this->ligarEmail();
        $ed = $this->token('editor');
        self::assertSame(403, $this->req('GET', '/_pbl/contas/convidar', $ed)->getStatusCode());
        self::assertSame(403, $this->post($ed, '/_pbl/contas/convidar', ['login' => 'x1x', 'nome' => 'X', 'email' => 'x@exemplo.org'])->getStatusCode());
        self::assertSame(403, $this->req('GET', '/_pbl/email', $ed)->getStatusCode());
        self::assertSame(403, $this->post($ed, '/_pbl/email', ['driver' => 'arquivo'])->getStatusCode());
        self::assertNull($this->s->usuarios->porLogin('x1x'));
        self::assertSame(303, $this->req('GET', '/_pbl/perfil')->getStatusCode());
        self::assertSame(403, $this->post($adm, '/_pbl/contas/convidar', ['login' => 'x2x'], false)->getStatusCode());
    }

    public function testPerfilTrocaSenhaMantendoASessaoAtual(): void
    {
        $atual = $this->tokenSemElevar('editor');
        $outra = $this->tokenSemElevar('editor');
        self::assertSame(200, $this->req('GET', '/_pbl/perfil', $atual)->getStatusCode());

        $errada = $this->post($atual, '/_pbl/perfil/senha', ['senha_atual' => 'errada errada errada', 'senha' => self::NOVA, 'senha2' => self::NOVA]);
        self::assertSame(401, $errada->getStatusCode());
        self::assertNull($this->s->usuarios->autenticar('editor', self::NOVA));

        $ok = $this->post($atual, '/_pbl/perfil/senha', ['senha_atual' => self::SENHA, 'senha' => self::NOVA, 'senha2' => self::NOVA]);
        self::assertSame(303, $ok->getStatusCode());
        self::assertTrue($this->sessaoViva($atual), 'a sessão atual continua');
        self::assertFalse($this->sessaoViva($outra), 'as outras caem');
        self::assertNotNull($this->s->usuarios->autenticar('editor', self::NOVA));
        self::assertCount(1, $this->auditoria('senha.trocar'));
    }

    public function testPerfilExigeSenhaForteCsrfENomeSoDaPropriaConta(): void
    {
        $t = $this->tokenSemElevar('editor');
        self::assertSame(422, $this->post($t, '/_pbl/perfil/senha', ['senha_atual' => self::SENHA, 'senha' => 'curta', 'senha2' => 'curta'])->getStatusCode());
        self::assertSame(403, $this->post($t, '/_pbl/perfil/senha', ['senha_atual' => self::SENHA, 'senha' => self::NOVA, 'senha2' => self::NOVA], false)->getStatusCode());
        self::assertNull($this->s->usuarios->autenticar('editor', self::NOVA));
        self::assertSame(303, $this->post($t, '/_pbl/perfil/nome', ['nome' => 'Novo Nome', 'id' => (string) $this->ids['admin']])->getStatusCode());
        self::assertSame('Novo Nome', $this->s->usuarios->porId($this->ids['editor'])?->nome);
        self::assertSame('Admin', $this->s->usuarios->porId($this->ids['admin'])?->nome);
    }

    public function testPerfilListaEEncerraSessoesSemExporOToken(): void
    {
        $atual = $this->tokenSemElevar('editor');
        $outra = $this->tokenSemElevar('editor');
        $alheia = $this->tokenSemElevar('admin');
        $html = $this->html($this->req('GET', '/_pbl/perfil', $atual));
        self::assertStringContainsString('esta sessão', $html);
        self::assertStringNotContainsString($atual, $html);
        self::assertStringNotContainsString(hash('sha256', $outra), $html);
        $ref = substr(hash('sha256', $outra), 0, 16);
        self::assertStringContainsString($ref, $html);

        // a sessão atual e a de outra conta não podem ser encerradas por referência
        self::assertSame(422, $this->post($atual, '/_pbl/perfil/sessoes/encerrar', ['ref' => substr(hash('sha256', $atual), 0, 16)])->getStatusCode());
        self::assertSame(422, $this->post($atual, '/_pbl/perfil/sessoes/encerrar', ['ref' => substr(hash('sha256', $alheia), 0, 16)])->getStatusCode());
        self::assertSame(403, $this->post($atual, '/_pbl/perfil/sessoes/encerrar', ['ref' => $ref], false)->getStatusCode());
        self::assertTrue($this->sessaoViva($outra));
        self::assertSame(303, $this->post($atual, '/_pbl/perfil/sessoes/encerrar', ['ref' => $ref])->getStatusCode());
        self::assertFalse($this->sessaoViva($outra));

        $terceira = $this->tokenSemElevar('editor');
        self::assertSame(303, $this->post($atual, '/_pbl/perfil/sessoes/encerrar-outras', [])->getStatusCode());
        self::assertFalse($this->sessaoViva($terceira));
        self::assertTrue($this->sessaoViva($atual));
        self::assertTrue($this->sessaoViva($alheia));
    }

    public function testConfigurarEmailNuncaMostraNemAuditaASenhaDoSmtp(): void
    {
        $segredo = 'sm' . 'tp-' . bin2hex(random_bytes(6));
        $adm = $this->token('admin');
        $campos = ['driver' => 'smtp', 'remetente' => 'Site <nao@exemplo.org>', 'host' => 'smtp.exemplo.org', 'porta' => '587', 'seguranca' => 'starttls', 'usuario' => 'robo', 'senha_smtp' => $segredo];
        self::assertSame(303, $this->post($adm, '/_pbl/email', $campos)->getStatusCode());
        $pagina = $this->html($this->req('GET', '/_pbl/email', $adm));
        self::assertStringNotContainsString($segredo, $pagina);
        self::assertStringContainsString('••••••••', $pagina);
        self::assertStringNotContainsString($segredo, (string) file_get_contents($this->caminhos->config() . '/email.json'), 'cifrada em repouso');
        self::assertSame($segredo, $this->s->configDeEmail()->carregar()->senha);
        foreach ($this->s->auditoria->recentes(100) as $l) {
            self::assertStringNotContainsString($segredo, json_encode($l, JSON_THROW_ON_ERROR));
        }
        self::assertCount(1, $this->auditoria('email.configurar'));
        // senha em branco mantém a guardada
        $this->post($adm, '/_pbl/email', ['senha_smtp' => ''] + $campos);
        self::assertSame($segredo, $this->s->configDeEmail()->carregar()->senha);
        // configuração inválida é recusada
        self::assertSame(422, $this->post($adm, '/_pbl/email', ['host' => "a\r\nb"] + $campos)->getStatusCode());
    }

    public function testEnviarTesteGravaNaCaixa(): void
    {
        $this->ligarEmail();
        $adm = $this->token('admin');
        self::assertSame(303, $this->post($adm, '/_pbl/email/teste', [])->getStatusCode());
        self::assertCount(1, $this->caixa());
    }
}

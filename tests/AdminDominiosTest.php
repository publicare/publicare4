<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Conteudo\Dominio;
use PHPUnit\Framework\TestCase;

/** Telas de domínios de um site na administração, de ponta a ponta (ADR 0012, fase 5d). */
final class AdminDominiosTest extends TestCase
{
    use AdminAuxiliar;

    private function lista(): string
    {
        return "/_pbl/site/{$this->siteId}/dominios";
    }

    /** @return array<string, string> host => modo */
    private function dominios(?int $site = null): array
    {
        $r = [];
        foreach ($this->s->dominios->doSite($site ?? $this->siteId) as $d) {
            $r[$d->host] = $d->modo;
        }

        return $r;
    }

    /** @return list<string> */
    private function posts(): array
    {
        return array_map(fn (string $a): string => $this->lista() . '/' . $a, ['adicionar', 'principal', 'modo', 'remover']);
    }

    public function testSemLoginMandaParaEntrarEOPostNaoGrava(): void
    {
        $r = $this->req('GET', $this->lista());
        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/_pbl/entrar', $r->headers->get('Location'));
        foreach ($this->posts() as $caminho) {
            self::assertSame(303, $this->req('POST', $caminho, null, ['host' => 'novo.exemplo.org', 'modo' => 'servir'], self::ORIGEM)->getStatusCode(), $caminho);
        }
        self::assertSame(['www.exemplo.org' => 'principal'], $this->dominios());
    }

    public function testQuemNaoAdministraOSiteNaoVeNemAlteraEATentativaFicaNaAuditoria(): void
    {
        foreach (['editor', 'leitor'] as $login) {
            $token = $this->token($login);
            self::assertSame(403, $this->req('GET', $this->lista(), $token)->getStatusCode(), $login);
            self::assertSame($this->html($this->req('GET', $this->lista(), $token)), $this->html($this->req('GET', '/_pbl/site/999999/dominios', $token)), 'site inexistente = sem permissão');
            self::assertStringNotContainsString('/dominios', $this->html($this->req('GET', '/_pbl/', $token)), 'sem botão de domínios');
        }
        $token = $this->token('editor');
        foreach ($this->posts() as $caminho) {
            self::assertSame(403, $this->post($token, $caminho, ['host' => 'novo.exemplo.org', 'modo' => 'servir'])->getStatusCode(), $caminho);
        }
        self::assertSame(['www.exemplo.org' => 'principal'], $this->dominios());
        $negados = $this->auditoria('dominio.negado');
        self::assertCount(4, $negados);
        self::assertSame('usuario:' . $this->ids['editor'], $negados[0]['ator']);
        self::assertStringNotContainsString('novo.exemplo.org', (string) json_encode($negados));
    }

    public function testCsrfEOrigemSaoConferidosEmTodoPost(): void
    {
        $token = $this->token('admin');
        $outro = $this->token('editor');
        $campos = ['host' => 'novo.exemplo.org', 'modo' => 'servir'];
        foreach ($this->posts() as $caminho) {
            self::assertSame(403, $this->req('POST', $caminho, $token, $campos, self::ORIGEM)->getStatusCode(), "{$caminho} sem token");
            self::assertSame(403, $this->req('POST', $caminho, $token, ['_csrf' => 'x'] + $campos, self::ORIGEM)->getStatusCode(), "{$caminho} token errado");
            self::assertSame(403, $this->req('POST', $caminho, $token, ['_csrf' => $this->csrf($outro)] + $campos, self::ORIGEM)->getStatusCode(), "{$caminho} token de outra sessão");
            self::assertSame(403, $this->req('POST', $caminho, $token, ['_csrf' => $this->csrf($token)] + $campos, ['HTTP_ORIGIN' => 'https://evil.example'])->getStatusCode(), "{$caminho} origem de fora");
        }
        self::assertSame(['www.exemplo.org' => 'principal'], $this->dominios());
    }

    public function testRotasInexistentesDao404(): void
    {
        $token = $this->token('admin');
        foreach (["/_pbl/site/0/dominios", "/_pbl/site/01/dominios", "/_pbl/site/{$this->siteId}/dominios/apagar", "/_pbl/site/{$this->siteId}/dominio"] as $caminho) {
            self::assertSame(404, $this->req('GET', $caminho, $token)->getStatusCode(), $caminho);
            self::assertSame(404, $this->post($token, $caminho, [])->getStatusCode(), $caminho);
        }
        self::assertSame(404, $this->req('GET', $this->lista() . '/adicionar', $token)->getStatusCode(), 'ações só por POST');
        self::assertSame(404, $this->post($token, $this->lista(), [])->getStatusCode(), 'a lista é só GET');
    }

    public function testListaMostraDominiosEAcoes(): void
    {
        $this->s->dominios->adicionar($this->siteId, 'alias.exemplo.org', Dominio::SERVIR);
        $this->s->dominios->adicionar($this->siteId, 'velho.exemplo.org', Dominio::REDIRECIONAR);
        $r = $this->req('GET', $this->lista(), $this->token('admin'));
        $html = $this->html($r);
        self::assertSame(200, $r->getStatusCode());
        foreach (['www.exemplo.org', 'alias.exemplo.org', 'velho.exemplo.org'] as $h) {
            self::assertStringContainsString('<code>' . $h . '</code>', $html);
        }
        self::assertStringContainsString('Principal', $html);
        self::assertStringContainsString('Tornar redirecionador', $html);
        self::assertStringContainsString('Tornar alias', $html);
        self::assertSame(2, substr_count($html, 'value="www.exemplo.org"') + substr_count($html, 'value="alias.exemplo.org"') - 1, 'o principal não tem botões de ação');
        self::assertStringContainsString("default-src 'none'", (string) $r->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('href="' . $this->lista() . '"', $this->html($this->req('GET', '/_pbl/', $this->token('admin'))), 'botão no painel');
    }

    public function testAdicionarAliasERedirecionador(): void
    {
        $token = $this->token('admin');
        $r = $this->post($token, $this->lista() . '/adicionar', ['host' => '  ALIAS.Exemplo.org ', 'modo' => 'servir']);
        self::assertSame(303, $r->getStatusCode());
        self::assertSame($this->lista() . '?ok=adicionado', $r->headers->get('Location'));
        $this->post($token, $this->lista() . '/adicionar', ['host' => 'velho.exemplo.org', 'modo' => 'redirecionar']);
        self::assertSame(['alias.exemplo.org' => 'servir', 'velho.exemplo.org' => 'redirecionar', 'www.exemplo.org' => 'principal'], $this->dominios());
        self::assertStringContainsString('Domínio adicionado', $this->html($this->req('GET', $this->lista() . '?ok=adicionado', $token)));
        self::assertCount(2, $this->auditoria('dominio.adicionar'));
        // O alias passa a responder de fato (mesmo site) e o redirecionador manda ao principal.
        self::assertSame(301, $this->requisitar('velho.exemplo.org', '/')->getStatusCode());
    }

    private function requisitar(string $host, string $caminho): \Symfony\Component\HttpFoundation\Response
    {
        return (new \Pbl\Aplicacao($this->caminhos))->tratar(\Symfony\Component\HttpFoundation\Request::create('https://' . $host . $caminho));
    }

    public function testAdicionarRecusaEntradaInvalida(): void
    {
        $token = $this->token('admin');
        $this->s->dominios->adicionar($this->siteId, 'ja.exemplo.org');
        $casos = [
            'repetido' => ['www.exemplo.org', 'servir'],
            'repetido de outra caixa' => ['JA.exemplo.org', 'servir'],
            'com esquema' => ['https://x.exemplo.org', 'servir'],
            'com porta' => ['x.exemplo.org:8080', 'servir'],
            'com caminho' => ['x.exemplo.org/a', 'servir'],
            'vazio' => ['', 'servir'],
            'com controle' => ["x\n.exemplo.org", 'servir'],
            'principal não vale' => ['x.exemplo.org', 'principal'],
            'modo desconhecido' => ['x.exemplo.org', 'abc'],
            'sem modo' => ['x.exemplo.org', ''],
        ];
        foreach ($casos as $nome => [$host, $modo]) {
            $r = $this->post($token, $this->lista() . '/adicionar', ['host' => $host, 'modo' => $modo]);
            self::assertSame(422, $r->getStatusCode(), $nome);
            self::assertStringContainsString('role="alert"', $this->html($r), $nome);
        }
        self::assertSame(['ja.exemplo.org' => 'servir', 'www.exemplo.org' => 'principal'], $this->dominios());
        self::assertSame(400, $this->post($token, $this->lista() . '/adicionar', ['host' => ['x'], 'modo' => 'servir'])->getStatusCode());
        self::assertSame(400, $this->post($token, $this->lista() . '/adicionar', ['modo' => 'servir'])->getStatusCode());
    }

    public function testMensagemDeErroEscapaOHost(): void
    {
        $r = $this->post($this->token('admin'), $this->lista() . '/adicionar', ['host' => '"><script>x</script>', 'modo' => 'servir']);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringNotContainsString('<script>x', $this->html($r));
    }

    public function testDefinirPrincipalTrocaOAnteriorParaRedirecionadorOuAlias(): void
    {
        $token = $this->token('admin');
        $this->s->dominios->adicionar($this->siteId, 'novo.exemplo.org', Dominio::SERVIR);
        $r = $this->post($token, $this->lista() . '/principal', ['host' => 'novo.exemplo.org', 'antigo' => 'servir']);
        self::assertSame(303, $r->getStatusCode());
        self::assertSame(['novo.exemplo.org' => 'principal', 'www.exemplo.org' => 'servir'], $this->dominios());
        self::assertSame('novo.exemplo.org', $this->s->dominios->principalDe($this->siteId)?->host, 'sempre um único principal');

        // De volta para o www (que ainda servia), agora o outro passa a redirecionar.
        $this->post($token, $this->lista() . '/principal', ['host' => 'www.exemplo.org', 'antigo' => 'redirecionar']);
        self::assertSame(['novo.exemplo.org' => 'redirecionar', 'www.exemplo.org' => 'principal'], $this->dominios());
        $log = $this->auditoria('dominio.principal');
        self::assertCount(2, $log);
    }

    public function testDefinirPrincipalRecusaCasosInvalidos(): void
    {
        $token = $this->token('admin');
        $this->s->dominios->adicionar($this->siteId, 'alias.exemplo.org', Dominio::SERVIR);
        foreach ([['www.exemplo.org', 'servir'], ['inexistente.exemplo.org', 'servir'], ['alias.exemplo.org', 'principal'], ['alias.exemplo.org', 'lixo'], ['alias.exemplo.org', '']] as [$host, $antigo]) {
            self::assertSame(422, $this->post($token, $this->lista() . '/principal', ['host' => $host, 'antigo' => $antigo])->getStatusCode(), "{$host}/{$antigo}");
        }
        self::assertSame(['alias.exemplo.org' => 'servir', 'www.exemplo.org' => 'principal'], $this->dominios());
    }

    public function testTornarAliasOuRedirecionador(): void
    {
        $token = $this->token('admin');
        $this->s->dominios->adicionar($this->siteId, 'outro.exemplo.org', Dominio::SERVIR);
        $r = $this->post($token, $this->lista() . '/modo', ['host' => 'outro.exemplo.org', 'modo' => 'redirecionar']);
        self::assertSame(303, $r->getStatusCode());
        self::assertSame('redirecionar', $this->dominios()['outro.exemplo.org']);
        self::assertSame(301, $this->requisitar('outro.exemplo.org', '/x')->getStatusCode());
        $this->post($token, $this->lista() . '/modo', ['host' => 'outro.exemplo.org', 'modo' => 'servir']);
        self::assertSame('servir', $this->dominios()['outro.exemplo.org']);
        self::assertCount(2, $this->auditoria('dominio.modo'));
    }

    public function testNaoMudaOModoDoPrincipalNemComModoInvalido(): void
    {
        $token = $this->token('admin');
        $this->s->dominios->adicionar($this->siteId, 'outro.exemplo.org', Dominio::SERVIR);
        foreach ([['www.exemplo.org', 'redirecionar'], ['www.exemplo.org', 'servir'], ['outro.exemplo.org', 'principal'], ['outro.exemplo.org', 'x'], ['nao.existe.org', 'servir']] as [$host, $modo]) {
            self::assertSame(422, $this->post($token, $this->lista() . '/modo', ['host' => $host, 'modo' => $modo])->getStatusCode(), "{$host}/{$modo}");
        }
        self::assertSame(['outro.exemplo.org' => 'servir', 'www.exemplo.org' => 'principal'], $this->dominios());
    }

    public function testRemoverDominio(): void
    {
        $token = $this->token('admin');
        $this->s->dominios->adicionar($this->siteId, 'sobra.exemplo.org', Dominio::SERVIR);
        $r = $this->post($token, $this->lista() . '/remover', ['host' => 'sobra.exemplo.org']);
        self::assertSame(303, $r->getStatusCode());
        self::assertSame(['www.exemplo.org' => 'principal'], $this->dominios());
        self::assertCount(1, $this->auditoria('dominio.remover'));
        self::assertSame(422, $this->post($token, $this->lista() . '/remover', ['host' => 'sobra.exemplo.org'])->getStatusCode(), 'já removido');
    }

    public function testNaoRemoveOPrincipalNemOEnderecoEmUso(): void
    {
        $token = $this->token('admin');
        $r = $this->post($token, $this->lista() . '/remover', ['host' => 'www.exemplo.org']);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('Você está acessando', $this->html($r), 'o endereço em uso é protegido primeiro');
        $this->s->dominios->adicionar($this->siteId, 'alias.exemplo.org', Dominio::SERVIR);
        $this->s->dominios->definirPrincipal($this->siteId, 'alias.exemplo.org', Dominio::SERVIR);
        // www é agora alias e é o endereço em uso: continua protegido; o principal novo nunca se remove.
        self::assertSame(422, $this->post($token, $this->lista() . '/remover', ['host' => 'www.exemplo.org'])->getStatusCode());
        self::assertSame(422, $this->post($token, $this->lista() . '/remover', ['host' => 'alias.exemplo.org'])->getStatusCode());
        self::assertCount(2, $this->dominios());
        self::assertCount(0, $this->auditoria('dominio.remover'));
    }

    public function testDominioDeOutroSiteNaoSeMexePelaTelaDesteSite(): void
    {
        $token = $this->token('admin');
        $outro = $this->s->sites->criar('Outro Site', 'outro-site.org');
        $this->s->dominios->adicionar($outro->id, 'extra.outro-site.org', Dominio::SERVIR);
        foreach (['principal' => ['host' => 'extra.outro-site.org', 'antigo' => 'servir'], 'modo' => ['host' => 'extra.outro-site.org', 'modo' => 'redirecionar'], 'remover' => ['host' => 'extra.outro-site.org']] as $acao => $campos) {
            self::assertSame(422, $this->post($token, $this->lista() . '/' . $acao, $campos)->getStatusCode(), $acao);
        }
        self::assertSame(['extra.outro-site.org' => 'servir', 'outro-site.org' => 'principal'], $this->dominios($outro->id));
        self::assertSame(['www.exemplo.org' => 'principal'], $this->dominios());
        // Cada site tem a sua tela.
        self::assertStringContainsString('extra.outro-site.org', $this->html($this->req('GET', "/_pbl/site/{$outro->id}/dominios", $token)));
        self::assertStringNotContainsString('extra.outro-site.org', $this->html($this->req('GET', $this->lista(), $token)));
    }

    public function testContaDesativadaPerdeAcesso(): void
    {
        $token = $this->token('admin2');
        $this->s->usuarios->definirAtivo($this->ids['admin2'], false);
        self::assertSame(303, $this->req('GET', $this->lista(), $token)->getStatusCode());
    }

    // ---------------------------------------------------------------- modo elevado (fase 5h)

    public function testAdicionarPrincipalERemoverExigemSenhaDeNovoMasModoNao(): void
    {
        $token = $this->tokenSemElevar('admin');
        $r = $this->post($token, $this->lista() . '/adicionar', ['host' => 'novo.exemplo.org', 'modo' => Dominio::SERVIR]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('Confirme sua senha', $this->html($r));
        self::assertArrayNotHasKey('novo.exemplo.org', $this->dominios());

        // "modo" (alias ⇄ redirecionador) não troca o endereço nem derruba acesso: não pede a senha.
        $this->s->dominios->adicionar($this->siteId, 'alias.exemplo.org', Dominio::SERVIR);
        $r2 = $this->post($token, $this->lista() . '/modo', ['host' => 'alias.exemplo.org', 'modo' => Dominio::REDIRECIONAR]);
        self::assertSame(303, $r2->getStatusCode());
        self::assertSame(Dominio::REDIRECIONAR, $this->dominios()['alias.exemplo.org']);
    }

    public function testAdicionarComSenhaCertaFuncionaEAbreOModoElevado(): void
    {
        $token = $this->tokenSemElevar('admin');
        $r = $this->post($token, $this->lista() . '/adicionar', ['host' => 'novo.exemplo.org', 'modo' => Dominio::SERVIR, 'senha' => self::SENHA]);
        self::assertSame(303, $r->getStatusCode());
        self::assertArrayHasKey('novo.exemplo.org', $this->dominios());

        // Já elevado: remover não pede a senha de novo.
        $r2 = $this->post($token, $this->lista() . '/remover', ['host' => 'novo.exemplo.org']);
        self::assertSame(303, $r2->getStatusCode());
        self::assertArrayNotHasKey('novo.exemplo.org', $this->dominios());
    }

    public function testSenhaErradaNaoAdicionaDominioENaoAparecenaAuditoria(): void
    {
        $token = $this->tokenSemElevar('admin');
        $r = $this->post($token, $this->lista() . '/adicionar', ['host' => 'novo.exemplo.org', 'modo' => Dominio::SERVIR, 'senha' => 'senha-errada']);
        self::assertSame(401, $r->getStatusCode());
        self::assertArrayNotHasKey('novo.exemplo.org', $this->dominios());
        self::assertStringNotContainsString('senha-errada', (string) json_encode($this->s->auditoria->recentes(500)));
    }
}

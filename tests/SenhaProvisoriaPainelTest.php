<?php

declare(strict_types=1);

namespace Pbl\Testes;

use PHPUnit\Framework\TestCase;

/** Senha provisória no painel (ADR 0047/0048): guard central de telas e API, tela de troca e expiração das outras sessões. */
final class SenhaProvisoriaPainelTest extends TestCase
{
    use AdminAuxiliar;

    private const NOVA = 'outra senha ainda mais longa 2027';

    private function provisoria(string $login = 'novata'): int
    {
        $id = $this->s->usuarios->criar($login, $login . '@exemplo.org', 'Nova Pessoa', self::SENHA, true, 'teste', true)->id;
        $this->ids[$login] = $id;

        return $id;
    }

    private function marca(int $id): int
    {
        return $this->s->usuarios->porId($id)?->senhaProvisoria === true ? 1 : 0;
    }

    public function testContaNormalNavegaLivre(): void
    {
        self::assertSame(200, $this->req('GET', '/_pbl/', $this->tokenSemElevar('admin'))->getStatusCode());
    }

    public function testLoginProvisorioRedirecionaQualquerTelaParaATroca(): void
    {
        $this->provisoria();
        $t = $this->tokenSemElevar('novata');
        foreach (['/_pbl', '/_pbl/', '/_pbl/mais', '/_pbl/perfil', '/_pbl/perfil/', '/_pbl/contas', '/_pbl/copias', '/_pbl/conteudo', '/_pbl/objeto/' . $this->inicioId, '/_pbl/entrar'] as $c) {
            $r = $this->req('GET', $c, $t);
            self::assertSame(303, $r->getStatusCode(), $c);
            self::assertSame($c === '/_pbl/entrar' ? '/_pbl/' : '/_pbl/trocar-senha', $r->headers->get('Location'), $c);
        }
    }

    public function testPostsDoPainelSaoBarradosSemExecutar(): void
    {
        $id = $this->provisoria();
        $t = $this->tokenSemElevar('novata');
        foreach (['/_pbl/perfil/nome' => ['nome' => 'Invasor'], '/_pbl/perfil/senha' => ['senha_atual' => self::SENHA, 'senha' => self::NOVA, 'senha2' => self::NOVA], '/_pbl/contas/nova' => []] as $c => $campos) {
            $r = $this->post($t, $c, $campos);
            self::assertSame(303, $r->getStatusCode(), $c);
            self::assertSame('/_pbl/trocar-senha', $r->headers->get('Location'), $c);
        }
        self::assertSame('Nova Pessoa', $this->s->usuarios->porId($id)?->nome);
        self::assertSame(1, $this->marca($id));
    }

    public function testApiDoPainelDevolve403ComCodigoEstavel(): void
    {
        $this->provisoria();
        $t = $this->tokenSemElevar('novata');
        foreach ([['GET', '/_pbl/api/acoes?objetos=' . $this->inicioId], ['POST', '/_pbl/api/estado']] as [$m, $c]) {
            $r = $this->req($m, $c, $t);
            self::assertSame(403, $r->getStatusCode(), $c);
            $j = json_decode($this->html($r), true);
            self::assertSame('senha_provisoria', $j['erro'] ?? null);
            self::assertSame('/_pbl/trocar-senha', $j['trocar_senha'] ?? null);
        }
        self::assertSame(401, $this->req('GET', '/_pbl/api/acoes?objetos=1')->getStatusCode(), 'sem sessão segue 401');
    }

    public function testTelaDeTrocaTemRotulosFocoESemMenu(): void
    {
        $this->provisoria();
        $r = $this->req('GET', '/_pbl/trocar-senha', $this->tokenSemElevar('novata'));
        $h = $this->html($r);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('Defina sua nova senha', $h);
        foreach (['Senha atual (provisória)', 'Nova senha', 'Repita a nova senha'] as $rotulo) {
            self::assertStringContainsString($rotulo, $h);
        }
        self::assertStringContainsString('autocomplete="current-password"', $h);
        self::assertStringContainsString('autofocus', $h);
        self::assertStringContainsString('action="/_pbl/sair"', $h);
        self::assertStringNotContainsString('href="/_pbl/mais"', $h, 'sem o menu do painel');
        self::assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
    }

    public function testSairContinuaPermitido(): void
    {
        $this->provisoria();
        $t = $this->tokenSemElevar('novata');
        $r = $this->post($t, '/_pbl/sair', []);
        self::assertSame('/_pbl/entrar', $r->headers->get('Location'));
        self::assertSame('/_pbl/entrar', $this->req('GET', '/_pbl/trocar-senha', $t)->headers->get('Location'));
    }

    /** @return iterable<string, array{array<string, string>, int, string}> */
    public static function erros(): iterable
    {
        yield 'atual errada' => [['senha_atual' => 'senha errada qualquer 1', 'senha' => self::NOVA, 'senha2' => self::NOVA], 401, 'senha'];
        yield 'confirmação diferente' => [['senha_atual' => self::SENHA, 'senha' => self::NOVA, 'senha2' => self::NOVA . 'x'], 422, 'não são iguais'];
        yield 'igual à atual' => [['senha_atual' => self::SENHA, 'senha' => self::SENHA, 'senha2' => self::SENHA], 422, 'diferente da atual'];
        yield 'curta' => [['senha_atual' => self::SENHA, 'senha' => 'curta', 'senha2' => 'curta'], 422, 'pelo menos 10'];
    }

    /**
     * @param array<string, string> $campos
     * @dataProvider erros
     */
    public function testTrocaInvalidaMantemAMarca(array $campos, int $status, string $trecho): void
    {
        $id = $this->provisoria();
        $r = $this->post($this->tokenSemElevar('novata'), '/_pbl/trocar-senha', $campos);
        self::assertSame($status, $r->getStatusCode());
        self::assertStringContainsString($trecho, $this->html($r));
        self::assertStringContainsString('role="alert"', $this->html($r));
        self::assertSame(1, $this->marca($id));
    }

    public function testTrocaSemCsrfERecusada(): void
    {
        $id = $this->provisoria();
        $r = $this->post($this->tokenSemElevar('novata'), '/_pbl/trocar-senha', ['senha_atual' => self::SENHA, 'senha' => self::NOVA, 'senha2' => self::NOVA], false);
        self::assertSame(403, $r->getStatusCode());
        self::assertSame(1, $this->marca($id));
    }

    public function testTrocaRemoveAMarcaMantemASessaoAtualEEncerraAsOutras(): void
    {
        $id = $this->provisoria();
        $a = $this->tokenSemElevar('novata');
        $b = $this->tokenSemElevar('novata');
        $r = $this->post($a, '/_pbl/trocar-senha', ['senha_atual' => self::SENHA, 'senha' => self::NOVA, 'senha2' => self::NOVA]);
        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/_pbl/?ok=senha-nova', $r->headers->get('Location'));
        self::assertSame(0, $this->marca($id));
        self::assertNotNull($this->s->usuarios->autenticar('novata', self::NOVA));
        $inicio = $this->req('GET', '/_pbl/?ok=senha-nova', $a);
        self::assertSame(200, $inicio->getStatusCode());
        self::assertStringContainsString('Senha trocada. Você já pode usar o painel.', $this->html($inicio));
        $fora = $this->req('GET', '/_pbl/', $b);
        self::assertSame(303, $fora->getStatusCode());
        self::assertSame('/_pbl/entrar', $fora->headers->get('Location'));
        self::assertNotEmpty($this->auditoria('conta.senha_provisoria_trocada'));
    }

    public function testQuemNaoTemMarcaNaoUsaATelaDeTroca(): void
    {
        $r = $this->req('GET', '/_pbl/trocar-senha', $this->tokenSemElevar('admin'));
        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/_pbl/', $r->headers->get('Location'));
    }
}

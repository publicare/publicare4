<?php

declare(strict_types=1);

namespace Pbl\Testes\E2E;

use Pbl\Email\ConfigDeEmail;
use Pbl\Email\Transporte;
use Pbl\Testes\AdminAuxiliar;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * E2E da recuperação de acesso (features/recuperacao-de-acesso.feature): a `Aplicacao` inteira, driver de e-mail `arquivo`,
 * sem navegador. Cada teste cobre um cenário Gherkin; regression/recuperacao.test.mjs os roda por título.
 */
final class RecuperacaoDeAcessoPontaAPontaTest extends TestCase
{
    use AdminAuxiliar;

    private const NOVA = 'outra senha ainda mais longa 2027';

    private function ligarEmail(): void
    {
        $this->s->configDeEmail()->salvar(new ConfigDeEmail(Transporte::ARQUIVO, remetente: 'Site <nao-responda@exemplo.org>'));
    }

    /** @return list<string> */
    private function caixa(): array
    {
        $a = glob($this->caminhos->dados() . '/email/caixa/*.eml') ?: [];
        sort($a);

        return array_map(static fn (string $f): string => (string) file_get_contents($f), $a);
    }

    /** @param array<string, mixed> $campos */
    private function publico(string $caminho, array $campos): Response
    {
        return $this->req('POST', $caminho, null, ['_csrf' => $this->s->csrf()->paraEntrar()] + $campos, self::ORIGEM);
    }

    /** Pede a recuperação, processa a fila e devolve o token do último e-mail de link. */
    private function receberLink(string $login = 'editor'): string
    {
        $this->ligarEmail();
        $this->publico('/_pbl/esqueci', ['identificador' => $login]);
        $this->s->recuperacao()->processarFila();

        return $this->ultimoToken();
    }

    private function ultimoToken(): string
    {
        $achou = null;
        foreach ($this->caixa() as $e) {
            if (preg_match('#/_pbl/redefinir-senha/([0-9a-f]{64})#', $e, $m) === 1) {
                $achou = $m[1];
            }
        }
        self::assertNotNull($achou, 'link no e-mail');

        return $achou;
    }

    public function testRespostaDeEsqueciIdenticaParaTodos(): void
    {
        $this->ligarEmail();
        $respostas = [];
        foreach (['editor', 'editor@exemplo.org', 'ninguem', ''] as $id) {
            $r = $this->publico('/_pbl/esqueci', ['identificador' => $id]);
            $respostas[] = [$r->getStatusCode(), $this->html($r), $r->headers->get('Content-Type'), $r->headers->get('Cache-Control')];
        }
        self::assertSame(200, $respostas[0][0]);
        foreach ($respostas as $r) {
            self::assertSame($respostas[0], $r);
        }
        self::assertSame([], $this->caixa());
    }

    public function testEmailTemSoOLinkEOTokenNaoFicaEmClaro(): void
    {
        $token = $this->receberLink();
        $email = implode("\n", $this->caixa());
        self::assertSame(1, preg_match_all('#/_pbl/redefinir-senha/[0-9a-f]{64}#', $email));
        self::assertStringNotContainsString(self::SENHA, $email);
        $hashDaConta = (string) $this->s->conexao()->fetchOne('SELECT senha_hash FROM usuario WHERE login = ?', ['editor']);
        if ($hashDaConta !== '') {
            self::assertStringNotContainsString($hashDaConta, $email);
        }
        self::assertFalse($this->s->conexao()->fetchOne('SELECT 1 FROM token_conta WHERE token_hash = ?', [$token]));
        self::assertSame(1, (int) $this->s->conexao()->fetchOne('SELECT COUNT(*) FROM token_conta WHERE token_hash = ?', [hash('sha256', $token)]));
        foreach ($this->s->auditoria->recentes(200) as $l) {
            self::assertStringNotContainsString($token, json_encode($l, JSON_THROW_ON_ERROR));
        }
    }

    public function testLinkExpiradoNaoRedefine(): void
    {
        $token = $this->receberLink();
        $rota = '/_pbl/redefinir-senha/' . $token;
        self::assertSame(200, $this->req('GET', $rota)->getStatusCode());
        $this->s->conexao()->executeStatement('UPDATE token_conta SET expira_em = ?', [time() - 1]);
        self::assertSame(400, $this->req('GET', $rota)->getStatusCode());
        self::assertSame(400, $this->publico($rota, ['senha' => self::NOVA, 'senha2' => self::NOVA])->getStatusCode());
        self::assertNotNull($this->s->usuarios->autenticar('editor', self::SENHA));
        self::assertNull($this->s->usuarios->autenticar('editor', self::NOVA));
    }

    public function testNovoPedidoInvalidaOLinkAnterior(): void
    {
        $primeiro = $this->receberLink();
        $this->publico('/_pbl/esqueci', ['identificador' => 'editor']);
        $this->s->recuperacao()->processarFila();
        $segundo = $this->ultimoToken();
        self::assertNotSame($primeiro, $segundo);
        self::assertSame(400, $this->req('GET', '/_pbl/redefinir-senha/' . $primeiro)->getStatusCode());
        self::assertSame(400, $this->publico('/_pbl/redefinir-senha/' . $primeiro, ['senha' => self::NOVA, 'senha2' => self::NOVA])->getStatusCode());
        self::assertSame(200, $this->req('GET', '/_pbl/redefinir-senha/' . $segundo)->getStatusCode());
        self::assertSame(200, $this->publico('/_pbl/redefinir-senha/' . $segundo, ['senha' => self::NOVA, 'senha2' => self::NOVA])->getStatusCode());
    }

    public function testEntrarComSenhaNovaEOLinkMorre(): void
    {
        $token = $this->receberLink();
        $rota = '/_pbl/redefinir-senha/' . $token;
        self::assertSame(200, $this->publico($rota, ['senha' => self::NOVA, 'senha2' => self::NOVA])->getStatusCode());

        $velha = $this->publico('/_pbl/entrar', ['login' => 'editor', 'senha' => self::SENHA]);
        self::assertNotSame(303, $velha->getStatusCode(), 'a senha antiga não entra');

        $nova = $this->publico('/_pbl/entrar', ['login' => 'editor', 'senha' => self::NOVA]);
        self::assertSame(303, $nova->getStatusCode(), 'a senha nova entra');
        $cookie = (string) $nova->headers->get('Set-Cookie');
        self::assertStringContainsString('__Host-pbl4=', $cookie);
        self::assertStringContainsStringIgnoringCase('httponly', $cookie);
        self::assertStringContainsStringIgnoringCase('secure', $cookie);
        self::assertStringContainsStringIgnoringCase('samesite=', $cookie);

        self::assertSame(400, $this->req('GET', $rota)->getStatusCode());
        self::assertSame(400, $this->publico($rota, ['senha' => 'mais uma senha longa 2028', 'senha2' => 'mais uma senha longa 2028'])->getStatusCode());
    }

    public function testPedidosEmExcessoNaoGeramEmailsDemais(): void
    {
        $this->ligarEmail();
        $primeira = null;
        for ($i = 0; $i < 10; ++$i) {
            $r = $this->publico('/_pbl/esqueci', ['identificador' => 'editor']);
            $atual = [$r->getStatusCode(), $this->html($r)];
            $primeira ??= $atual;
            self::assertSame($primeira, $atual, "pedido {$i}");
        }
        $this->s->recuperacao()->processarFila();
        $n = count($this->caixa());
        self::assertGreaterThanOrEqual(1, $n);
        self::assertLessThanOrEqual(5, $n, 'no máximo cinco e-mails para a conta na janela');
    }

    public function testTelasPublicasProtegemContraScriptEVazamento(): void
    {
        $this->ligarEmail();
        $token = $this->receberLink();
        foreach (['/_pbl/esqueci', '/_pbl/esqueci-usuario', '/_pbl/redefinir-senha/' . $token] as $c) {
            $r = $this->req('GET', $c);
            self::assertSame(200, $r->getStatusCode(), $c);
            $csp = (string) $r->headers->get('Content-Security-Policy');
            self::assertStringContainsString("script-src 'self'", $csp);
            self::assertStringNotContainsString("'unsafe-inline'", preg_replace('#style-src[^;]*;?#', '', $csp) ?? $csp);
            $h = $this->html($r);
            self::assertDoesNotMatchRegularExpression('#<script(?![^>]*\bsrc=)#i', $h, $c);
            self::assertStringNotContainsString(' style="', $h, $c);
            self::assertDoesNotMatchRegularExpression('# on[a-z]+="#i', $h, $c);
        }
        $r = $this->req('GET', '/_pbl/redefinir-senha/' . $token);
        self::assertSame('no-referrer', $r->headers->get('Referrer-Policy'));
        self::assertMatchesRegularExpression('#no-store|private#', (string) $r->headers->get('Cache-Control'));
    }

    public function testConviteDefineSenhaPorLinkDeUsoUnico(): void
    {
        $this->ligarEmail();
        $this->s->convites()->convidar('bia', 'Bia', 'bia@exemplo.org', false, 'admin');
        self::assertNull($this->s->usuarios->autenticar('bia', self::NOVA), 'sem senha antes de aceitar');
        $email = implode("\n", $this->caixa());
        $rota = $this->ultimoCaminho('#/_pbl/convite/[0-9a-f]{64}#', $email);

        self::assertSame(200, $this->req('GET', $rota)->getStatusCode());
        self::assertSame(422, $this->publico($rota, ['senha' => 'curta', 'senha2' => 'curta'])->getStatusCode());
        self::assertSame(200, $this->req('GET', $rota)->getStatusCode(), 'senha recusada não consome o link');
        $this->publico($rota, ['senha' => self::NOVA, 'senha2' => self::NOVA]);
        self::assertNotNull($this->s->usuarios->autenticar('bia', self::NOVA));
        self::assertSame(400, $this->req('GET', $rota)->getStatusCode(), 'link usado morre');
        self::assertSame(400, $this->publico($rota, ['senha' => 'mais uma senha longa 2028', 'senha2' => 'mais uma senha longa 2028'])->getStatusCode());
        self::assertNull($this->s->usuarios->autenticar('bia', 'mais uma senha longa 2028'));
    }

    private function ultimoCaminho(string $padrao, string $texto): string
    {
        preg_match_all($padrao, $texto, $todos);
        $achou = end($todos[0]);
        self::assertIsString($achou, 'link no e-mail');

        return $achou;
    }
}

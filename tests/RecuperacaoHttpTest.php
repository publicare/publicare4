<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Aplicacao;
use Pbl\Caminhos;
use Pbl\Email\ConfigDeEmail;
use Pbl\Email\Transporte;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Fluxo HTTP completo de "esqueci a senha" e convite com o driver `arquivo` (ADR 0035/0036). */
final class RecuperacaoHttpTest extends TestCase
{
    private const SENHA = 'uma senha bem longa 2026';
    private const NOVA = 'outra senha ainda mais longa 2027';
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Site', 'www.exemplo.org');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $this->s->usuarios->criar('ana', 'ana@exemplo.org', 'Ana', self::SENHA);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function ligarEmail(): void
    {
        $this->s->configDeEmail()->salvar(new ConfigDeEmail(Transporte::ARQUIVO, remetente: 'Site <nao@exemplo.org>'));
    }

    /** @param array<string, string> $campos */
    private function req(string $metodo, string $caminho, array $campos = [], string $ip = '198.51.100.7'): Response
    {
        $campos += $metodo === 'POST' ? ['_csrf' => $this->s->csrf()->paraEntrar()] : [];

        return (new Aplicacao($this->caminhos))->tratar(Request::create('https://www.exemplo.org' . $caminho, $metodo, $campos, [], [], ['REMOTE_ADDR' => $ip]));
    }

    private function processar(): void
    {
        Servicos::doBancoInstalado($this->caminhos)->recuperacao()->processarFila();
    }

    /** @return list<string> */
    private function emails(): array
    {
        $a = glob($this->caminhos->dados() . '/email/caixa/*.eml') ?: [];
        sort($a);

        return array_map(static fn (string $f): string => (string) file_get_contents($f), $a);
    }

    private function tokenDe(string $email): string
    {
        self::assertSame(1, preg_match('#/_pbl/[a-z-]+/([0-9a-f]{64})#', $email, $m));

        return $m[1];
    }

    public function testSemEmailConfiguradoAvisaEMantemAEntrada(): void
    {
        $r = $this->req('GET', '/_pbl/esqueci');
        self::assertStringContainsString('não está ativada', (string) $r->getContent());
        self::assertStringContainsString('/_pbl/esqueci', (string) $this->req('GET', '/_pbl/entrar')->getContent());
    }

    public function testRespostaIgualParaContaExistenteEInexistente(): void
    {
        $this->ligarEmail();
        $a = $this->req('POST', '/_pbl/esqueci', ['identificador' => 'ana']);
        $b = $this->req('POST', '/_pbl/esqueci', ['identificador' => 'ninguem@exemplo.org']);
        $this->processar();

        self::assertSame($a->getStatusCode(), $b->getStatusCode());
        self::assertSame($a->getContent(), $b->getContent());
        self::assertCount(1, $this->emails());
    }

    public function testFluxoCompletoEUsoUnico(): void
    {
        $this->ligarEmail();
        $this->req('POST', '/_pbl/esqueci', ['identificador' => 'ana@exemplo.org']);
        $this->processar();
        $token = $this->tokenDe($this->emails()[0]);

        self::assertSame(200, $this->req('GET', '/_pbl/redefinir-senha/' . $token)->getStatusCode());
        $fraca = $this->req('POST', '/_pbl/redefinir-senha/' . $token, ['senha' => 'curta', 'senha2' => 'curta']);
        self::assertSame(422, $fraca->getStatusCode());
        $naoIgual = $this->req('POST', '/_pbl/redefinir-senha/' . $token, ['senha' => self::NOVA, 'senha2' => 'x']);
        self::assertSame(422, $naoIgual->getStatusCode());
        $ok = $this->req('POST', '/_pbl/redefinir-senha/' . $token, ['senha' => self::NOVA, 'senha2' => self::NOVA]);
        self::assertSame(200, $ok->getStatusCode());
        self::assertStringContainsString('Senha alterada', (string) $ok->getContent());
        self::assertNotNull(Servicos::doBancoInstalado($this->caminhos)->usuarios->autenticar('ana', self::NOVA));
        self::assertStringContainsString('Sua senha foi alterada', implode("\n", $this->emails()));

        self::assertSame(404, $this->req('GET', '/_pbl/redefinir-senha/' . $token)->getStatusCode());
        $reuso = $this->req('POST', '/_pbl/redefinir-senha/' . $token, ['senha' => 'mais uma senha longa 2028', 'senha2' => 'mais uma senha longa 2028']);
        self::assertSame(404, $reuso->getStatusCode() === 422 ? 404 : $reuso->getStatusCode());
        self::assertNull(Servicos::doBancoInstalado($this->caminhos)->usuarios->autenticar('ana', 'mais uma senha longa 2028'));
    }

    public function testPostSemCsrfOuComOrigemErradaERecusado(): void
    {
        $this->ligarEmail();
        $r = (new Aplicacao($this->caminhos))->tratar(Request::create('https://www.exemplo.org/_pbl/esqueci', 'POST', ['identificador' => 'ana', '_csrf' => 'x'], [], [], ['REMOTE_ADDR' => '198.51.100.7']));
        self::assertSame(403, $r->getStatusCode());
        $o = (new Aplicacao($this->caminhos))->tratar(Request::create('https://www.exemplo.org/_pbl/esqueci', 'POST', ['identificador' => 'ana', '_csrf' => $this->s->csrf()->paraEntrar()], [], [], ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_ORIGIN' => 'https://mal.example']));
        self::assertSame(403, $o->getStatusCode());
    }

    public function testTokenMalFormadoOuInexistenteDa404(): void
    {
        self::assertSame(404, $this->req('GET', '/_pbl/redefinir-senha/' . str_repeat('a', 64))->getStatusCode());
        self::assertSame(404, $this->req('GET', '/_pbl/redefinir-senha/curto')->getStatusCode());
    }

    public function testConviteAceitoPorLink(): void
    {
        $this->ligarEmail();
        $this->s->usuarios->porLogin('ana');
        $this->s->convites()->convidar('beto', 'Beto', 'beto@exemplo.org', false, 'teste');
        $token = $this->tokenDe($this->emails()[0]);

        self::assertSame(200, $this->req('GET', '/_pbl/convite/' . $token)->getStatusCode());
        $ok = $this->req('POST', '/_pbl/convite/' . $token, ['senha' => self::NOVA, 'senha2' => self::NOVA]);
        self::assertStringContainsString('Senha definida', (string) $ok->getContent());
        self::assertNotNull(Servicos::doBancoInstalado($this->caminhos)->usuarios->autenticar('beto', self::NOVA));
        self::assertSame(404, $this->req('GET', '/_pbl/convite/' . $token)->getStatusCode());
    }
}

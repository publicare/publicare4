<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Email\CanalSmtp;
use Pbl\Email\CanalSmtpSocket;
use Pbl\Email\ConfigDeEmail;
use Pbl\Email\ConfigDeEmailRepositorio;
use Pbl\Email\FabricaDeTransporte;
use Pbl\Email\FalhaDeEmail;
use Pbl\Email\Mensagem;
use Pbl\Email\Transporte;
use Pbl\Email\TransporteArquivo;
use Pbl\Email\TransporteNulo;
use Pbl\Email\TransporteSmtp;
use PHPUnit\Framework\TestCase;

/** Transporte de e-mail plugável e configuração com segredo cifrado (ADR 0034). */
final class EmailTest extends TestCase
{
    private string $pasta;

    protected function setUp(): void
    {
        $this->pasta = sys_get_temp_dir() . '/pbl-email-' . bin2hex(random_bytes(4));
        mkdir($this->pasta, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->pasta . '/{,*/}*', GLOB_BRACE) ?: [] as $f) {
            is_file($f) && unlink($f);
        }
        @rmdir($this->pasta . '/caixa');
        @rmdir($this->pasta);
    }

    /** Segredo falso montado em tempo de execução (nunca por extenso no código). */
    private function senhaFalsa(): string
    {
        return implode('-', ['fake', 'smtp', bin2hex(random_bytes(6))]);
    }

    public function testMensagemRejeitaQuebraDeLinhaNosCabecalhos(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Mensagem('a@exemplo.org', "Oi\r\nBcc: x@exemplo.org", 'corpo');
    }

    public function testMensagemRejeitaDestinatarioInvalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Mensagem("a@exemplo.org\nBcc: x@y.org", 'Oi', 'corpo');
    }

    public function testMensagemFormataComCrlfEAssuntoCodificado(): void
    {
        $texto = (new Mensagem('a@exemplo.org', 'Olá', "linha1\nlinha2"))->formatar('Site <nao@exemplo.org>');
        self::assertStringContainsString("To: a@exemplo.org\r\n", $texto);
        self::assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Olá') . "?=\r\n", $texto);
        self::assertStringContainsString("linha1\r\nlinha2\r\n", $texto);
    }

    public function testTransporteNuloNaoEnvia(): void
    {
        $this->expectException(\RuntimeException::class);
        (new TransporteNulo())->enviar(new Mensagem('a@exemplo.org', 'x', 'y'));
    }

    public function testTransporteArquivoGravaEmlComPermissaoRestrita(): void
    {
        $t = new TransporteArquivo($this->pasta . '/caixa', 'nao@exemplo.org');
        $t->enviar(new Mensagem('a@exemplo.org', 'Oi', 'corpo do e-mail'));
        $arquivos = glob($this->pasta . '/caixa/*.eml') ?: [];
        self::assertCount(1, $arquivos);
        self::assertSame('0600', substr(sprintf('%o', fileperms($arquivos[0])), -4));
        self::assertStringContainsString('corpo do e-mail', (string) file_get_contents($arquivos[0]));
        self::assertSame(Transporte::ARQUIVO, $t->tipo());
    }

    public function testConfiguracaoGuardaSenhaCifradaEMascaraNaTela(): void
    {
        $repo = new ConfigDeEmailRepositorio($this->pasta . '/email.json', str_repeat('a', 64));
        $senha = $this->senhaFalsa();
        $repo->salvar(new ConfigDeEmail(Transporte::SMTP, 'smtp.exemplo.org', 587, ConfigDeEmail::SEGURANCA_STARTTLS, 'usr', '', 'Site <nao@exemplo.org>'), $senha);

        self::assertStringNotContainsString($senha, (string) file_get_contents($this->pasta . '/email.json'));
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->pasta . '/email.json')), -4));
        $c = $repo->carregar();
        self::assertSame($senha, $c->senha);
        self::assertStringNotContainsString($senha, json_encode($c->paraTela(), JSON_THROW_ON_ERROR));
        self::assertSame('••••••••', $c->paraTela()['senha']);
        // Salvar sem senha nova mantém a que existe.
        $repo->salvar(new ConfigDeEmail(Transporte::SMTP, 'smtp2.exemplo.org', 465, ConfigDeEmail::SEGURANCA_TLS, 'usr', '', 'Site <nao@exemplo.org>'), '');
        self::assertSame($senha, $repo->carregar()->senha);
        // Outro segredo do site não decifra.
        self::assertSame('', (new ConfigDeEmailRepositorio($this->pasta . '/email.json', str_repeat('b', 64)))->carregar()->senha);
    }

    public function testConfiguracaoInvalidaERecusada(): void
    {
        $repo = new ConfigDeEmailRepositorio($this->pasta . '/email.json', str_repeat('a', 64));
        $this->expectException(\InvalidArgumentException::class);
        $repo->salvar(new ConfigDeEmail(Transporte::SMTP, "host\r\nx", 587, 'tls', '', '', 'a@exemplo.org'));
    }

    public function testSemConfiguracaoOTransporteEDesligado(): void
    {
        $repo = new ConfigDeEmailRepositorio($this->pasta . '/nao-existe.json', str_repeat('a', 64));
        self::assertSame(Transporte::DESLIGADO, FabricaDeTransporte::criar($repo->carregar(), $this->pasta . '/caixa')->tipo());
    }

    public function testSmtpConversaComStarttlsAutenticaEFazDotStuffing(): void
    {
        $senha = $this->senhaFalsa();
        $canal = new CanalFalso(["220 oi\r\n", "250 ok\r\n", "220 pronto\r\n", "250 ok\r\n", "235 ok\r\n", "250 ok\r\n", "250 ok\r\n", "354 va\r\n", "250 aceito\r\n"]);
        $t = new TransporteSmtp(static fn (): CanalSmtp => $canal, true, 'usr', $senha, 'Site <nao@exemplo.org>');
        $t->enviar(new Mensagem('a@exemplo.org', 'Oi', "a\n.linha com ponto"));

        $enviado = implode('', $canal->escrito);
        self::assertTrue($canal->tls);
        self::assertTrue($canal->fechado);
        self::assertStringContainsString("STARTTLS\r\n", $enviado);
        self::assertStringContainsString('AUTH PLAIN ' . base64_encode("\0usr\0" . $senha), $enviado);
        self::assertStringContainsString("MAIL FROM:<nao@exemplo.org>\r\n", $enviado);
        self::assertStringContainsString("RCPT TO:<a@exemplo.org>\r\n", $enviado);
        self::assertStringContainsString("\r\n..linha com ponto\r\n", $enviado);
        self::assertStringEndsWith("\r\n.\r\nQUIT\r\n", $enviado);
    }

    public function testSmtpFalhaDeTlsAbortaSemEnviarSenha(): void
    {
        $senha = $this->senhaFalsa();
        $canal = new CanalFalso(["220 oi\r\n", "250 ok\r\n", "220 pronto\r\n"], false);
        $t = new TransporteSmtp(static fn (): CanalSmtp => $canal, true, 'usr', $senha, 'nao@exemplo.org');
        try {
            $t->enviar(new Mensagem('a@exemplo.org', 'Oi', 'x'));
            self::fail('devia falhar');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString($senha, $e->getMessage());
        }
        self::assertStringNotContainsString(base64_encode("\0usr\0" . $senha), implode('', $canal->escrito));
        self::assertTrue($canal->fechado);
    }

    public function testSmtpRecusaDoServidorNaoVazaRespostaNemSenha(): void
    {
        $canal = new CanalFalso(["220 oi\r\n", "250 ok\r\n", "535 senha errada para usr\r\n", "535 no\r\n"]);
        $t = new TransporteSmtp(static fn (): CanalSmtp => $canal, false, 'usr', 'x', 'nao@exemplo.org');
        $this->expectExceptionMessage('Autenticação recusada pelo servidor de e-mail');
        $t->enviar(new Mensagem('a@exemplo.org', 'Oi', 'x'));
    }

    public function testNenhumaSoValeNoLoopback(): void
    {
        foreach (['localhost', '127.0.0.1', '::1', 'LOCALHOST'] as $host) {
            self::assertNull((new ConfigDeEmail(Transporte::SMTP, $host, 25, ConfigDeEmail::SEGURANCA_NENHUMA, '', '', 'Site <nao@exemplo.org>'))->valida(), $host);
        }
        foreach (['smtp.exemplo.org', '10.0.0.5', '127.0.0.1.exemplo.org', 'localhost.exemplo.org'] as $host) {
            self::assertNotNull((new ConfigDeEmail(Transporte::SMTP, $host, 25, ConfigDeEmail::SEGURANCA_NENHUMA, '', '', 'Site <nao@exemplo.org>'))->valida(), $host);
        }
        self::assertNotNull((new ConfigDeEmail(Transporte::SMTP, 'smtp.exemplo.org', 25, 'qualquer', '', '', 'nao@exemplo.org'))->valida());
        self::assertNull((new ConfigDeEmail(Transporte::SMTP, 'smtp.exemplo.org', 587, ConfigDeEmail::SEGURANCA_STARTTLS, 'u', '', 'nao@exemplo.org'))->valida());
    }

    public function testTransporteRecusaSemTlsEmHostExterno(): void
    {
        $canal = new CanalFalso(["220 oi\r\n"]);
        $t = new TransporteSmtp(static fn (): CanalSmtp => $canal, false, '', '', 'nao@exemplo.org', 'pbl4.invalid', true, 'smtp.exemplo.org');
        try {
            $t->enviar(new Mensagem('a@exemplo.org', 'Oi', 'x'));
            self::fail('devia recusar');
        } catch (FalhaDeEmail $e) {
            self::assertSame(FalhaDeEmail::CONFIGURACAO, $e->categoria);
        }
        self::assertSame([], $canal->escrito);
    }

    public function testCanalSocketRecusaSemTlsEmHostExterno(): void
    {
        $this->expectException(FalhaDeEmail::class);
        new CanalSmtpSocket('smtp.exemplo.org', 25, false, 1, true);
    }

    public function testSemAuthQuandoUsuarioVazioNoLoopback(): void
    {
        $canal = new CanalFalso(["220 oi\r\n", "250 ok\r\n", "250 ok\r\n", "250 ok\r\n", "354 va\r\n", "250 aceito\r\n"]);
        $t = new TransporteSmtp(static fn (): CanalSmtp => $canal, false, '', '', 'nao@exemplo.org', 'pbl4.invalid', true, '127.0.0.1');
        $t->enviar(new Mensagem('a@exemplo.org', 'Oi', 'x'));
        $enviado = implode('', $canal->escrito);
        self::assertStringNotContainsString('AUTH', $enviado);
        self::assertStringNotContainsString('STARTTLS', $enviado);
        self::assertFalse($canal->tls);
    }

    public function testEnvioRealParaServidorSmtpFalsoEmLoopbackSemTls(): void
    {
        $codigo = '$s = stream_socket_server("tcp://127.0.0.1:0", $e, $m); echo stream_socket_get_name($s, false), "\n"; fflush(STDOUT);'
            . '$c = stream_socket_accept($s, 10); fwrite($c, "220 falso\r\n"); $d = false;'
            . 'while (($l = fgets($c)) !== false) { $out = $l; if ($d) { if ($l === ".\r\n") { $d = false; fwrite($c, "250 ok\r\n"); } continue; }'
            . 'fwrite(STDERR, $l); if (str_starts_with($l, "EHLO")) fwrite($c, "250 ok\r\n"); elseif (str_starts_with($l, "DATA")) { $d = true; fwrite($c, "354 va\r\n"); }'
            . 'elseif (str_starts_with($l, "QUIT")) { fwrite($c, "221 tchau\r\n"); break; } else fwrite($c, "250 ok\r\n"); }';
        $p = proc_open([PHP_BINARY, '-r', $codigo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($p);
        try {
            $porta = (int) substr((string) strrchr(trim((string) fgets($pipes[1])), ':'), 1);
            self::assertGreaterThan(0, $porta);
            $c = new ConfigDeEmail(Transporte::SMTP, '127.0.0.1', $porta, ConfigDeEmail::SEGURANCA_NENHUMA, '', '', 'Site <nao-responda@site.exemplo.org>');
            FabricaDeTransporte::criar($c, $this->pasta . '/caixa')->enviar(new Mensagem('adm@exemplo.org', 'Teste', 'corpo'));
            $log = (string) stream_get_contents($pipes[2]);
            self::assertStringContainsString('MAIL FROM:<nao-responda@site.exemplo.org>', $log);
            self::assertStringContainsString('RCPT TO:<adm@exemplo.org>', $log);
            self::assertStringNotContainsString('AUTH', $log);
        } finally {
            proc_terminate($p);
            proc_close($p);
        }
    }

    public function testConexaoRecusadaEClassificadaComoRede(): void
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($s);
        $porta = (int) substr((string) strrchr((string) stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        $c = new ConfigDeEmail(Transporte::SMTP, '127.0.0.1', $porta, ConfigDeEmail::SEGURANCA_NENHUMA, '', '', 'nao@exemplo.org');
        try {
            FabricaDeTransporte::criar($c, $this->pasta . '/caixa')->enviar(new Mensagem('a@exemplo.org', 'Oi', 'x'));
            self::fail('devia falhar');
        } catch (FalhaDeEmail $e) {
            self::assertSame(FalhaDeEmail::REDE, $e->categoria);
        }
    }

    public function testVerificacaoDeCertificadoSegueLigada(): void
    {
        $fonte = (string) file_get_contents(__DIR__ . '/../app/src/Email/CanalSmtpSocket.php');
        self::assertStringContainsString("'verify_peer' => true, 'verify_peer_name' => true", $fonte);
        self::assertStringNotContainsString("'verify_peer' => false", $fonte);
        self::assertStringNotContainsString('allow_self_signed', $fonte);
    }
}

/** @internal */
final class CanalFalso implements CanalSmtp
{
    /** @var list<string> */
    public array $escrito = [];
    public bool $tls = false;
    public bool $fechado = false;

    /** @param list<string> $respostas */
    public function __construct(private array $respostas, private readonly bool $tlsOk = true)
    {
    }

    /** @return array{int, string} */
    public function ler(): array
    {
        $r = array_shift($this->respostas) ?? throw new \RuntimeException('sem resposta');

        return [(int) substr($r, 0, 3), $r];
    }

    public function escrever(string $dados): void
    {
        $this->escrito[] = $dados;
    }

    public function ligarTls(): bool
    {
        $this->tls = $this->tlsOk;

        return $this->tlsOk;
    }

    public function fechar(): void
    {
        $this->fechado = true;
    }
}

<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Caminhos;
use Pbl\Console\AgendadosComando;
use Pbl\Console\PublicarComando;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\Site;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Publicação e saída do ar por data (ADR 0012 §10): `Publicador::pendentes`, `aplicarAgendados` e `bin/pbl agendados`. */
final class AgendadosTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;
    private Site $site;
    private Objeto $inicio;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Meu Site', 'www.exemplo.org');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $this->site = $this->s->sites->porHost('www.exemplo.org') ?? self::fail();
        $this->inicio = $this->s->objetos->porId((int) $this->site->objetoRaizId) ?? self::fail();
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function pagina(string $url, ?string $publicacao, ?string $validade, string $status = 'publicado'): Objeto
    {
        $classe = $this->s->classes->porNome('pagina') ?? self::fail();

        return $this->s->objetos->criar($this->site->id, $classe, $this->inicio->id, ucfirst($url), $url, [], $status, ['data_publicacao' => $publicacao, 'data_validade' => $validade]);
    }

    private function noCache(string $url): bool
    {
        return is_file($this->caminhos->pagina($this->site->id, $url));
    }

    private function agora(string $intervalo): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify($intervalo)->format('c');
    }

    /** Muda as datas direto no banco, como se o relógio tivesse andado (sem passar pelo repositório). */
    private function viajar(Objeto $o, ?string $publicacao, ?string $validade): void
    {
        \Pbl\Banco\Conexao::sqlite($this->caminhos->banco())->executeStatement(
            'UPDATE objeto SET data_publicacao = ?, data_validade = ? WHERE id = ?',
            [$publicacao, $validade, $o->id],
        );
    }

    public function testFuturoNaoVaiAoArAntesDaDataEEntraDepois(): void
    {
        $futura = $this->pagina('lancamento', $this->agora('+2 days'), null);
        $this->s->publicador->publicarSite($this->site);
        self::assertFalse($this->noCache('lancamento'), 'data futura: fora do ar');
        self::assertSame(['entram' => [], 'saem' => []], $this->s->publicador->pendentes($this->site), 'nada a fazer ainda');
        self::assertSame(['entraram' => 0, 'sairam' => 0], $this->s->publicador->aplicarAgendados($this->site));
        // A consulta pública também respeita a data.
        self::assertNotContains($futura->id, array_map(static fn (Objeto $o): int => $o->id, $this->s->objetos->publicadosDaSubarvore($this->site->id, $this->inicio->caminho)));

        $this->viajar($futura, $this->agora('-1 minute'), null);
        $pendentes = $this->s->publicador->pendentes($this->site);
        self::assertSame([$futura->id], array_map(static fn (Objeto $o): int => $o->id, $pendentes['entram']));
        self::assertSame(['entraram' => 1, 'sairam' => 0], $this->s->publicador->aplicarAgendados($this->site));
        self::assertTrue($this->noCache('lancamento'));
        self::assertContains($futura->id, array_map(static fn (Objeto $o): int => $o->id, $this->s->objetos->publicadosDaSubarvore($this->site->id, $this->inicio->caminho)));
        // Idempotente: rodar de novo não acha nada.
        self::assertSame(['entraram' => 0, 'sairam' => 0], $this->s->publicador->aplicarAgendados($this->site));
    }

    public function testValidadeVencidaTiraDoArEIdempotente(): void
    {
        $p = $this->pagina('promocao', null, $this->agora('+1 day'));
        $this->s->publicador->publicarSite($this->site);
        self::assertTrue($this->noCache('promocao'));
        self::assertSame(['entraram' => 0, 'sairam' => 0], $this->s->publicador->aplicarAgendados($this->site));

        $this->viajar($p, null, $this->agora('-1 minute'));
        self::assertSame(['entraram' => 0, 'sairam' => 1], $this->s->publicador->aplicarAgendados($this->site));
        self::assertFalse($this->noCache('promocao'));
        self::assertSame(['entraram' => 0, 'sairam' => 0], $this->s->publicador->aplicarAgendados($this->site));
    }

    public function testRascunhoEApagadoNaoSaoPendencias(): void
    {
        $this->pagina('rascunho', null, null, 'privado');
        $this->pagina('lixo', null, null, 'apagado');
        $this->s->publicador->publicarSite($this->site);
        self::assertSame(['entram' => [], 'saem' => []], $this->s->publicador->pendentes($this->site));
    }

    public function testPaginaQueEntraAtualizaAListaDoPai(): void
    {
        // Um molde que lista os filhos: a página inicial precisa ser regravada quando o filho entra.
        $this->pagina('novidade', $this->agora('-1 minute'), null);
        $this->s->publicador->publicarSite($this->site);
        self::assertTrue($this->noCache('novidade'));
        $futura = $this->pagina('outra', $this->agora('+1 day'), null);
        $this->s->publicador->publicarSite($this->site);
        $this->viajar($futura, $this->agora('-1 minute'), null);
        $this->s->publicador->aplicarAgendados($this->site);
        self::assertTrue($this->noCache('outra'));
        self::assertTrue($this->noCache(''), 'a página inicial continua no ar');
    }

    public function testComandoAplicaSimulaEEhIdempotente(): void
    {
        $futura = $this->pagina('cron', $this->agora('+1 day'), null);
        (new CommandTester(new PublicarComando($this->caminhos)))->execute([]);
        $t = new CommandTester(new AgendadosComando($this->caminhos));

        self::assertSame(0, $t->execute([]));
        self::assertSame('', trim($t->getDisplay()), 'sem nada a fazer o comando fica em silêncio (bom para cron)');

        $this->viajar($futura, $this->agora('-1 minute'), null);
        self::assertSame(0, $t->execute(['--simular' => true]));
        self::assertStringContainsString('entrariam 1, sairiam 0', $t->getDisplay());
        self::assertFalse($this->noCache('cron'), 'simular não publica');

        self::assertSame(0, $t->execute([]));
        self::assertStringContainsString('1 entrou(aram) no ar', $t->getDisplay());
        self::assertTrue($this->noCache('cron'));

        self::assertSame(0, $t->execute([]));
        self::assertSame('', trim($t->getDisplay()), 'segunda rodada: nada');
    }

    public function testPublicarComandoTambemAplicaAsTransicoes(): void
    {
        $futura = $this->pagina('pelo-publicar', $this->agora('+1 day'), null);
        $t = new CommandTester(new PublicarComando($this->caminhos));
        $t->execute([]);
        self::assertFalse($this->noCache('pelo-publicar'));
        $this->viajar($futura, $this->agora('-1 minute'), null);
        $t->execute([]);
        self::assertTrue($this->noCache('pelo-publicar'));
    }
}

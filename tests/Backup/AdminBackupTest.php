<?php

declare(strict_types=1);

namespace Pbl\Testes\Backup;

use Pbl\Backup\ConfigDeBackup;
use Pbl\Testes\AdminAuxiliar;
use PHPUnit\Framework\TestCase;

/** Tela "Cópias de segurança" (/_pbl/copias) de ponta a ponta. */
final class AdminBackupTest extends TestCase
{
    use AdminAuxiliar;

    private const TELA = '/_pbl/copias';

    /** @return list<string> */
    private function zips(): array
    {
        return array_map('basename', glob($this->caminhos->dados() . '/backups/*.zip') ?: []);
    }

    private function criarCopia(string $token): string
    {
        $r = $this->post($token, self::TELA . '/criar', []);
        self::assertSame(303, $r->getStatusCode(), $this->html($r));
        $z = $this->zips();
        self::assertNotEmpty($z);

        return $z[0];
    }

    public function testSemLoginEQuemNaoEAdministradorNaoAcessa(): void
    {
        self::assertSame(303, $this->req('GET', self::TELA)->getStatusCode());
        self::assertSame(303, $this->req('POST', self::TELA . '/criar', null, [], self::ORIGEM)->getStatusCode());
        foreach (['editor', 'leitor'] as $login) {
            $t = $this->token($login);
            self::assertSame(403, $this->req('GET', self::TELA, $t)->getStatusCode());
            self::assertSame(403, $this->post($t, self::TELA . '/criar', [])->getStatusCode());
        }
        self::assertSame([], $this->zips());
        self::assertNotEmpty($this->auditoria('backup.negado'));
    }

    public function testPostSemCsrfOuSemOrigemERecusado(): void
    {
        $t = $this->token('admin');
        self::assertSame(403, $this->post($t, self::TELA . '/criar', [], false)->getStatusCode());
        self::assertSame(403, $this->req('POST', self::TELA . '/criar', $t, ['_csrf' => $this->csrf($t)], ['HTTP_ORIGIN' => 'https://malicioso.example'])->getStatusCode());
        self::assertSame([], $this->zips());
    }

    public function testTelaVaziaMostraEstadoInicialEMenu(): void
    {
        $h = $this->html($this->req('GET', self::TELA, $this->token('admin')));
        self::assertStringContainsString('Nenhuma cópia', $h);
        self::assertStringContainsString('desligada', $h);
        self::assertStringContainsString('livre:', $h);
        // O menu por tarefa tem 5 itens; Cópias é alcançada por "Mais".
        self::assertStringContainsString('href="/_pbl/copias"', $this->html($this->req('GET', '/_pbl/mais', $this->token('admin'))));
        self::assertStringContainsString('/_pbl/assets/pbl-backup.js?v=', $h);
        self::assertFileExists(__DIR__ . '/../../public/_pbl/assets/pbl-backup.js');
        self::assertStringNotContainsString('<script>', $h);
    }

    public function testSemSenhaNaoCriaEComSenhaCriaEListaComIntegridade(): void
    {
        $t = $this->tokenSemElevar('admin');
        $r = $this->post($t, self::TELA . '/criar', []);
        self::assertSame(422, $r->getStatusCode());
        self::assertSame([], $this->zips());
        $r = $this->post($t, self::TELA . '/criar', ['senha' => self::SENHA]);
        self::assertSame(303, $r->getStatusCode());
        self::assertCount(1, $this->zips());
        $h = $this->html($this->req('GET', self::TELA, $t));
        self::assertStringContainsString('Manual', $h);
        self::assertStringContainsString('íntegra', $h);
        self::assertStringContainsString('Restaurar', $h);
        self::assertNotEmpty($this->auditoria('backup.criar'));
    }

    public function testSalvarConfiguracaoLigaOAgendamentoEValida(): void
    {
        $t = $this->token('admin');
        $ok = ['ativo' => '1', 'frequencia' => 'semanal', 'hora' => '04:30', 'dias' => ['1', '5'], 'reter_ultimas' => '3', 'reter_dias' => '10', 'pasta' => ''];
        self::assertSame(303, $this->post($t, self::TELA . '/config', $ok)->getStatusCode());
        $c = ConfigDeBackup::carregar($this->caminhos);
        self::assertTrue($c->ativo);
        self::assertSame('semanal', $c->frequencia);
        self::assertSame('04:30', $c->hora);
        self::assertSame([1, 5], $c->dias);
        self::assertSame(3, $c->reterUltimas);
        self::assertSame('0600', substr(sprintf('%o', fileperms(ConfigDeBackup::arquivo($this->caminhos))), -4));

        self::assertSame(422, $this->post($t, self::TELA . '/config', ['hora' => '99:99'] + $ok)->getStatusCode());
        self::assertSame(422, $this->post($t, self::TELA . '/config', ['reter_ultimas' => 'x'] + $ok)->getStatusCode());
        self::assertSame(422, $this->post($t, self::TELA . '/config', ['pasta' => '../fora'] + $ok)->getStatusCode());
        self::assertSame('04:30', ConfigDeBackup::carregar($this->caminhos)->hora);
        $h = $this->html($this->req('GET', self::TELA, $t));
        self::assertStringContainsString('ligada', $h);
    }

    public function testBaixarEntregaOPacoteSoPorPostEBloqueiaNomeRuim(): void
    {
        $t = $this->token('admin');
        $nome = $this->criarCopia($t);
        self::assertSame(404, $this->req('GET', self::TELA . '/baixar', $t)->getStatusCode());
        $r = $this->post($t, self::TELA . '/baixar', ['arquivo' => $nome]);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('attachment; filename="' . $nome . '"', (string) $r->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        ob_start();
        $r->sendContent();
        $corpo = (string) ob_get_clean();
        self::assertSame("PK", substr($corpo, 0, 2));
        self::assertSame(404, $this->post($t, self::TELA . '/baixar', ['arquivo' => '../../dados/pbl4.sqlite'])->getStatusCode());
    }

    public function testApagarExigeConfirmacaoENaoApagaOQueNaoExiste(): void
    {
        $t = $this->token('admin');
        $nome = $this->criarCopia($t);
        self::assertSame(422, $this->post($t, self::TELA . '/apagar', ['arquivo' => $nome])->getStatusCode());
        self::assertCount(1, $this->zips());
        self::assertSame(422, $this->post($t, self::TELA . '/apagar', ['arquivo' => 'nao-existe.zip', 'confirmar' => '1'])->getStatusCode());
        self::assertSame(303, $this->post($t, self::TELA . '/apagar', ['arquivo' => $nome, 'confirmar' => '1'])->getStatusCode());
        self::assertSame([], $this->zips());
        self::assertNotEmpty($this->auditoria('backup.apagar'));
    }

    public function testNaoApagaCopiaDoAtualizador(): void
    {
        $t = $this->token('admin');
        @mkdir($this->caminhos->dados() . '/backups', 0750, true);
        file_put_contents($this->caminhos->dados() . '/backups/pbl4-0.1.0.sqlite', 'x');
        $r = $this->post($t, self::TELA . '/apagar', ['arquivo' => 'pbl4-0.1.0.sqlite', 'confirmar' => '1']);
        self::assertSame(422, $r->getStatusCode());
        self::assertFileExists($this->caminhos->dados() . '/backups/pbl4-0.1.0.sqlite');
    }

    public function testLimparAplicaARetencao(): void
    {
        $t = $this->token('admin');
        $this->post($t, self::TELA . '/config', ['ativo' => '', 'frequencia' => 'diaria', 'hora' => '03:00', 'reter_ultimas' => '1', 'reter_dias' => '0', 'pasta' => '']);
        $a = $this->criarCopia($t);
        $pasta = $this->caminhos->dados() . '/backups';
        // segunda cópia, com nome e data diferentes
        $b = 'pbl4-20990101-000000-manual-v0.zip';
        copy($pasta . '/' . $a, $pasta . '/' . $b);
        copy($pasta . '/' . $a . '.sha256', $pasta . '/' . $b . '.sha256');
        $r = $this->post($t, self::TELA . '/limpar', []);
        self::assertSame(303, $r->getStatusCode());
        self::assertLessThanOrEqual(2, count($this->zips()));
        self::assertNotEmpty($this->auditoria('backup.limpar'));
    }

    public function testRestaurarExigeConfirmacaoESenhaERestaura(): void
    {
        $t = $this->tokenSemElevar('admin');
        $this->post($t, self::TELA . '/criar', ['senha' => self::SENHA]);
        $nome = $this->zips()[0];
        $h = $this->html($this->req('GET', self::TELA . '/restaurar?arquivo=' . rawurlencode($nome), $t));
        self::assertStringContainsString('será perdido', $h);
        self::assertStringContainsString('RESTAURAR', $h);
        self::assertSame(404, $this->req('GET', self::TELA . '/restaurar?arquivo=x.zip', $t)->getStatusCode());

        $base = ['arquivo' => $nome, 'senha' => self::SENHA];
        self::assertSame(422, $this->post($t, self::TELA . '/restaurar', $base)->getStatusCode());
        self::assertSame(422, $this->post($t, self::TELA . '/restaurar', $base + ['confirmar' => '1', 'palavra' => 'restaurar'])->getStatusCode());
        self::assertSame(401, $this->post($t, self::TELA . '/restaurar', ['senha' => 'errada errada errada'] + $base + ['confirmar' => '1', 'palavra' => 'RESTAURAR'])->getStatusCode());
        self::assertSame([], $this->auditoria('backup.restaurar'));

        $r = $this->post($t, self::TELA . '/restaurar', $base + ['confirmar' => '1', 'palavra' => 'RESTAURAR']);
        self::assertSame(303, $r->getStatusCode(), $this->html($r));
        $this->s = \Pbl\Servicos::doBancoInstalado($this->caminhos);
        self::assertNotEmpty($this->auditoria('backup.restaurar'));
        $tipos = array_filter($this->zips(), static fn (string $z): bool => str_contains($z, 'pre-restauracao'));
        self::assertCount(1, $tipos);
    }

    public function testNaoVazaSegredoNaTela(): void
    {
        $t = $this->token('admin');
        $this->criarCopia($t);
        $h = $this->html($this->req('GET', self::TELA, $t));
        self::assertStringNotContainsString($this->raiz, $h);
    }
}

<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Atualizacao\ErroDeAtualizacao;
use Pbl\Atualizacao\PedidoAgendado;
use Pbl\Atualizacao\PedidoDeAtualizacao;
use Pbl\Caminhos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** O pedido agendado em `dados/atualizacao/pedido.json`: esquema fechado, validade, repetido, cancelamento e adulteração. */
final class PedidoDeAtualizacaoTest extends TestCase
{
    private string $raiz;
    private Caminhos $caminhos;
    private int $agora = 1_800_000_000;

    protected function setUp(): void
    {
        $this->raiz = Ajuda::pastaTemporaria();
        $this->caminhos = new Caminhos($this->raiz);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    private function repo(): PedidoDeAtualizacao
    {
        return new PedidoDeAtualizacao($this->caminhos, fn (): int => $this->agora);
    }

    /** @return array<string, mixed> */
    private function valido(): array
    {
        return [
            'tipo' => 'atualizar', 'versao_alvo' => '0.2.0', 'por_id' => 7, 'por_nome' => 'Ana', 'em' => $this->agora,
            'valido_ate' => $this->agora + 1800, 'nonce' => str_repeat('ab', 16), 'estado' => 'aguardando', 'iniciada_em' => 0,
        ];
    }

    /** @param array<string, mixed> $d */
    private function gravarBruto(array|string $d): void
    {
        mkdir($this->raiz . '/dados/atualizacao', 0775, true);
        file_put_contents(PedidoDeAtualizacao::arquivo($this->caminhos), is_string($d) ? $d : json_encode($d));
    }

    public function testAgendaGravaComNonceValidadeEPermissaoRestritiva(): void
    {
        $p = $this->repo()->agendar('atualizar', '0.2.0', 7, 'Ana');

        self::assertSame('atualizar', $p->tipo);
        self::assertSame('0.2.0', $p->versaoAlvo);
        self::assertSame($this->agora + 1800, $p->validoAte);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $p->nonce);
        self::assertSame(PedidoAgendado::AGUARDANDO, $p->estado);
        $arquivo = PedidoDeAtualizacao::arquivo($this->caminhos);
        self::assertSame('0600', substr(sprintf('%o', fileperms($arquivo)), -4));
        self::assertSame('0750', substr(sprintf('%o', fileperms(dirname($arquivo))), -4));
        self::assertEquals($p, $this->repo()->ler());
        self::assertSame([], glob(dirname($arquivo) . '/*.tmp'), 'nenhum temporário sobra');
        self::assertStringNotContainsString('senha', (string) file_get_contents($arquivo));
    }

    public function testPedidoRepetidoOuEmAndamentoERecusadoComMensagemClara(): void
    {
        $r = $this->repo();
        $p = $r->agendar('atualizar', '0.2.0', 7, 'Ana');
        try {
            $r->agendar('atualizar', '0.2.0', 8, 'Beto');
            self::fail('deveria recusar o repetido');
        } catch (ErroDeAtualizacao $e) {
            self::assertStringContainsString('Já existe uma atualização agendada', $e->getMessage());
        }
        $r->iniciar($p);
        try {
            $r->agendar('reverter', '0.1.0', 8, 'Beto');
            self::fail('deveria recusar o em andamento');
        } catch (ErroDeAtualizacao $e) {
            self::assertStringContainsString('já está em andamento', $e->getMessage());
        }
    }

    public function testPedidoVencidoPodeSerSubstituido(): void
    {
        $r = $this->repo();
        $r->agendar('atualizar', '0.2.0', 7, 'Ana');
        $this->agora += 1800;

        $novo = $r->agendar('atualizar', '0.3.0', 7, 'Ana');

        self::assertSame('0.3.0', $novo->versaoAlvo);
    }

    public function testCancelaSoEnquantoNaoComecou(): void
    {
        $r = $this->repo();
        $p = $r->agendar('atualizar', '0.2.0', 7, 'Ana');
        $r->cancelar();
        self::assertFalse($r->existe());

        $p = $r->agendar('atualizar', '0.2.0', 7, 'Ana');
        $r->iniciar($p);
        $this->expectException(ErroDeAtualizacao::class);
        $this->expectExceptionMessage('já começou');
        $r->cancelar();
    }

    public function testCancelarSemPedidoDizIsso(): void
    {
        $this->expectException(ErroDeAtualizacao::class);
        $this->expectExceptionMessage('Não há atualização agendada');
        $this->repo()->cancelar();
    }

    public function testIniciarSoValeParaOMesmoPedidoAindaAguardandoEDentroDaValidade(): void
    {
        $r = $this->repo();
        $visto = $r->agendar('atualizar', '0.2.0', 7, 'Ana');

        // cancelado e pedido outro (nonce novo) entre a leitura e o início: o antigo não pode começar
        $r->cancelar();
        $outro = $r->agendar('atualizar', '0.2.0', 7, 'Ana');
        self::assertNull($r->iniciar($visto), 'nonce diferente');

        $ok = $r->iniciar($outro);
        self::assertNotNull($ok);
        self::assertTrue($ok->emAndamento());
        self::assertSame($this->agora, $ok->iniciadaEm);
        self::assertNull($r->iniciar($outro), 'já em andamento');
    }

    public function testIniciarVencidoERecusado(): void
    {
        $r = $this->repo();
        $p = $r->agendar('atualizar', '0.2.0', 7, 'Ana');
        $this->agora += 1801;

        self::assertNull($r->iniciar($p));
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function adulteracoes(): array
    {
        return [
            'nonce curto' => ['nonce', 'abc'],
            'nonce não hexadecimal' => ['nonce', str_repeat('zz', 16)],
            'nonce em maiúsculas' => ['nonce', str_repeat('AB', 16)],
            'nonce de outro tipo' => ['nonce', 12345],
            'tipo fora do conjunto' => ['tipo', 'apagar'],
            'tipo com comando' => ['tipo', 'atualizar; rm -rf /'],
            'versão inválida' => ['versao_alvo', '0.2.0; id'],
            'versão de outro tipo' => ['versao_alvo', 2],
            'estado desconhecido' => ['estado', 'concluido'],
            'validade além do limite' => ['valido_ate', 1_800_000_000 + 99999],
            'validade antes do pedido' => ['valido_ate', 1_799_999_999],
            'id de outro tipo' => ['por_id', '7'],
            'nome enorme' => ['por_nome', str_repeat('x', 101)],
        ];
    }

    #[DataProvider('adulteracoes')]
    public function testPedidoAdulteradoEhRecusado(string $campo, mixed $valor): void
    {
        $d = $this->valido();
        $d[$campo] = $valor;
        $this->gravarBruto($d);

        self::assertTrue($this->repo()->existe());
        self::assertNull($this->repo()->ler());
    }

    public function testCampoExtraFaltandoCorrompidoOuGrandeDemaisEhRecusado(): void
    {
        $extra = $this->valido() + ['comando' => 'rm -rf /'];
        $this->gravarBruto($extra);
        self::assertNull($this->repo()->ler(), 'campo extra');

        $falta = $this->valido();
        unset($falta['nonce']);
        file_put_contents(PedidoDeAtualizacao::arquivo($this->caminhos), json_encode($falta));
        self::assertNull($this->repo()->ler(), 'sem nonce');

        file_put_contents(PedidoDeAtualizacao::arquivo($this->caminhos), '{"tipo": ');
        self::assertNull($this->repo()->ler(), 'JSON corrompido');

        file_put_contents(PedidoDeAtualizacao::arquivo($this->caminhos), json_encode($this->valido()) . str_repeat(' ', PedidoDeAtualizacao::LIMITE));
        self::assertNull($this->repo()->ler(), 'maior que o limite');

        file_put_contents(PedidoDeAtualizacao::arquivo($this->caminhos), json_encode($this->valido()));
        self::assertNotNull($this->repo()->ler(), 'o válido passa');
    }

    public function testNaoLeLinkSimbolico(): void
    {
        mkdir($this->raiz . '/dados/atualizacao', 0775, true);
        file_put_contents($this->raiz . '/alvo.json', json_encode($this->valido()));
        symlink($this->raiz . '/alvo.json', PedidoDeAtualizacao::arquivo($this->caminhos));

        self::assertNull($this->repo()->ler());
    }

    public function testAgendarRecusaTipoOuVersaoInvalidos(): void
    {
        $this->expectException(ErroDeAtualizacao::class);
        $this->repo()->agendar('formatar', '0.2.0', 1, 'x');
    }
}

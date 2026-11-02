<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Admin\TelasDeAtualizacao;
use Pbl\Atualizacao\Assinatura;
use Pbl\Atualizacao\Baixador;
use Pbl\Atualizacao\BaixadorLocal;
use Pbl\Atualizacao\CopiaDeBanco;
use Pbl\Atualizacao\Empacotador;
use Pbl\Atualizacao\ErroDeAtualizacao;
use Pbl\Atualizacao\EstadoDoPainel;
use Pbl\Atualizacao\Lancador;
use Pbl\Atualizacao\LancadorDeProcesso;
use Pbl\Atualizacao\RegistroDaAtualizacao;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Baixador local que conta as consultas de rede. */
final class BaixadorContador implements Baixador
{
    public int $consultas = 0;
    private readonly BaixadorLocal $local;

    public function __construct()
    {
        $this->local = new BaixadorLocal();
    }

    public function obter(string $origem, int $maximoBytes): string
    {
        if (!str_ends_with($origem, '.sig')) {
            $this->consultas++;
        }

        return $this->local->obter($origem, $maximoBytes);
    }

    public function copiar(string $origem, string $arquivoDestino, int $maximoBytes): void
    {
        $this->local->copiar($origem, $arquivoDestino, $maximoBytes);
    }

    public function resolver(string $base, string $relativo): string
    {
        return $this->local->resolver($base, $relativo);
    }
}

/** Lançador de mentira: só registra que foi chamado (e pode falhar). */
final class LancadorFalso implements Lancador
{
    public int $chamadas = 0;
    public bool $falhar = false;
    public string $saida = '';
    public string $codigo = '';
    /** @var list<string> */
    public array $comandos = [];

    public function iniciar(string $arquivoDeSaida, string $arquivoDeCodigo, string $comando = 'atualizar'): void
    {
        if ($this->falhar) {
            throw new ErroDeAtualizacao('Não achei o PHP de linha de comando.');
        }
        $this->chamadas++;
        $this->saida = $arquivoDeSaida;
        $this->codigo = $arquivoDeCodigo;
        $this->comandos[] = $comando;
    }
}

/** Tela "Atualização" do painel (fase 4c): permissão, CSRF, origem, senha, trava, cache e estados. */
final class AdminAtualizacaoTest extends TestCase
{
    use AdminAuxiliar;

    private const VERSAO = '0.1.0';
    /** @var array{publica: string, privada: string} */
    private array $par;
    /** @var list<string> */
    private array $limpar = [];
    private BaixadorContador $baixador;
    private LancadorFalso $lancador;
    private int $agora = 1_800_000_000;
    private string $manifesto = '';

    /** @param array<string, mixed> $opcoes */
    private function preparar(string $nova = '0.2.0', bool $configurar = true, array $opcoes = ['resumo' => 'Correções e novidades', 'seguranca' => true]): void
    {
        $this->par = Assinatura::gerarPar();
        $this->baixador = new BaixadorContador();
        $this->lancador = new LancadorFalso();
        $origem = Ajuda::raizDeProjeto();
        $saida = Ajuda::raizDeProjeto();
        $this->limpar = [$origem, $saida];
        foreach (['app/src', 'vendor', 'bin'] as $p) {
            mkdir("$origem/$p", 0775, true);
        }
        file_put_contents("$origem/app/src/Versao.php", "<?php\nfinal class Versao { public const ATUAL = '{$nova}'; }\n");
        file_put_contents("$origem/vendor/autoload.php", '<?php');
        file_put_contents("$origem/bin/pbl", '#!/usr/bin/env php');
        file_put_contents("$origem/composer.json", '{"require":{"php":">=8.2"}}');
        file_put_contents("$origem/composer.lock", '{}');
        file_put_contents("$origem/LICENSE", 'MIT');
        $this->manifesto = (new Empacotador())->empacotar($origem, $saida, $this->par['privada'], 'estavel', $opcoes)['manifesto'];
        if ($configurar) {
            $this->configurar(['url' => $this->manifesto, 'canal' => 'estavel']);
        }
    }

    /** @param array<string, mixed> $dados */
    private function configurar(array $dados): void
    {
        $this->caminhos->garantirDados();
        file_put_contents($this->caminhos->config() . '/atualizacao.json', json_encode($dados));
    }

    protected function tearDown(): void
    {
        foreach ($this->limpar as $p) {
            Ajuda::apagar($p);
        }
        Ajuda::apagar($this->raiz);
    }

    /**
     * @param list<string>|null $chaves
     * @param array<string, mixed> $campos
     * @param array<string, string> $cab
     */
    private function tela(string $login, string $metodo, string $caminho, array $campos = [], ?array $chaves = null, bool $comCsrf = true, array $cab = self::ORIGEM): Response
    {
        $token = $this->token($login);
        $sessao = $this->s->sessoes()->validar($token);
        self::assertNotNull($sessao);
        $rota = TelasDeAtualizacao::rota('/_pbl', $caminho, $metodo);
        self::assertNotNull($rota, $caminho);
        $campos = ($metodo === 'POST' && $comCsrf ? ['_csrf' => $this->csrf($token)] : []) + $campos;
        $r = Request::create('https://www.exemplo.org' . $caminho, $metodo, $campos, [], [], ['REMOTE_ADDR' => '198.51.100.7'] + $cab);
        $rede = new \Pbl\Acesso\Rede($this->caminhos);
        $t = new TelasDeAtualizacao($this->s, $rede, $this->s->csrf(), $this->baixador, $chaves ?? [$this->par['publica']], self::VERSAO, $this->lancador, fn (): int => $this->agora, '8.3.0');

        return $t->tratar($sessao, $rota, $r);
    }

    /** @return array<string, mixed> */
    private function aplicarCampos(string $versao = '0.2.0', string $senha = self::SENHA): array
    {
        return ['versao' => $versao, 'senha' => $senha, 'confirmar' => '1'];
    }

    private function estado(): EstadoDoPainel
    {
        return new EstadoDoPainel($this->caminhos, fn (): int => $this->agora);
    }

    /** @return list<string> arquivos da instalação fora de dados/ */
    private function foraDeDados(): array
    {
        $r = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->raiz, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($this->raiz) + 1);
            if (!str_starts_with($rel, 'dados/')) {
                $r[] = $rel . ':' . md5_file($f->getPathname());
            }
        }
        sort($r);

        return $r;
    }

    /**
     * Fabrica o estado que uma atualização concluída deixa (`dados/atualizacao/anterior/` + `aplicada.json` + cópia do banco),
     * sem rodar uma atualização real: o suficiente para o `Reversor` dizer que dá para voltar. A versão restaurada ("anterior")
     * só tem `app`, o bastante para `Reversor::avaliar` conferir o código guardado.
     */
    private function fabricarAtualizacaoConcluida(string $de = '0.1.0', string $para = self::VERSAO, int $em = 0): void
    {
        $this->caminhos->garantirDados();
        $pasta = $this->caminhos->dados() . '/atualizacao';
        mkdir($pasta . '/anterior/app/src', 0775, true);
        file_put_contents($pasta . '/anterior/app/src/Versao.php', "<?php\nfinal class Versao { public const ATUAL = '{$de}'; }\n");
        $backups = $this->caminhos->dados() . '/backups';
        mkdir($backups, 0775, true);
        $backup = $backups . '/pbl4-' . $de . '-antes-de-' . $para . '.sqlite';
        file_put_contents($backup, 'BANCO-ANTERIOR-A-' . $para);
        RegistroDaAtualizacao::gravar($this->caminhos, [
            'de' => $de, 'para' => $para, 'em' => $em === 0 ? $this->agora - 100 : $em,
            'backup' => basename($backup), 'backup_sha256' => (string) hash_file('sha256', $backup),
            'schema_antes' => null, 'schema_depois' => CopiaDeBanco::versaoDoEsquema($this->caminhos->banco()),
            'itens' => ['app'], 'sem_anterior' => [],
            'revertida' => false, 'revertida_em' => 0,
        ]);
    }

    // ------------------------------------------------------------------ acesso

    public function testSemLoginVaiParaEntrarEOPostNaoFazNada(): void
    {
        $this->preparar();
        $r = $this->req('GET', '/_pbl/atualizacao');
        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/_pbl/entrar', $r->headers->get('Location'));
        foreach (['verificar', 'aplicar', 'reverter'] as $a) {
            self::assertSame(303, $this->req('POST', "/_pbl/atualizacao/{$a}", null, $this->aplicarCampos(), self::ORIGEM)->getStatusCode());
        }
        self::assertSame(0, $this->lancador->chamadas);
        self::assertDirectoryDoesNotExist($this->estado()->pasta());
    }

    public function testQuemNaoEAdministradorNaoVeNemAplicaEATentativaFicaNaAuditoria(): void
    {
        $this->preparar();
        foreach (['editor', 'leitor'] as $login) {
            self::assertSame(403, $this->req('GET', '/_pbl/atualizacao', $this->token($login))->getStatusCode(), $login);
            self::assertSame(403, $this->post($this->token($login), '/_pbl/atualizacao/aplicar', $this->aplicarCampos())->getStatusCode(), $login);
            self::assertSame(403, $this->post($this->token($login), '/_pbl/atualizacao/verificar', [])->getStatusCode(), $login);
            self::assertStringNotContainsString('/_pbl/atualizacao', $this->html($this->req('GET', '/_pbl/', $this->token($login))), 'sem link na barra');
        }
        self::assertSame(0, $this->lancador->chamadas);
        self::assertCount(4, $this->auditoria('atualizacao.negado'));
        self::assertSame(0, $this->baixador->consultas);
        self::assertDirectoryDoesNotExist($this->estado()->pasta());
        self::assertStringContainsString('href="/_pbl/atualizacao"', $this->html($this->req('GET', '/_pbl/', $this->token('admin'))), 'o administrador vê o link');
    }

    public function testPostSemCsrfOuComOrigemErradaERecusadoAntesDeQualquerCoisa(): void
    {
        $this->preparar();
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos(), null, false);
        self::assertSame(403, $r->getStatusCode());
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos(), null, true, ['HTTP_ORIGIN' => 'https://outro.example']);
        self::assertSame(403, $r->getStatusCode());
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', ['_csrf' => 'errado'] + $this->aplicarCampos(), null, false);
        self::assertSame(403, $r->getStatusCode());
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/verificar', [], null, false);
        self::assertSame(403, $r->getStatusCode());
        self::assertSame(0, $this->lancador->chamadas);
        self::assertSame(0, $this->baixador->consultas);
        self::assertSame([], $this->auditoria('atualizacao.iniciar'));
    }

    public function testRotasDesconhecidasOuComMetodoErradoNaoExistem(): void
    {
        self::assertNull(TelasDeAtualizacao::rota('/_pbl', '/_pbl/atualizacao', 'POST'));
        self::assertNull(TelasDeAtualizacao::rota('/_pbl', '/_pbl/atualizacao/aplicar', 'GET'));
        self::assertNull(TelasDeAtualizacao::rota('/_pbl', '/_pbl/atualizacao/outra', 'POST'));
        self::assertNull(TelasDeAtualizacao::rota('/_pbl', '/_pbl/atualizacao/aplicar/x', 'POST'));
        self::assertNull(TelasDeAtualizacao::rota('/_pbl', '/_pbl/atualizacao/reverter', 'GET'));
        self::assertSame('reverter', TelasDeAtualizacao::rota('/_pbl', '/_pbl/atualizacao/reverter', 'POST'));
    }

    // ------------------------------------------------------------------ consulta

    public function testSemUrlConfiguradaExplicaComoConfigurarENaoConsultaNada(): void
    {
        $this->preparar(configurar: false);
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringContainsString('Nenhum endereço de atualizações configurado', $h);
        self::assertStringContainsString('dados/config/atualizacao.json', $h);
        self::assertStringContainsString('<code>' . self::VERSAO . '</code>', $h);
        self::assertStringNotContainsString('Atualizar agora', $h);
        self::assertSame(0, $this->baixador->consultas);
    }

    public function testSemChaveDeAssinaturaDizClaramenteERecusa(): void
    {
        $this->preparar();
        $r = $this->tela('admin', 'GET', '/_pbl/atualizacao', [], []);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('Nenhuma chave de assinatura instalada; atualizações recusadas.', $this->html($r));
        self::assertStringNotContainsString('Atualizar agora', $this->html($r));
        self::assertSame(0, $this->baixador->consultas, 'sem chave nem consulta');
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos(), []);
        self::assertSame(409, $r->getStatusCode());
        self::assertSame(0, $this->lancador->chamadas);
    }

    public function testChaveDeProducaoVaziaDeVerdadeNaoQuebraATelaPadrao(): void
    {
        $this->preparar();
        // Sem injeção: valem Chaves::PUBLICAS (vazia) e o baixador HTTPS; nenhuma rede é tocada.
        $sessao = $this->s->sessoes()->validar($this->token('admin'));
        self::assertNotNull($sessao);
        $t = new TelasDeAtualizacao($this->s, new \Pbl\Acesso\Rede($this->caminhos), $this->s->csrf());
        $r = $t->tratar($sessao, 'ver', Request::create('https://www.exemplo.org/_pbl/atualizacao'));
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('Nenhuma chave de assinatura instalada; atualizações recusadas.', $this->html($r));
    }

    public function testVerificacaoDesligadaNaoConsulta(): void
    {
        $this->preparar();
        $this->configurar(['url' => $this->manifesto, 'verificar' => false]);
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringContainsString('desligada', $h);
        self::assertSame(0, $this->baixador->consultas);
    }

    public function testMostraVersaoNovaMudancasESegurancaEOFormularioDeAplicar(): void
    {
        $this->preparar();
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringContainsString('Nova versão: 0.2.0', $h);
        self::assertStringContainsString('Correções e novidades', $h);
        self::assertStringContainsString('Correção de segurança', $h);
        self::assertStringContainsString('name="senha"', $h);
        self::assertStringContainsString('name="confirmar"', $h);
        self::assertStringContainsString('name="versao" value="0.2.0"', $h);
        self::assertStringContainsString('Verificar agora', $h);
        self::assertStringContainsString('estavel', $h);
    }

    public function testResumoDoManifestoVaiEscapado(): void
    {
        $this->preparar(opcoes: ['resumo' => '<script>alert(1)</script><img src=x onerror=y>']);
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringNotContainsString('<script>', $h);
        self::assertStringNotContainsString('<img', $h);
        self::assertStringContainsString('&lt;script&gt;', $h);
    }

    public function testVersaoJaAtualNaoMostraFormulario(): void
    {
        $this->preparar(self::VERSAO);
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringContainsString('Já está na versão mais recente', $h);
        self::assertStringNotContainsString('Atualizar agora', $h);
    }

    public function testCacheDe24HorasEBotaoVerificarAgora(): void
    {
        $this->preparar();
        $this->tela('admin', 'GET', '/_pbl/atualizacao');
        $this->tela('admin', 'GET', '/_pbl/atualizacao');
        self::assertSame(1, $this->baixador->consultas, 'segunda visita usa o cache');
        $this->agora += 23 * 3600;
        $this->tela('admin', 'GET', '/_pbl/atualizacao');
        self::assertSame(1, $this->baixador->consultas, 'ainda dentro de 24 h');
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/verificar');
        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/_pbl/atualizacao?ok=verificado', $r->headers->get('Location'));
        self::assertSame(2, $this->baixador->consultas, 'verificar agora ignora o cache');
        $this->agora += 25 * 3600;
        $this->tela('admin', 'GET', '/_pbl/atualizacao');
        self::assertSame(3, $this->baixador->consultas, 'cache vencido consulta de novo');
        self::assertCount(1, $this->auditoria('atualizacao.verificar'));
    }

    public function testFalhaDeConsultaMostraOErroEFicaEmCacheCurto(): void
    {
        $this->preparar();
        $this->configurar(['url' => '/nao/existe/latest.json']);
        $r = $this->tela('admin', 'GET', '/_pbl/atualizacao');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('Não foi possível consultar o canal', $this->html($r));
        self::assertStringContainsString('Arquivo não encontrado', $this->html($r));
        $antes = $this->baixador->consultas;
        $this->tela('admin', 'GET', '/_pbl/atualizacao');
        self::assertSame($antes, $this->baixador->consultas, 'falha também fica em cache por pouco tempo');
        $this->agora += 3601;
        $this->tela('admin', 'GET', '/_pbl/atualizacao');
        self::assertSame($antes + 1, $this->baixador->consultas);
    }

    public function testAssinaturaInvalidaEhRecusadaComMensagem(): void
    {
        $this->preparar();
        $outra = Assinatura::gerarPar();
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao', [], [$outra['publica']]));
        self::assertStringContainsString('assinatura do manifesto não confere', $h);
        self::assertStringNotContainsString('Atualizar agora', $h);
    }

    // ------------------------------------------------------------------ aplicar

    public function testSenhaErradaNaoIniciaNadaERegistraNaAuditoria(): void
    {
        $this->preparar();
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos(senha: 'senha errada qualquer'));
        self::assertSame(401, $r->getStatusCode());
        self::assertStringContainsString('Senha incorreta.', $this->html($r));
        self::assertStringNotContainsString('senha errada qualquer', $this->html($r));
        self::assertSame(0, $this->lancador->chamadas);
        self::assertCount(1, $this->auditoria('atualizacao.senha_errada'));
        self::assertSame('ociosa', $this->estado()->situacao()['estado']);
    }

    public function testSenhaDeOutraContaNaoServe(): void
    {
        $this->preparar();
        $this->s->usuarios->definirSenha($this->ids['admin2'], 'outra senha bem longa 2026');
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos(senha: 'outra senha bem longa 2026'));
        self::assertSame(401, $r->getStatusCode());
        self::assertSame(0, $this->lancador->chamadas);
    }

    public function testMuitasSenhasErradasBloqueiam(): void
    {
        $this->preparar();
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(401, $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos(senha: 'errada' . $i))->getStatusCode());
        }
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos());
        self::assertSame(429, $r->getStatusCode(), 'mesmo com a senha certa, depois do limite');
        self::assertNotNull($r->headers->get('Retry-After'));
        self::assertSame(0, $this->lancador->chamadas);
    }

    public function testSemConfirmacaoOuCamposFaltandoNaoInicia(): void
    {
        $this->preparar();
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', ['versao' => '0.2.0', 'senha' => self::SENHA]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('Marque a confirmação', $this->html($r));
        self::assertSame(400, $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', ['confirmar' => '1'])->getStatusCode());
        self::assertSame(400, $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', ['versao' => ['0.2.0'], 'senha' => self::SENHA, 'confirmar' => '1'])->getStatusCode());
        self::assertSame(0, $this->lancador->chamadas);
    }

    public function testSeAVersaoDisponivelMudouPedeConfirmarDeNovo(): void
    {
        $this->preparar();
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos('0.1.5'));
        self::assertSame(409, $r->getStatusCode());
        self::assertStringContainsString('A versão disponível mudou para 0.2.0', $this->html($r));
        self::assertSame(0, $this->lancador->chamadas);
    }

    public function testAplicarIniciaEmSegundoPlanoUmaVezSoEAAtelaMostraAndamento(): void
    {
        $this->preparar();
        $antes = $this->foraDeDados();
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos());
        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/_pbl/atualizacao?ok=iniciada', $r->headers->get('Location'));
        self::assertSame(1, $this->lancador->chamadas);
        self::assertSame($this->estado()->pasta() . '/saida.txt', $this->lancador->saida);
        self::assertSame('andamento', $this->estado()->situacao()['estado']);
        $a = $this->auditoria('atualizacao.iniciar');
        self::assertCount(1, $a);
        self::assertSame(['de' => self::VERSAO, 'para' => '0.2.0'], json_decode((string) $a[0]['detalhe'], true));
        self::assertStringNotContainsString(self::SENHA, (string) json_encode($this->s->auditoria->recentes(500)));

        // trava: um segundo clique (ou outra pessoa) não inicia outra
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos());
        self::assertSame(409, $r->getStatusCode());
        self::assertStringContainsString('Já existe uma atualização em andamento', $this->html($r));
        $r = $this->tela('admin2', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos());
        self::assertSame(409, $r->getStatusCode());
        self::assertSame(1, $this->lancador->chamadas);

        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringContainsString('Em andamento', $h);
        self::assertStringNotContainsString('name="senha"', $h, 'sem formulário enquanto roda');
        self::assertSame($antes, $this->foraDeDados(), 'a requisição web só escreve em dados/');
    }

    public function testConcluidaMostraResultadoEAuditaUmaVez(): void
    {
        $this->preparar();
        $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos());
        file_put_contents($this->lancador->saida, "Disponível: 0.2.0.\nAtualizado de 0.1.0 para 0.2.0.\n");
        file_put_contents($this->lancador->codigo, "0\n");
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringContainsString('Terminou sem erro', $h);
        self::assertStringContainsString('Atualizado de 0.1.0 para 0.2.0.', $h);
        $this->tela('admin', 'GET', '/_pbl/atualizacao');
        self::assertCount(1, $this->auditoria('atualizacao.concluida'), 'só uma vez');
    }

    public function testFalhaMostraOMotivoEscapadoELiberaATrava(): void
    {
        $this->preparar();
        $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos());
        file_put_contents($this->lancador->saida, "\e[31mA atualização falhou <script>alert(1)</script>\e[0m\n");
        file_put_contents($this->lancador->codigo, '1');
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringContainsString('A atualização falhou', $h);
        self::assertStringContainsString('&lt;script&gt;', $h);
        self::assertStringNotContainsString('<script>', $h);
        self::assertStringNotContainsString("\e", $h);
        self::assertCount(1, $this->auditoria('atualizacao.falhou'));
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos());
        self::assertSame(303, $r->getStatusCode(), 'depois da falha pode tentar de novo');
        self::assertSame(2, $this->lancador->chamadas);
        self::assertFileDoesNotExist($this->lancador->codigo, 'resultado antigo foi limpo');
    }

    public function testProcessoQueNuncaTerminaSoltaATravaDepoisDoLimite(): void
    {
        $this->preparar();
        $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos());
        $this->agora += EstadoDoPainel::LIMITE_EXECUCAO + 1;
        self::assertSame('sem_resposta', $this->estado()->situacao()['estado']);
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringContainsString('não deu sinal de término', $h);
        self::assertSame(303, $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos())->getStatusCode());
        self::assertSame(2, $this->lancador->chamadas);
    }

    public function testFalhaAoLancarMostraErroENaoPrendeATrava(): void
    {
        $this->preparar();
        $this->lancador->falhar = true;
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos());
        self::assertSame(503, $r->getStatusCode());
        self::assertStringContainsString('Não achei o PHP de linha de comando', $this->html($r));
        self::assertSame('ociosa', $this->estado()->situacao()['estado']);
        $this->lancador->falhar = false;
        self::assertSame(303, $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos())->getStatusCode());
    }

    public function testSemAtualizacaoConcluidaNaoMostraOBotaoDeVoltarAtras(): void
    {
        $this->preparar();
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringContainsString('Se algo der errado', $h);
        self::assertStringContainsString('Não há atualização concluída para desfazer', $h);
        self::assertStringNotContainsString('name="senha-reverter"', $h);
        self::assertStringNotContainsString('action="/_pbl/atualizacao/reverter"', $h);
    }

    // ------------------------------------------------------------------ reverter

    public function testBotaoDeReverterApareceEFuncionaComSenhaEConfirmacao(): void
    {
        $this->preparar();
        $this->fabricarAtualizacaoConcluida('0.1.0', self::VERSAO);
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringContainsString('Voltar para a 0.1.0', $h);
        self::assertStringContainsString('id="campo-senha-reverter"', $h);
        self::assertStringContainsString('action="/_pbl/atualizacao/reverter"', $h);

        $antes = $this->foraDeDados();
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/reverter', ['senha' => self::SENHA, 'confirmar' => '1']);
        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/_pbl/atualizacao?ok=reversao_iniciada', $r->headers->get('Location'));
        self::assertSame(['reverter'], $this->lancador->comandos);
        self::assertSame($antes, $this->foraDeDados(), 'a requisição web só escreve em dados/');
        $s = $this->estado()->situacao();
        self::assertSame('andamento', $s['estado']);
        self::assertSame('reverter', $s['tipo']);
        $a = $this->auditoria('atualizacao.reverter_iniciar');
        self::assertCount(1, $a);
        self::assertSame(['de' => self::VERSAO, 'para' => '0.1.0'], json_decode((string) $a[0]['detalhe'], true));
        self::assertStringNotContainsString(self::SENHA, (string) json_encode($this->s->auditoria->recentes(500)));

        // trava: um segundo clique não inicia outra
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/reverter', ['senha' => self::SENHA, 'confirmar' => '1']);
        self::assertSame(409, $r->getStatusCode());
        self::assertSame(1, $this->lancador->chamadas);
    }

    public function testReverterConcluidoMostraResultadoEAuditaUmaVezComPrefixoProprio(): void
    {
        $this->preparar();
        $this->fabricarAtualizacaoConcluida('0.1.0', self::VERSAO);
        $this->tela('admin', 'POST', '/_pbl/atualizacao/reverter', ['senha' => self::SENHA, 'confirmar' => '1']);
        file_put_contents($this->lancador->saida, "Revertido de 0.2.0 para 0.1.0.\n");
        file_put_contents($this->lancador->codigo, "0\n");
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringContainsString('Última volta atrás iniciada pelo painel', $h);
        self::assertStringContainsString('Terminou sem erro', $h);
        $this->tela('admin', 'GET', '/_pbl/atualizacao');
        self::assertCount(1, $this->auditoria('atualizacao.reverter_concluida'), 'só uma vez');
        self::assertSame([], $this->auditoria('atualizacao.concluida'), 'não usa a ação de uma atualização comum');
    }

    public function testReverterSenhaErradaNaoIniciaNadaERegistraNaAuditoria(): void
    {
        $this->preparar();
        $this->fabricarAtualizacaoConcluida('0.1.0', self::VERSAO);
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/reverter', ['senha' => 'senha errada qualquer', 'confirmar' => '1']);
        self::assertSame(401, $r->getStatusCode());
        self::assertStringContainsString('Senha incorreta.', $this->html($r));
        self::assertSame(0, $this->lancador->chamadas);
        self::assertCount(1, $this->auditoria('atualizacao.senha_errada'));
        self::assertSame('ociosa', $this->estado()->situacao()['estado']);
    }

    public function testReverterSemConfirmacaoOuSemSenhaNaoInicia(): void
    {
        $this->preparar();
        $this->fabricarAtualizacaoConcluida('0.1.0', self::VERSAO);
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/reverter', ['senha' => self::SENHA]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('Marque a confirmação', $this->html($r));
        self::assertSame(400, $this->tela('admin', 'POST', '/_pbl/atualizacao/reverter', ['confirmar' => '1'])->getStatusCode());
        self::assertSame(0, $this->lancador->chamadas);
    }

    public function testReverterSemCsrfOuOrigemErradaERecusado(): void
    {
        $this->preparar();
        $this->fabricarAtualizacaoConcluida('0.1.0', self::VERSAO);
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/reverter', ['senha' => self::SENHA, 'confirmar' => '1'], null, false);
        self::assertSame(403, $r->getStatusCode());
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/reverter', ['senha' => self::SENHA, 'confirmar' => '1'], null, true, ['HTTP_ORIGIN' => 'https://outro.example']);
        self::assertSame(403, $r->getStatusCode());
        self::assertSame(0, $this->lancador->chamadas);
    }

    public function testReverterSoParaAdministradorGeral(): void
    {
        $this->preparar();
        $this->fabricarAtualizacaoConcluida('0.1.0', self::VERSAO);
        foreach (['editor', 'leitor'] as $login) {
            self::assertSame(403, $this->post($this->token($login), '/_pbl/atualizacao/reverter', ['senha' => self::SENHA, 'confirmar' => '1'])->getStatusCode(), $login);
        }
        self::assertSame(0, $this->lancador->chamadas);
    }

    public function testReverterRecusadoQuandoJaHaOperacaoEmAndamento(): void
    {
        $this->preparar();
        $this->fabricarAtualizacaoConcluida('0.1.0', self::VERSAO);
        $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos());
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/reverter', ['senha' => self::SENHA, 'confirmar' => '1']);
        self::assertSame(409, $r->getStatusCode());
        self::assertStringContainsString('Já existe uma atualização em andamento', $this->html($r));
        self::assertSame(1, $this->lancador->chamadas, 'só a atualização chamou o lançador');
    }

    public function testReverterRecusaQuandoOBancoMudouDeFormaIncompativelDepoisDaAtualizacao(): void
    {
        $this->preparar();
        $this->fabricarAtualizacaoConcluida('0.1.0', self::VERSAO);
        // Simula um esquema de banco diferente do registrado (migração ou alteração posterior): avaliar() deve recusar.
        $r = RegistroDaAtualizacao::ler($this->caminhos) ?? self::fail();
        $r['schema_depois'] = ($r['schema_depois'] ?? 0) + 1000;
        RegistroDaAtualizacao::gravar($this->caminhos, $r);

        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringContainsString('O banco mudou de forma incompatível', $h);
        self::assertStringNotContainsString('action="/_pbl/atualizacao/reverter"', $h);

        $resp = $this->tela('admin', 'POST', '/_pbl/atualizacao/reverter', ['senha' => self::SENHA, 'confirmar' => '1']);
        self::assertSame(409, $resp->getStatusCode());
        self::assertStringContainsString('O banco mudou de forma incompatível', $this->html($resp));
        self::assertSame(0, $this->lancador->chamadas);
    }

    public function testReverterRecusaQuandoAVersaoInstaladaNaoEADaUltimaAtualizacaoRegistrada(): void
    {
        $this->preparar();
        $this->fabricarAtualizacaoConcluida('0.1.0', '0.9.9'); // registro de uma transição diferente da versão instalada (self::VERSAO)
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringContainsString('não é a da última atualização registrada', $h);
        self::assertSame(409, $this->tela('admin', 'POST', '/_pbl/atualizacao/reverter', ['senha' => self::SENHA, 'confirmar' => '1'])->getStatusCode());
        self::assertSame(0, $this->lancador->chamadas);
    }

    // ------------------------------------------------------------------ processo real

    public function testLancadorDeProcessoRodaEmSegundoPlanoEGravaSaidaECodigo(): void
    {
        $raiz = Ajuda::pastaTemporaria();
        $this->limpar[] = $raiz;
        mkdir($raiz . '/bin');
        file_put_contents($raiz . '/bin/pbl', "<?php\nusleep(300000);\necho 'argumento=' . (\$argv[1] ?? '') . \"\\n\";\nexit(3);\n");
        $php = LancadorDeProcesso::descobrirPhp();
        self::assertNotNull($php, 'o PHP de linha de comando é achado nos testes');
        $inicio = microtime(true);
        (new LancadorDeProcesso(new \Pbl\Caminhos($raiz), $php))->iniciar($raiz . '/saida.txt', $raiz . '/codigo');
        self::assertLessThan(0.25, microtime(true) - $inicio, 'volta sem esperar o processo');
        for ($i = 0; $i < 100 && !is_file($raiz . '/codigo'); $i++) {
            usleep(100_000);
        }
        self::assertSame('3', trim((string) file_get_contents($raiz . '/codigo')));
        self::assertSame('argumento=atualizar', trim((string) file_get_contents($raiz . '/saida.txt')));
    }

    public function testLancadorSemPhpOuSemScriptExplicaOProblema(): void
    {
        $raiz = Ajuda::pastaTemporaria();
        $this->limpar[] = $raiz;
        try {
            (new LancadorDeProcesso(new \Pbl\Caminhos($raiz), '/usr/local/bin/php'))->iniciar($raiz . '/s', $raiz . '/c');
            self::fail('sem bin/pbl deveria falhar');
        } catch (ErroDeAtualizacao $e) {
            self::assertStringContainsString('bin/pbl', $e->getMessage());
        }
        self::assertSame(LancadorDeProcesso::descobrirPhp(), LancadorDeProcesso::descobrirPhp('relativo/php'), 'caminho relativo é ignorado');
        self::assertNotSame('/bin/sh', LancadorDeProcesso::descobrirPhp('/bin/sh'), 'só aceita executável chamado php');
    }

    public function testAplicacaoCompletaPeloPainelSemInjecaoNaoQuebra(): void
    {
        $this->preparar(configurar: false);
        $r = $this->req('GET', '/_pbl/atualizacao', $this->token('admin'));
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('Nenhum endereço de atualizações configurado', $this->html($r));
        self::assertSame(404, $this->req('GET', '/_pbl/atualizacao/aplicar', $this->token('admin'))->getStatusCode());
        $h = $this->html($this->req('GET', '/_pbl/atualizacao', $this->token('admin')));
        self::assertStringContainsString('Atualização', $h);
    }
}

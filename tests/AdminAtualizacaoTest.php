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
use Pbl\Atualizacao\PedidoDeAtualizacao;
use Pbl\Atualizacao\ProgressoEmArquivo;
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
        $rota = TelasDeAtualizacao::rota('/_pbl', (string) parse_url($caminho, PHP_URL_PATH), $metodo);
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
            'codigo_sha256' => \Pbl\Atualizacao\ResumoDeArvore::dosItens($pasta . '/anterior', ['app']),
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

    public function testBuildSemChavesDizClaramenteERecusa(): void
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

    public function testComAsChavesEmbutidasATelaPadraoNaoMostraOAvisoDeSemChave(): void
    {
        $this->preparar();
        // Sem injeção: valem Chaves::PUBLICAS (embutidas) e o baixador HTTPS; o endereço de teste não é https, então nenhuma rede é tocada.
        $sessao = $this->s->sessoes()->validar($this->token('admin'));
        self::assertNotNull($sessao);
        $t = new TelasDeAtualizacao($this->s, new \Pbl\Acesso\Rede($this->caminhos), $this->s->csrf());
        $r = $t->tratar($sessao, 'ver', Request::create('https://www.exemplo.org/_pbl/atualizacao'));
        self::assertSame(200, $r->getStatusCode());
        self::assertStringNotContainsString('Nenhuma chave de assinatura instalada', $this->html($r));
        self::assertStringContainsString('Não foi possível consultar o canal', $this->html($r), 'a consulta foi tentada e barrada pelo HTTPS, não pela falta de chave');
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
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao?aba=historico'));
        self::assertStringContainsString('Terminou sem erro', $h);
        self::assertStringContainsString('Atualizado de 0.1.0 para 0.2.0.', $h);
        $this->tela('admin', 'GET', '/_pbl/atualizacao?aba=historico');
        self::assertCount(1, $this->auditoria('atualizacao.concluida'), 'só uma vez');
    }

    public function testFalhaMostraOMotivoEscapadoELiberaATrava(): void
    {
        $this->preparar();
        $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos());
        file_put_contents($this->lancador->saida, "\e[31mA atualização falhou <script>alert(1)</script>\e[0m\n");
        file_put_contents($this->lancador->codigo, '1');
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao?aba=historico'));
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
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao?aba=historico'));
        self::assertStringContainsString('não deu sinal de término', $h);
        self::assertSame(303, $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos())->getStatusCode());
        self::assertSame(2, $this->lancador->chamadas);
    }

    public function testFalhaAoLancarComAgendamentoDesligadoMostraErroENaoPrendeATrava(): void
    {
        $this->preparar();
        $this->configurar(['url' => $this->manifesto, 'canal' => 'estavel', 'agendar' => false]);
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
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao?aba=historico'));
        self::assertStringContainsString('Voltar atrás', $h);
        self::assertStringContainsString('Não há atualização concluída para desfazer', $h);
        self::assertStringNotContainsString('name="senha-reverter"', $h);
        self::assertStringNotContainsString('action="/_pbl/atualizacao/reverter"', $h);
    }

    // ------------------------------------------------------------------ fase 2: estado + ação principal, abas, diagnóstico


    public function testCabecalhoTemUmaAcaoPrincipalPorEstado(): void
    {
        // versão nova
        $this->preparar();
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringContainsString('Atualizar para 0.2.0', $h);
        self::assertSame(1, substr_count($h, 'class="btn btn-primary"'));
        self::assertSame(1, substr_count($h, 'action="/_pbl/atualizacao/verificar"'), 'um só "Verificar agora"');
        self::assertStringContainsString('href="/_pbl/atualizacao?aba=historico"', $h);
        self::assertStringContainsString('aria-current="page">Atualizar<', $h);
        self::assertStringNotContainsString('style=', $h);
    }

    public function testEmDiaEOutrosEstadosTemVerificarAgoraOuConfigurar(): void
    {
        $this->preparar(self::VERSAO);
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringContainsString('Você está na versão mais recente', $h);
        self::assertStringContainsString('btn btn-primary">Verificar agora', $h);
        self::assertStringNotContainsString('Atualizar para', $h);

    }

    public function testSemEnderecoOfereceConfigurarComoAcaoPrincipal(): void
    {
        $this->preparar(configurar: false);
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringContainsString('btn btn-primary" href="/_pbl/atualizacao?aba=configuracao">Configurar', $h);
        self::assertStringNotContainsString('btn btn-primary">Verificar agora', $h);
    }

    public function testAndamentoSoApareceQuandoHaAtualizacaoEmCurso(): void
    {
        $this->preparar();
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringNotContainsString('data-pbl-progresso', $h);
        self::assertStringNotContainsString('role="progressbar"', $h);
        $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos());
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringContainsString('data-pbl-progresso', $h);
        self::assertStringContainsString('href="#titulo-andamento">Ver andamento', $h);
        self::assertStringNotContainsString('Atualizar para', $h);
    }

    public function testVoltarAtrasNaoApareceNaAbaAtualizarENemNaConfiguracao(): void
    {
        $this->preparar();
        foreach (['', '?aba=configuracao'] as $q) {
            $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao' . $q));
            self::assertStringNotContainsString('action="/_pbl/atualizacao/reverter"', $h);
            self::assertStringNotContainsString('id="titulo-voltar"', $h);
        }
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao?aba=historico'));
        self::assertStringContainsString('id="titulo-voltar"', $h);
        self::assertStringContainsString('Nada foi feito pelo painel ainda', $h);
    }

    public function testConfiguracaoMostraCanalEnderecoEODiagnosticoRecolhido(): void
    {
        $this->preparar();
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao?aba=configuracao'));
        self::assertStringContainsString('Endereço de consulta', $h);
        self::assertStringContainsString('estavel', $h);
        self::assertStringContainsString('<summary class="pbl-resumo">Diagnóstico', $h);
        self::assertStringContainsString('proc_open', $h);
        self::assertStringContainsString('href="/_pbl/tarefas"', $h);
        self::assertStringNotContainsString('name="senha"', $h);
    }

    public function testAbaInvalidaVoltaParaAtualizar(): void
    {
        $this->preparar();
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao?aba=%3Cscript%3E'));
        self::assertStringContainsString('name="versao" value="0.2.0"', $h);
        self::assertStringNotContainsString('<script>', $h);
    }

    public function testErroDeReverterMostraAAbaHistorico(): void
    {
        $this->preparar();
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/reverter', ['senha' => 'x']);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('id="titulo-voltar"', $this->html($r));
    }

    public function testMigalhasEMenuNaAtualizacao(): void
    {
        $this->preparar();
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringContainsString('<a href="/_pbl/mais">Mais</a>', $h);
        self::assertStringContainsString('aria-current="page">Atualização</li>', $h);
    }

    // ------------------------------------------------------------------ reverter

    public function testBotaoDeReverterApareceEFuncionaComSenhaEConfirmacao(): void
    {
        $this->preparar();
        $this->fabricarAtualizacaoConcluida('0.1.0', self::VERSAO);
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao?aba=historico'));
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
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao?aba=historico'));
        self::assertStringContainsString('Última volta atrás iniciada pelo painel', $h);
        self::assertStringContainsString('Terminou sem erro', $h);
        $this->tela('admin', 'GET', '/_pbl/atualizacao?aba=historico');
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

        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao?aba=historico'));
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
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao?aba=historico'));
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

    // ------------------------------------------------------------------ agendamento para o cron e andamento

    private function pedidos(): PedidoDeAtualizacao
    {
        return new PedidoDeAtualizacao($this->caminhos, fn (): int => $this->agora);
    }

    /** @return array<string, mixed> */
    private function json(Response $r): array
    {
        $d = json_decode($this->html($r), true);
        self::assertIsArray($d);

        return $d;
    }

    public function testSemPermissaoParaIniciarProcessosOBotaoAgendaParaOCron(): void
    {
        $this->preparar();
        $this->lancador->falhar = true;

        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos());

        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/_pbl/atualizacao?ok=agendada_sem_processo', $r->headers->get('Location'));
        $p = $this->pedidos()->ler();
        self::assertNotNull($p);
        self::assertSame('atualizar', $p->tipo);
        self::assertSame('0.2.0', $p->versaoAlvo);
        self::assertSame($this->ids['admin'], $p->porId);
        self::assertSame($this->agora + 1800, $p->validoAte);
        self::assertSame('ociosa', $this->estado()->situacao()['estado'], 'nada ficou preso no estado do painel');
        self::assertStringNotContainsString(self::SENHA, (string) file_get_contents(PedidoDeAtualizacao::arquivo($this->caminhos)));
        self::assertCount(1, $this->auditoria('atualizacao.agendar'));
        $pagina = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao?ok=agendada_sem_processo'));
        self::assertStringContainsString('Esta hospedagem não deixa o painel iniciar a atualização na hora', $pagina);
    }

    public function testAdministradorPodeEscolherAgendarMesmoComProcessosLiberados(): void
    {
        $this->preparar();

        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos() + ['modo' => 'agendar']);

        self::assertSame('/_pbl/atualizacao?ok=agendada', $r->headers->get('Location'));
        self::assertSame(0, $this->lancador->chamadas, 'nenhum processo foi iniciado pela requisição');
        self::assertNotNull($this->pedidos()->ler());
        self::assertSame('Agendada: aguardando o próximo ciclo do cron (até 1 minuto).', $this->json($this->tela('admin', 'GET', '/_pbl/atualizacao/progresso'))['mensagem'] . '.');
    }

    public function testAgendarExigeAsMesmasCoisasQueAtualizarAgora(): void
    {
        $this->preparar();
        $agendar = $this->aplicarCampos() + ['modo' => 'agendar'];

        self::assertSame(422, $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', ['confirmar' => '0'] + $agendar)->getStatusCode(), 'sem confirmação');
        self::assertSame(400, $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', ['senha' => null] + $agendar)->getStatusCode(), 'sem senha');
        self::assertSame(401, $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', ['senha' => 'errada errada errada'] + $agendar)->getStatusCode(), 'senha errada');
        self::assertSame(403, $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $agendar, null, false)->getStatusCode(), 'sem CSRF');
        self::assertSame(403, $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $agendar, null, true, ['HTTP_ORIGIN' => 'https://outro.example'])->getStatusCode(), 'origem errada');
        self::assertSame(409, $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', ['versao' => '0.9.9'] + $agendar)->getStatusCode(), 'versão diferente da do manifesto');
        self::assertFalse($this->pedidos()->existe(), 'nenhuma das recusas gravou pedido');
        self::assertSame([], $this->auditoria('atualizacao.agendar'));
    }

    public function testQuemNaoEAdministradorNaoAgendaNemCancelaNemVeOAndamento(): void
    {
        $this->preparar();
        foreach (['editor', 'leitor'] as $login) {
            $r = $this->post($this->token($login), '/_pbl/atualizacao/aplicar', $this->aplicarCampos() + ['modo' => 'agendar']);
            self::assertSame(403, $r->getStatusCode(), $login);
            self::assertSame(403, $this->post($this->token($login), '/_pbl/atualizacao/cancelar-agendamento', [])->getStatusCode(), $login);
            $j = $this->req('GET', '/_pbl/atualizacao/progresso', $this->token($login));
            self::assertSame(403, $j->getStatusCode(), $login);
            self::assertStringStartsWith('application/json', (string) $j->headers->get('Content-Type'));
        }
        self::assertSame(303, $this->req('GET', '/_pbl/atualizacao/progresso')->getStatusCode(), 'sem login vai para entrar');
        self::assertFalse($this->pedidos()->existe());
    }

    public function testPedidoRepetidoOuComAtualizacaoEmAndamentoERecusado(): void
    {
        $this->preparar();
        $agendar = $this->aplicarCampos() + ['modo' => 'agendar'];
        self::assertSame(303, $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $agendar)->getStatusCode());

        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $agendar);
        self::assertSame(409, $r->getStatusCode());
        self::assertStringContainsString('Já existe uma atualização agendada', $this->html($r));
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos());
        self::assertSame(409, $r->getStatusCode(), 'nem o botão "agora" vale com um pedido agendado');
        self::assertSame(0, $this->lancador->chamadas);
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringNotContainsString('name="versao"', $h, 'o formulário de atualizar some enquanto há pedido');
    }

    public function testAtualizacaoEmAndamentoNoCronRecusaOutroPedido(): void
    {
        $this->preparar();
        $r = new ProgressoEmArquivo($this->caminhos, 'terminal', fn (): int => $this->agora);
        $r->iniciar('atualizar');

        $resp = $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos() + ['modo' => 'agendar']);

        self::assertSame(409, $resp->getStatusCode());
        self::assertStringContainsString('em andamento', $this->html($resp));
    }

    public function testAgendarSoPodeSerDesligadoPelaChaveDeConfiguracao(): void
    {
        $this->preparar();
        $this->configurar(['url' => $this->manifesto, 'canal' => 'estavel', 'agendar' => false]);

        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos() + ['modo' => 'agendar']);

        self::assertSame('/_pbl/atualizacao?ok=iniciada', $r->headers->get('Location'), 'modo=agendar é ignorado');
        self::assertFalse($this->pedidos()->existe());
        self::assertSame(1, $this->lancador->chamadas);
        self::assertStringNotContainsString('agendar para o cron', $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao')));
    }

    public function testCancelaPedidoQueNaoComecouERecusaDepoisDeComecar(): void
    {
        $this->preparar();
        $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos() + ['modo' => 'agendar']);

        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/cancelar-agendamento');
        self::assertSame('/_pbl/atualizacao?ok=cancelada', $r->headers->get('Location'));
        self::assertFalse($this->pedidos()->existe());
        self::assertCount(1, $this->auditoria('atualizacao.cancelar_agendamento'));
        self::assertSame(409, $this->tela('admin', 'POST', '/_pbl/atualizacao/cancelar-agendamento')->getStatusCode(), 'nada a cancelar');

        $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos() + ['modo' => 'agendar']);
        $p = $this->pedidos()->ler();
        self::assertNotNull($p);
        $this->pedidos()->iniciar($p); // o cron começou
        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/cancelar-agendamento');
        self::assertSame(409, $r->getStatusCode());
        self::assertStringContainsString('já começou', $this->html($r));
        self::assertNotNull($this->pedidos()->ler());
        self::assertSame(403, $this->tela('admin', 'POST', '/_pbl/atualizacao/cancelar-agendamento', [], null, false)->getStatusCode(), 'CSRF');
    }

    public function testVoltarAtrasTambemPodeSerAgendadoComAVersaoRegistrada(): void
    {
        $this->preparar();
        $this->fabricarAtualizacaoConcluida('0.1.0', self::VERSAO);

        $r = $this->tela('admin', 'POST', '/_pbl/atualizacao/reverter', ['senha' => self::SENHA, 'confirmar' => '1', 'modo' => 'agendar']);

        self::assertSame('/_pbl/atualizacao?ok=agendada', $r->headers->get('Location'));
        $p = $this->pedidos()->ler();
        self::assertNotNull($p);
        self::assertSame('reverter', $p->tipo);
        self::assertSame('0.1.0', $p->versaoAlvo);
        self::assertSame([], $this->lancador->comandos);
    }

    public function testEndpointDeProgressoDevolveSoACamposFixosSemSegredosNemCaminhos(): void
    {
        $this->preparar();
        $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos() + ['modo' => 'agendar']);
        $pedido = $this->pedidos()->ler();
        self::assertNotNull($pedido);
        $nonce = $pedido->nonce;

        $r = $this->tela('admin', 'GET', '/_pbl/atualizacao/progresso');

        self::assertSame(200, $r->getStatusCode());
        self::assertStringStartsWith('application/json', (string) $r->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        self::assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $d = $this->json($r);
        self::assertSame('agendada', $d['estado']);
        self::assertSame('atualizar', $d['tipo']);
        self::assertSame($this->agora, $d['agendada_em']);
        self::assertSame($this->agora + 1800, $d['valida_ate']);
        self::assertFalse($d['cron_ativo']);
        $corpo = $this->html($r);
        self::assertStringNotContainsString($nonce, $corpo);
        self::assertStringNotContainsString($this->raiz, $corpo);
        self::assertStringNotContainsString(self::SENHA, $corpo);
        self::assertStringNotContainsString('Admin', $corpo, 'nem o nome de quem pediu');
    }

    public function testEndpointMostraOAndamentoDeQualquerOrigemEFalhaSemCaminhos(): void
    {
        $this->preparar();
        $p = new ProgressoEmArquivo($this->caminhos, 'terminal', fn (): int => $this->agora);
        $p->iniciar('atualizar');
        $p->etapa(\Pbl\Atualizacao\Etapa::MigrandoBanco);
        $d = $this->json($this->tela('admin', 'GET', '/_pbl/atualizacao/progresso'));
        self::assertSame('em_andamento', $d['estado']);
        self::assertSame('migrando_banco', $d['etapa']);
        self::assertSame(85, $d['percentual']);

        $p->falhar("quebrou em {$this->raiz}/app/src/A.php");
        $r = $this->tela('admin', 'GET', '/_pbl/atualizacao/progresso');
        $d = $this->json($r);
        self::assertSame('falhou', $d['estado']);
        self::assertStringNotContainsString($this->raiz, $this->html($r));
        self::assertStringContainsString('quebrou em', (string) $d['falha']);
    }

    public function testPaginaMostraBarraComAriaEScriptProprioSemInline(): void
    {
        $this->preparar();
        $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos() + ['modo' => 'agendar']);

        $r = $this->tela('admin', 'GET', '/_pbl/atualizacao?ok=agendada');
        $h = $this->html($r);

        self::assertStringContainsString('role="progressbar"', $h);
        self::assertStringContainsString('aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"', $h);
        self::assertStringContainsString('aria-valuetext="0% — Agendada: aguardando o próximo ciclo do cron (até 1 minuto)"', $h);
        self::assertMatchesRegularExpression('/aria-live="polite" aria-atomic="true" data-pbl-etapa>Agendada</', $h);
        self::assertStringContainsString('data-url="/_pbl/atualizacao/progresso"', $h);
        self::assertStringContainsString('Agendada: aguardando o próximo ciclo do cron (até 1 minuto)', $h);
        self::assertStringContainsString('Cancelar o agendamento', $h);
        self::assertStringContainsString('<script src="/_pbl/assets/pbl-atualizacao-progresso.js?v=', $h);
        self::assertSame(0, preg_match('/<script(?![^>]*\ssrc=)/', $h), 'nenhum script inline');
        self::assertStringNotContainsString('style=', $h);
        self::assertMatchesRegularExpression('/role="status" data-pbl-ok/', $h);
        self::assertMatchesRegularExpression('/role="alert" data-pbl-erro/', $h);
        self::assertMatchesRegularExpression('/<div class="alert alert-warning" hidden role="status" data-pbl-aviso-cron>/', $h, 'o aviso do cron só aparece depois de 5 minutos');
        self::assertStringContainsString("connect-src 'self'", (string) $r->headers->get('Content-Security-Policy'));
        self::assertFileExists(__DIR__ . '/../public/_pbl/assets/pbl-atualizacao-progresso.js');
    }

    public function testPaginaAvisaSeOCronNaoPegarOPedidoEmCincoMinutos(): void
    {
        $this->preparar();
        $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos() + ['modo' => 'agendar']);
        $this->agora += 301;

        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));

        self::assertStringContainsString('O cron parece não estar rodando', $h);
        self::assertStringContainsString('php …/bin/pbl cron', $h);
        self::assertDoesNotMatchRegularExpression('/<div class="alert alert-warning" hidden/', $h);
        self::assertStringNotContainsString($this->raiz, $h);
    }

    public function testPaginaMostraResultadoConcluidoComStatusEFalhaComAlert(): void
    {
        $this->preparar();
        $p = new ProgressoEmArquivo($this->caminhos, 'cron', fn (): int => $this->agora);
        $p->iniciar('atualizar');
        $this->agora += 75;
        $p->concluir();
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringContainsString('aria-valuenow="100"', $h);
        self::assertStringContainsString('role="status" data-pbl-ok>A atualização terminou sem erro', $h);
        self::assertStringContainsString('Durou 1 min 15 s.', $h);

        $p->iniciar('atualizar');
        $p->falhar('A assinatura do manifesto não confere: a atualização foi recusada.');
        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));
        self::assertStringContainsString('role="alert" data-pbl-erro>A atualização falhou. A assinatura do manifesto não confere', $h);
    }

    public function testSemNadaEmAndamentoAPaginaNaoCarregaOScriptDeProgresso(): void
    {
        $this->preparar();

        $h = $this->html($this->tela('admin', 'GET', '/_pbl/atualizacao'));

        self::assertStringNotContainsString('pbl-atualizacao-progresso.js', $h);
        self::assertStringNotContainsString('data-pbl-progresso', $h);
    }

    public function testScriptDeProgressoEhPropriosemEvalNemInnerHtml(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../public/_pbl/assets/pbl-atualizacao-progresso.js');

        self::assertStringNotContainsString('innerHTML', $js);
        self::assertStringNotContainsString('eval(', $js);
        self::assertStringNotContainsString('document.write', $js);
        self::assertStringContainsString("credentials: 'same-origin'", $js);
        self::assertStringContainsString('textContent', $js);
    }

    public function testTarefaDoCronSoTemTrabalhoComPedido(): void
    {
        $this->preparar();
        $falha = static function (): never {
            throw new \LogicException('não deveria ser chamado');
        };
        $tarefa = new \Pbl\Cron\Tarefas\AtualizacaoAgendadaTarefa(new \Pbl\Atualizacao\ExecutorDePedido(
            $this->caminhos,
            $this->pedidos(),
            $falha,
            $falha,
            static function (): void {
            },
            fn (): int => $this->agora,
        ));

        self::assertSame('atualizacao-agendada', $tarefa->id());
        self::assertFalse($tarefa->leve(), 'nunca no pseudo-cron');
        self::assertFalse($tarefa->temTrabalho($this->s));
        $this->tela('admin', 'POST', '/_pbl/atualizacao/aplicar', $this->aplicarCampos() + ['modo' => 'agendar']);
        self::assertTrue($tarefa->temTrabalho($this->s));
        self::assertNotNull(\Pbl\Cron\Catalogo::porId(\Pbl\Cron\Catalogo::padrao(), 'atualizacao-agendada'));
    }
}

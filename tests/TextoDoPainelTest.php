<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Testes\Texto\Medidor;
use PHPUnit\Framework\TestCase;

/**
 * Anti-enchimento: renderiza as telas do painel e falha se aparecer padrão proibido (docs/ux/voz-e-texto.md), se uma ajuda passar
 * de {@see LIMITE_AJUDA} caracteres ou se um subtítulo tiver mais de uma frase. Exceções: tests/fixtures/texto-excecoes.php (id => justificativa).
 * Com `PBL_AUDITORIA_SAIDA=arquivo.json` também grava a contagem de palavras por tela e categoria (auditoria antes→depois).
 */
final class TextoDoPainelTest extends TestCase
{
    use AdminAuxiliar;

    /** Limite de caracteres de uma ajuda de campo/tela (uma linha). */
    private const LIMITE_AJUDA = 140;
    private const LIMITE_SUBTITULO = 120;
    /** Padrões proibidos (regex, sem diferenciar maiúsculas) — ver docs/ux/voz-e-texto.md. */
    private const PROIBIDOS = [
        'aqui você pode', 'esta tela', 'é importante', 'lembre-se', '\\bbasta\\b', 'simplesmente', 'por favor', 'gostaríamos', 'a fim de', 'vale ressaltar',
    ];
    /**
     * Telas da onda 2 (aguardam a aprovação do dono da prévia): os padrões proibidos já valem, os limites de tamanho ainda não.
     * Esvaziar esta lista ao concluir a onda 2.
     */
    private const ONDA_2 = [];

    /** @return array<string, string> nome => caminho GET (admin) */
    private function telas(): array
    {
        $s = '/_pbl/site/' . $this->siteId;
        $o = '/_pbl/objeto/' . $this->inicioId;

        return [
            'inicio' => '/_pbl/', 'conteudo' => '/_pbl/conteudo/' . $this->inicioId, 'editar' => $o . '/editar', 'criar' => $o . '/criar',
            'configuracoes' => $s . '/configuracoes', 'redirecionamentos' => $s . '/redirecionamentos', 'peles' => $s . '/peles',
            'busca' => $s . '/busca', 'publicacao' => $s . '/publicacao', 'dominios' => $s . '/dominios',
            'atualizacao' => '/_pbl/atualizacao', 'copias' => '/_pbl/copias', 'tarefas' => '/_pbl/tarefas',
            'contas' => '/_pbl/contas', 'conta-nova' => '/_pbl/contas/nova', 'conta' => '/_pbl/conta/' . $this->ids['editor'],
            'classes' => '/_pbl/classes', 'classe-nova' => '/_pbl/classes/nova', 'modelos' => '/_pbl/modelos',
            'revisar' => '/_pbl/revisar', 'pendencias' => '/_pbl/pendencias', 'vencidos' => '/_pbl/vencidos', 'lixeira' => '/_pbl/lixeira',
            'mais' => '/_pbl/mais', 'pilha' => '/_pbl/pilha', 'meus-envios' => '/_pbl/meus-envios',
        ];
    }

    /** @return array<string, string> nome => HTML (200) */
    private function renderizar(): array
    {
        $t = $this->token('admin');
        $htmls = [];
        foreach ($this->telas() as $nome => $caminho) {
            $r = $this->req('GET', $caminho, $t);
            if ($r->getStatusCode() === 200) {
                $htmls[$nome] = $this->html($r);
            }
        }
        $htmls['entrar'] = $this->html($this->req('GET', '/_pbl/entrar'));

        return $htmls;
    }

    public function testPrincipaisTelasRespondem(): void
    {
        $htmls = $this->renderizar();
        foreach (['inicio', 'editar', 'configuracoes', 'atualizacao', 'copias', 'contas', 'classes', 'revisar', 'lixeira', 'vencidos', 'entrar'] as $nome) {
            self::assertArrayHasKey($nome, $htmls, "a tela $nome precisa renderizar (200) para ser auditada");
        }
    }

    public function testSemPadraoProibidoNemAjudaOuSubtituloLongos(): void
    {
        $excecoes = require __DIR__ . '/fixtures/texto-excecoes.php';
        $erros = [];
        $auditoria = [];
        foreach ($this->renderizar() as $nome => $html) {
            $visivel = Medidor::textoVisivel($html);
            foreach (self::PROIBIDOS as $p) {
                if (preg_match('/' . $p . '/iu', $visivel) === 1) {
                    $erros[] = "$nome: padrão proibido /$p/";
                }
            }
            $auditoria[$nome] = Medidor::contar($html);
            if (in_array($nome, self::ONDA_2, true)) {
                continue;
            }
            foreach (Medidor::ajudas($html) as ['id' => $id, 'texto' => $texto]) {
                if (mb_strlen($texto) > self::LIMITE_AJUDA && !isset($excecoes[$id])) {
                    $erros[] = "$nome: ajuda '$id' com " . mb_strlen($texto) . ' caracteres (limite ' . self::LIMITE_AJUDA . ')';
                }
            }
            foreach (Medidor::subtitulos($html) as $sub) {
                if ((preg_match_all('/[.!?](\s|$)/u', $sub) > 1 || mb_strlen($sub) > self::LIMITE_SUBTITULO) && !isset($excecoes["subtitulo:$nome"])) {
                    $erros[] = "$nome: subtítulo com mais de uma frase ou > " . self::LIMITE_SUBTITULO . " caracteres: «{$sub}»";
                }
            }
        }
        $saida = getenv('PBL_AUDITORIA_SAIDA');
        if (is_string($saida) && $saida !== '') {
            file_put_contents($saida, json_encode($auditoria, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        self::assertSame([], $erros, "Texto fora do guia (docs/ux/voz-e-texto.md):\n" . implode("\n", $erros));
    }

    public function testExcecoesSemJustificativaSaoRejeitadas(): void
    {
        $excecoes = require __DIR__ . '/fixtures/texto-excecoes.php';
        self::assertIsArray($excecoes);
        foreach ($excecoes as $id => $porque) {
            self::assertGreaterThan(10, mb_strlen(trim((string) $porque)), "exceção '$id' sem justificativa");
        }
    }

    public function testMedidorContaPorCategoria(): void
    {
        $c = Medidor::contar('<main><h1>T</h1><p>Um dois.</p><div class="alert">Três quatro cinco</div><button>Salvar</button></main>');
        self::assertSame(3, $c['aviso']);
        self::assertSame(1, $c['botao']);
        self::assertSame(['Um dois.'], Medidor::subtitulos('<main><h1>T</h1><p>Um dois.</p></main>'));
    }
}

<?php

declare(strict_types=1);

namespace Pbl\Manual;

use Pbl\Busca\Texto;

/**
 * Conversor Markdown restrito (ADR 0040): só o subconjunto que o manual usa. Títulos (# no topo é o título da página e é
 * descartado; ## a ####), parágrafos, listas (aninhadas por recuo), tabelas, bloco de código, citação, régua, e no texto: `código`,
 * **negrito**, *itálico*, [link](destino) e ![alt](destino). HTML cru e qualquer outra construção são ERRO com a linha; nada passa sem
 * escape. Quem resolve destinos de link e de imagem é quem chama (callbacks), para o conversor não conhecer a árvore do manual.
 */
final class ConversorMarkdown
{
    /** @var list<array{nivel: int, texto: string, id: string}> */
    private array $titulos = [];
    /** @var array<string, int> */
    private array $ids = [];
    /** @var list<string> */
    private array $linhas = [];
    private int $linhaBase = 1;
    private string $arquivo = '';
    private int $linhaAtual = 1;

    /**
     * @param \Closure(string $destino, string $arquivo, int $linha): string $link devolve o href final (erro: lança ErroDeManual)
     * @param \Closure(string $alt, string $destino, string $arquivo, int $linha): string $imagem devolve o HTML da figura
     */
    public function __construct(private readonly \Closure $link, private readonly \Closure $imagem)
    {
    }

    /**
     * @return array{html: string, titulos: list<array{nivel: int, texto: string, id: string}>}
     */
    public function converter(string $markdown, string $arquivo, int $linhaBase = 1): array
    {
        $this->titulos = [];
        $this->ids = [];
        $this->arquivo = $arquivo;
        $this->linhaBase = $linhaBase;
        $this->linhas = preg_split('/\R/', rtrim($markdown)) ?: [];
        // Uma figura sozinha não fica dentro de <p>.
        $html = (string) preg_replace('#<p>(<figure>.*?</figure>)</p>#s', '$1', $this->blocos(0, count($this->linhas), true));

        return ['html' => $html, 'titulos' => $this->titulos];
    }

    private function erro(int $indice, string $mensagem): ErroDeManual
    {
        return ErroDeManual::em($this->arquivo, $this->linhaBase + $indice, $mensagem);
    }

    private function blocos(int $de, int $ate, bool $topo): string
    {
        $html = '';
        $paragrafo = [];
        $primeiraNaoVazia = true;
        $descarta = function () use (&$html, &$paragrafo): void {
            if ($paragrafo !== []) {
                $html .= '<p>' . $this->inline(implode(' ', array_map('trim', $paragrafo)), $this->paragrafoIndice) . '</p>';
                $paragrafo = [];
            }
        };
        for ($i = $de; $i < $ate; $i++) {
            $l = $this->linhas[$i];
            $this->linhaAtual = $i;
            if (trim($l) === '') {
                $descarta();
                continue;
            }
            $ehPrimeira = $primeiraNaoVazia;
            $primeiraNaoVazia = false;
            if (preg_match('/^```([a-z0-9]*)\s*$/D', $l, $m) === 1) {
                $descarta();
                $codigo = [];
                for ($i++; $i < $ate && preg_match('/^```\s*$/D', $this->linhas[$i]) !== 1; $i++) {
                    $codigo[] = $this->linhas[$i];
                }
                if ($i >= $ate) {
                    throw $this->erro($this->linhaAtual, 'bloco de código sem fechamento (```).');
                }
                $html .= '<pre><code>' . htmlspecialchars(implode("\n", $codigo), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/D', $l, $m) === 1) {
                $descarta();
                $nivel = strlen($m[1]);
                if ($nivel === 1) {
                    if (!$topo || !$ehPrimeira) {
                        throw $this->erro($i, 'só pode haver um título "#", na primeira linha (o título vem do front matter).');
                    }
                    continue;
                }
                if ($nivel > 4) {
                    throw $this->erro($i, 'use títulos de ## a ####.');
                }
                $html .= $this->titulo($nivel, $m[2], $i);
                continue;
            }
            if (preg_match('/^(---|\*\*\*)\s*$/D', $l) === 1) {
                $descarta();
                $html .= '<hr>';
                continue;
            }
            if (str_starts_with($l, '|')) {
                $descarta();
                $html .= $this->tabela($i, $ate);
                $i = $this->fimDaTabela;
                continue;
            }
            if (str_starts_with($l, '>')) {
                $descarta();
                $texto = [];
                for (; $i < $ate && str_starts_with($this->linhas[$i], '>'); $i++) {
                    $texto[] = ltrim(substr($this->linhas[$i], 1));
                }
                $i--;
                $partes = array_filter(array_map(static fn (string $p): string => trim(str_replace("\n", ' ', $p)), preg_split('/\n\s*\n/', implode("\n", $texto)) ?: []));
                $html .= '<blockquote>' . implode('', array_map(fn (string $p): string => '<p>' . $this->inline($p, $i) . '</p>', $partes)) . '</blockquote>';
                continue;
            }
            if (preg_match('/^(\s*)([-*]|\d+\.)\s+\S/', $l) === 1) {
                $descarta();
                $html .= $this->lista($i, $ate);
                $i = $this->fimDaLista;
                continue;
            }
            $this->paragrafoIndice = $i;
            $paragrafo[] = $l;
        }
        $descarta();

        return $html;
    }

    private int $paragrafoIndice = 0;
    private int $fimDaTabela = 0;
    private int $fimDaLista = 0;

    private function titulo(int $nivel, string $texto, int $i): string
    {
        $plano = trim((string) preg_replace('/[`*]|!?\[([^\]]*)\]\([^)]*\)/', '$1', $texto));
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', Texto::normalizar($plano)), '-');
        $base = $base === '' ? 'secao' : $base;
        $n = ($this->ids[$base] = ($this->ids[$base] ?? 0) + 1);
        $id = $n === 1 ? $base : $base . '-' . $n;
        $this->titulos[] = ['nivel' => $nivel, 'texto' => $plano, 'id' => $id];

        return '<h' . $nivel . '>' . $this->inline($texto, $i) . '</h' . $nivel . '>';
    }

    private function tabela(int $i, int $ate): string
    {
        $cabeca = $this->celulas($this->linhas[$i]);
        $sep = $this->linhas[$i + 1] ?? '';
        if (preg_match('/^\|(\s*:?-+:?\s*\|)+\s*$/D', $sep) !== 1 || count($this->celulas($sep)) !== count($cabeca)) {
            throw $this->erro($i, 'tabela sem linha separadora |---|---| logo abaixo do cabeçalho.');
        }
        $html = '<table><thead><tr>';
        foreach ($cabeca as $c) {
            $html .= '<th scope="col">' . $this->inline($c, $i) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        for ($j = $i + 2; $j < $ate && str_starts_with($this->linhas[$j], '|'); $j++) {
            $cel = $this->celulas($this->linhas[$j]);
            if (count($cel) !== count($cabeca)) {
                throw $this->erro($j, 'a linha da tabela tem número de colunas diferente do cabeçalho.');
            }
            $html .= '<tr>' . implode('', array_map(fn (string $c): string => '<td>' . $this->inline($c, $j) . '</td>', $cel)) . '</tr>';
        }
        $this->fimDaTabela = $j - 1;

        return $html . '</tbody></table>';
    }

    /** @return list<string> */
    private function celulas(string $linha): array
    {
        $linha = trim($linha);
        $linha = substr($linha, 1, str_ends_with($linha, '|') ? -1 : null);
        // `\|` e `|` dentro de `código` não separam colunas.
        $linha = str_replace('\\|', "\x01", $linha);
        $saida = [];
        $atual = '';
        $codigo = false;
        foreach (mb_str_split($linha) as $c) {
            if ($c === '`') {
                $codigo = !$codigo;
            }
            if ($c === '|' && !$codigo) {
                $saida[] = trim(str_replace("\x01", '|', $atual));
                $atual = '';
                continue;
            }
            $atual .= $c;
        }
        $saida[] = trim(str_replace("\x01", '|', $atual));

        return $saida;
    }

    private function lista(int $i, int $ate): string
    {
        preg_match('/^(\s*)([-*]|\d+\.)\s/', $this->linhas[$i], $m);
        $base = strlen($m[1]);
        $ordenada = $m[2] !== '-' && $m[2] !== '*';
        $itens = [];
        $j = $i;
        for (; $j < $ate; $j++) {
            $l = $this->linhas[$j];
            if (trim($l) === '') {
                $prox = $j + 1;
                while ($prox < $ate && trim($this->linhas[$prox]) === '') {
                    $prox++;
                }
                if ($prox < $ate && preg_match('/^(\s*)([-*]|\d+\.)\s+\S/', $this->linhas[$prox], $n) === 1 && strlen($n[1]) >= $base) {
                    $j = $prox - 1;
                    continue;
                }
                break;
            }
            $recuo = strlen($l) - strlen(ltrim($l));
            if ($recuo < $base) {
                break;
            }
            if ($recuo === $base) {
                if (preg_match('/^\s*([-*]|\d+\.)\s+(.*)$/', $l, $n) !== 1) {
                    break;
                }
                if ((($n[1] !== '-' && $n[1] !== '*')) !== $ordenada) {
                    break;
                }
                $itens[] = ['texto' => [$n[2]], 'sub' => [], 'linha' => $j];
            } elseif (preg_match('/^\s*([-*]|\d+\.)\s+\S/', $l) === 1) {
                $itens[count($itens) - 1]['sub'][] = $j;
            } else {
                $itens[count($itens) - 1]['texto'][] = trim($l);
            }
        }
        $this->fimDaLista = $j - 1;
        $tag = $ordenada ? 'ol' : 'ul';
        $html = '<' . $tag . '>';
        foreach ($itens as $item) {
            $html .= '<li>' . $this->inline(implode(' ', $item['texto']), $item['linha']);
            if ($item['sub'] !== []) {
                $html .= $this->lista($item['sub'][0], $item['sub'][array_key_last($item['sub'])] + 1);
            }
            $html .= '</li>';
        }

        return $html . '</' . $tag . '>';
    }

    private function inline(string $texto, int $i): string
    {
        $codigos = [];
        $texto = (string) preg_replace_callback('/(`+)(.+?)\1/s', static function (array $m) use (&$codigos): string {
            $codigos[] = '<code>' . htmlspecialchars(trim($m[2]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';

            return "\x00" . (count($codigos) - 1) . "\x00";
        }, $texto);
        // Endereço entre < > (autolink) é Markdown, não HTML.
        $texto = (string) preg_replace_callback('#<(https://[^\s<>"\']+)>#', function (array $m) use (&$codigos, $i): string {
            $href = ($this->link)($m[1], $this->arquivo, $this->linhaBase + $i);
            $codigos[] = '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . htmlspecialchars($m[1], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';

            return "\x00" . (count($codigos) - 1) . "\x00";
        }, $texto);
        if (preg_match('/<\s*\/?\s*[a-zA-Z!?]/', $texto) === 1) {
            throw $this->erro($i, 'HTML cru não é aceito no manual (use Markdown; para mostrar um sinal < use `código`).');
        }
        $texto = htmlspecialchars($texto, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $texto = (string) preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', fn (array $m): string => ($this->imagem)(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), $m[2], $this->arquivo, $this->linhaBase + $i), $texto);
        $texto = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function (array $m) use ($i): string {
            $href = ($this->link)(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'), $this->arquivo, $this->linhaBase + $i);

            return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $m[1] . '</a>';
        }, $texto);
        $texto = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/', '<strong>$1</strong>', $texto);
        $texto = (string) preg_replace('/(?<![*\w])\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?![*\w])/', '<em>$1</em>', $texto);
        $texto = (string) preg_replace_callback('/\x00(\d+)\x00/', static fn (array $m): string => $codigos[(int) $m[1]], $texto);

        return $texto;
    }
}

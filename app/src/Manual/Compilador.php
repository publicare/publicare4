<?php

declare(strict_types=1);

namespace Pbl\Manual;

/**
 * Compila `docs/usuario` num pacote determinístico (ADR 0041), sem banco e sem relógio: valida o front matter e a árvore
 * (capítulos de `capitulos.md`), converte o Markdown, resolve links entre guias e capturas, calcula pesos (10, 20, 30…),
 * tabela de conteúdo e anterior/próximo. Falha com TODOS os problemas de uma vez (um por linha) em vez de parar no primeiro.
 *
 * O HTML sai com marcadores que a carga troca por edição: `~ed~` (prefixo da edição: `/`, `/beta/`, `/<versão>/`) e `~img~`
 * (pasta das capturas da edição). Nada na saída tem data.
 */
final class Compilador
{
    public const ED = '~ed~';
    public const IMG = '~img~';
    /** Capítulo das páginas de entrada: uma por público (slug = público) e a "Quem é você?". */
    public const CAPITULO_TRILHAS = 'trilhas';
    public const QUEM_E_VOCE = 'quem-e-voce';
    /** Arquivos de docs/usuario que não são páginas do manual. */
    private const FORA_DO_MANUAL = ['README.md', 'capitulos.md', 'INVENTARIO.md'];

    /** @var list<string> */
    private array $problemas = [];
    /** @var array<string, array<string, mixed>> arquivo => página */
    private array $paginas = [];
    /** @var list<array{origem: string, destino: string, linha: int, ancora: string, de: string}> */
    private array $ancoras = [];
    /** @var array<string, array<string, mixed>> */
    private array $capturas = [];
    /** @var array<string, true> */
    private array $capturasUsadas = [];
    /** @var array<string, true> */
    private array $capturasFaltando = [];
    /** @var array<string, array<string, true>> arquivo => (id de âncora => true) */
    private array $idsPorArquivo = [];
    /** @var list<array{slug: string, titulo: string, resumo: string}> */
    private array $capitulosLidos = [];

    public function __construct(
        private readonly string $docs,
        private readonly string $linksExternos,
        private readonly ?string $capturasDir = null,
        private readonly bool $semCapturas = false,
    ) {
    }

    /** @return array<string, mixed> o pacote (manual.json) mais as capturas copiáveis em `arquivos` */
    public function compilar(): array
    {
        $capitulos = $this->capitulos();
        $this->capitulosLidos = $capitulos;
        $this->capturas = $this->lerCapturas();
        $this->lerPaginas($capitulos);
        $conversor = new ConversorMarkdown($this->resolverLink(...), $this->resolverImagem(...));
        foreach ($this->paginas as $arquivo => &$p) {
            try {
                $r = $conversor->converter($p['corpo'], $arquivo, $p['linhaDoCorpo']);
                $extra = $this->indiceGerado($p);
                $p['html'] = $r['html'] . $extra['html'];
                $p['titulos'] = array_merge($r['titulos'], $extra['titulos']);
                $this->idsPorArquivo[$arquivo] = array_fill_keys(array_column($r['titulos'], 'id'), true);
            } catch (ErroDeManual $e) {
                $this->problemas[] = $e->getMessage();
            }
        }
        unset($p);
        $this->conferirTrilhas();
        $this->conferirAncoras();
        foreach (array_diff(array_keys($this->capturas), array_keys($this->capturasUsadas)) as $id) {
            $this->problemas[] = "capturas.json: a captura \"{$id}\" não é usada por nenhum guia.";
        }
        if ($this->capturasFaltando !== [] && !$this->semCapturas) {
            $this->problemas[] = 'faltam as capturas de tela: ' . implode(', ', array_keys($this->capturasFaltando)) . ' (gere-as ou use --sem-capturas no desenvolvimento).';
        }
        if ($this->problemas !== []) {
            throw new ErroDeManual("O manual não compilou:\n- " . implode("\n- ", array_unique($this->problemas)));
        }

        return $this->montar($capitulos);
    }

    /** @return list<string> avisos que não impedem a compilação */
    public function avisos(): array
    {
        return $this->semCapturas && $this->capturasFaltando !== []
            ? ['capturas ausentes substituídas por quadro de texto: ' . implode(', ', array_keys($this->capturasFaltando))]
            : [];
    }

    /** @return list<array{slug: string, titulo: string, resumo: string}> */
    private function capitulos(): array
    {
        $arquivo = $this->docs . '/capitulos.md';
        if (!is_file($arquivo)) {
            throw ErroDeManual::em('docs/usuario/capitulos.md', null, 'arquivo não encontrado.');
        }
        $fm = FrontMatter::ler((string) file_get_contents($arquivo), 'capitulos.md');
        $lista = [];
        foreach (preg_split('/\r\n|\n|\r/', $fm['corpo']) ?: [] as $n => $l) {
            if (!str_starts_with($l, '|') || preg_match('/^\|\s*(-|slug)/i', $l) === 1) {
                continue;
            }
            $c = array_map('trim', explode('|', trim($l, " |\t")));
            if (count($c) !== 3 || preg_match(FrontMatter::SLUG, $c[0]) !== 1 || $c[1] === '' || $c[2] === '' || mb_strlen($c[2]) > FrontMatter::MAX_RESUMO) {
                throw ErroDeManual::em('capitulos.md', $fm['linhaDoCorpo'] + $n, 'use a linha "| slug | Título | Resumo |".');
            }
            if (isset($lista[$c[0]])) {
                throw ErroDeManual::em('capitulos.md', $fm['linhaDoCorpo'] + $n, "capítulo repetido \"{$c[0]}\".");
            }
            $lista[$c[0]] = ['slug' => $c[0], 'titulo' => $c[1], 'resumo' => $c[2]];
        }
        if ($lista === []) {
            throw ErroDeManual::em('capitulos.md', null, 'nenhum capítulo declarado.');
        }

        return array_values($lista);
    }

    /** @return array<string, array<string, mixed>> */
    private function lerCapturas(): array
    {
        $arquivo = $this->docs . '/capturas.json';
        if (!is_file($arquivo)) {
            return [];
        }
        $lido = json_decode((string) file_get_contents($arquivo), true);
        if (!is_array($lido) || !isset($lido['capturas']) || !is_array($lido['capturas'])) {
            throw ErroDeManual::em('capturas.json', null, 'JSON inválido (esperado {"capturas": [...]}).');
        }
        $saida = [];
        foreach ($lido['capturas'] as $c) {
            $id = is_array($c) ? (string) ($c['id'] ?? '') : '';
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $id) !== 1 || isset($saida[$id])) {
                throw ErroDeManual::em('capturas.json', null, "id de captura inválido ou repetido: \"{$id}\".");
            }
            foreach (['pagina_do_painel', 'papel', 'alt_padrao'] as $k) {
                if (!is_string($c[$k] ?? null) || $c[$k] === '') {
                    throw ErroDeManual::em('capturas.json', null, "captura \"{$id}\": falta \"{$k}\".");
                }
            }
            $saida[$id] = $c;
        }

        return $saida;
    }

    /** @param list<array{slug: string, titulo: string, resumo: string}> $capitulos */
    private function lerPaginas(array $capitulos): void
    {
        $slugs = array_column($capitulos, 'slug');
        $ordens = [];
        $arquivos = glob($this->docs . '/*.md') ?: [];
        sort($arquivos);
        foreach ($arquivos as $caminho) {
            $nome = basename($caminho);
            if (in_array($nome, self::FORA_DO_MANUAL, true)) {
                continue;
            }
            try {
                $fm = FrontMatter::ler((string) file_get_contents($caminho), $nome);
                $v = FrontMatter::validarPagina($fm['meta'], $nome, basename($nome, '.md'));
                if (!in_array($v['capitulo'], $slugs, true)) {
                    throw ErroDeManual::em($nome, 1, "o capítulo \"{$v['capitulo']}\" não está em capitulos.md (página órfã).");
                }
                $chave = $v['capitulo'] . '#' . $v['ordem'];
                if (isset($ordens[$chave])) {
                    throw ErroDeManual::em($nome, 1, "\"ordem: {$v['ordem']}\" já é de {$ordens[$chave]} neste capítulo.");
                }
                $ordens[$chave] = $nome;
                $chaveSlug = $v['capitulo'] . '/' . $v['slug'];
                if (in_array($chaveSlug, array_column($this->paginas, 'chave'), true)) {
                    throw ErroDeManual::em($nome, 1, "o endereço \"{$chaveSlug}\" já é de outro guia.");
                }
                $this->paginas[$nome] = $v + ['corpo' => $fm['corpo'], 'linhaDoCorpo' => $fm['linhaDoCorpo'], 'chave' => $v['capitulo'] . '/' . $v['slug'], 'hash_origem' => hash('sha256', (string) file_get_contents($caminho)), 'arquivo' => $nome];
            } catch (ErroDeManual $e) {
                $this->problemas[] = $e->getMessage();
            }
        }
        foreach ($slugs as $s) {
            if (!in_array($s, array_column($this->paginas, 'capitulo'), true)) {
                $this->problemas[] = "capitulos.md: o capítulo \"{$s}\" não tem nenhuma página.";
            }
        }
    }

    /** @param array<string, mixed> $p */
    private function ehEntradaDeTrilha(array $p): bool
    {
        return $p['capitulo'] === self::CAPITULO_TRILHAS && in_array($p['slug'], [...FrontMatter::PUBLICOS, self::QUEM_E_VOCE], true);
    }

    /** @return ?array<string, mixed> a página `trilhas/<slug>` */
    private function entrada(string $slug): ?array
    {
        foreach ($this->paginas as $p) {
            if ($p['chave'] === self::CAPITULO_TRILHAS . '/' . $slug) {
                return $p;
            }
        }

        return null;
    }

    /**
     * As páginas de um público, na ordem do manual (capítulo e depois `ordem`); a página aparece em cada público que declara, sem copiar texto.
     *
     * @return list<array<string, mixed>>
     */
    private function paginasDoPublico(string $publico): array
    {
        $ordemDoCapitulo = array_flip(array_column($this->capitulosLidos, 'slug'));
        $lista = array_values(array_filter($this->paginas, fn (array $p): bool => $p['capitulo'] !== self::CAPITULO_TRILHAS && in_array($publico, $p['publico'], true)));
        usort($lista, static fn (array $a, array $b): int => [$ordemDoCapitulo[$a['capitulo']], $a['ordem']] <=> [$ordemDoCapitulo[$b['capitulo']], $b['ordem']]);

        return $lista;
    }

    /**
     * O índice que a página de entrada ganha no fim do texto: "Nesta trilha" (páginas do público por capítulo) ou, na "Quem é você?", a lista das trilhas.
     *
     * @param array<string, mixed> $p
     * @return array{html: string, titulos: list<array{nivel: int, texto: string, id: string}>}
     */
    private function indiceGerado(array $p): array
    {
        $h = static fn (string $t): string => htmlspecialchars($t, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $item = static fn (array $q): string => '<li><a href="' . self::ED . $q['chave'] . '/">' . $h((string) $q['titulo']) . '</a>: ' . $h((string) $q['resumo']) . '</li>';
        if (!$this->ehEntradaDeTrilha($p)) {
            return ['html' => '', 'titulos' => []];
        }
        if ($p['slug'] === self::QUEM_E_VOCE) {
            $itens = '';
            foreach (FrontMatter::PUBLICOS as $pub) {
                $e = $this->entrada($pub);
                $itens .= $e !== null ? $item($e) : '';
            }

            return ['html' => "\n" . '<h2 id="as-trilhas">As trilhas</h2>' . "\n<ul>{$itens}</ul>\n", 'titulos' => [['nivel' => 2, 'texto' => 'As trilhas', 'id' => 'as-trilhas']]];
        }
        $html = "\n" . '<h2 id="nesta-trilha">Nesta trilha</h2>' . "\n";
        $titulos = [['nivel' => 2, 'texto' => 'Nesta trilha', 'id' => 'nesta-trilha']];
        $porCapitulo = [];
        foreach ($this->paginasDoPublico((string) $p['slug']) as $q) {
            $porCapitulo[$q['capitulo']][] = $q;
        }
        foreach ($this->capitulosLidos as $c) {
            if (!isset($porCapitulo[$c['slug']])) {
                continue;
            }
            $id = 'trilha-' . $c['slug'];
            $html .= '<h3 id="' . $id . '">' . $h($c['titulo']) . "</h3>\n<ul>" . implode('', array_map($item, $porCapitulo[$c['slug']])) . "</ul>\n";
            $titulos[] = ['nivel' => 3, 'texto' => $c['titulo'], 'id' => $id];
        }

        return ['html' => $html, 'titulos' => $titulos];
    }

    /** Cada público tem a sua página de entrada (`trilhas/<público>`, que declara o próprio público) e pelo menos uma página; a "Quem é você?" existe. */
    private function conferirTrilhas(): void
    {
        foreach ([self::QUEM_E_VOCE, ...FrontMatter::PUBLICOS] as $slug) {
            $e = $this->entrada($slug);
            if ($e === null) {
                $this->problemas[] = "trilhas: falta a página de entrada \"{$slug}\" (capitulo: " . self::CAPITULO_TRILHAS . ", slug: {$slug}).";
                continue;
            }
            if ($slug !== self::QUEM_E_VOCE && !in_array($slug, $e['publico'], true)) {
                $this->problemas[] = "{$e['arquivo']}:1: a entrada da trilha \"{$slug}\" precisa declarar \"publico: {$slug}\".";
            }
            if ($slug !== self::QUEM_E_VOCE && $this->paginasDoPublico($slug) === []) {
                $this->problemas[] = "trilhas: a trilha \"{$slug}\" não tem nenhuma página.";
            }
        }
    }

    /** @return list<array{slug: string, titulo: string, resumo: string, entrada: string, paginas: list<string>}> */
    private function trilhas(): array
    {
        $saida = [];
        foreach (FrontMatter::PUBLICOS as $pub) {
            $e = $this->entrada($pub);
            if ($e === null) {
                continue;
            }
            $saida[] = ['slug' => $pub, 'titulo' => (string) $e['titulo'], 'resumo' => (string) $e['resumo'], 'entrada' => (string) $e['chave'],
                'paginas' => array_map(static fn (array $q): string => (string) $q['chave'], $this->paginasDoPublico($pub))];
        }

        return $saida;
    }

    private function resolverLink(string $destino, string $arquivo, int $linha): string
    {
        if (preg_match('#^https?://[^\s]+$#i', $destino) === 1) {
            return $destino;
        }
        $ancora = '';
        $caminho = $destino;
        if (($p = strpos($destino, '#')) !== false) {
            $caminho = substr($destino, 0, $p);
            $ancora = substr($destino, $p + 1);
        }
        if ($caminho === '') {
            $this->ancoras[] = ['origem' => $arquivo, 'destino' => $arquivo, 'linha' => $linha, 'ancora' => $ancora, 'de' => $arquivo];

            return '#' . $ancora;
        }
        if (str_starts_with($caminho, '../')) {
            $externo = $this->enderecoExterno($caminho);
            if ($externo === null || !is_file($this->docs . '/' . $caminho)) {
                throw ErroDeManual::em($arquivo, $linha, "link para fora do manual não permitido ou inexistente: {$caminho}");
            }

            return $externo . ($ancora !== '' ? '#' . $ancora : '');
        }
        $alvo = $this->paginas[$caminho] ?? null;
        if ($alvo === null || !str_ends_with($caminho, '.md')) {
            throw ErroDeManual::em($arquivo, $linha, "link quebrado: {$destino}");
        }
        if ($ancora !== '') {
            $this->ancoras[] = ['origem' => $arquivo, 'destino' => $caminho, 'linha' => $linha, 'ancora' => $ancora, 'de' => $arquivo];
        }

        return self::ED . $alvo['chave'] . '/' . ($ancora !== '' ? '#' . $ancora : '');
    }

    /** O endereço público de um arquivo fora de docs/usuario, só para os prefixos de `manual/links-externos.json`. */
    private function enderecoExterno(string $caminho): ?string
    {
        $mapa = is_file($this->linksExternos) ? json_decode((string) file_get_contents($this->linksExternos), true) : null;
        foreach (is_array($mapa) ? ($mapa['prefixos'] ?? []) : [] as $prefixo => $base) {
            if (str_starts_with($caminho, (string) $prefixo) && is_string($base) && str_starts_with($base, 'https://')) {
                return $base . substr($caminho, strlen((string) $prefixo));
            }
        }

        return null;
    }

    private function resolverImagem(string $alt, string $destino, string $arquivo, int $linha): string
    {
        if (trim($alt) === '') {
            throw ErroDeManual::em($arquivo, $linha, 'toda imagem precisa de texto alternativo: ![descrição](captura:id).');
        }
        if (preg_match('/^captura:([a-z0-9]+(?:-[a-z0-9]+)*)$/D', $destino, $m) !== 1) {
            throw ErroDeManual::em($arquivo, $linha, 'imagens entram só por captura automática: ![descrição](captura:id); não há arquivo fixo no manual.');
        }
        $id = $m[1];
        if (!isset($this->capturas[$id])) {
            throw ErroDeManual::em($arquivo, $linha, "captura \"{$id}\" não declarada em capturas.json.");
        }
        $this->capturasUsadas[$id] = true;
        $a = htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $png = $this->capturasDir !== null ? $this->capturasDir . '/' . $id . '.png' : null;
        $tam = $png !== null && is_file($png) ? @getimagesize($png) : false;
        if ($tam === false) {
            $this->capturasFaltando[$id] = true;

            return '<figure><figcaption>' . $a . ' (captura de tela indisponível nesta geração)</figcaption></figure>';
        }

        return '<figure><img src="' . self::IMG . '/' . $id . '.png" alt="' . $a . '" width="' . $tam[0] . '" height="' . $tam[1] . '"><figcaption>' . $a . '</figcaption></figure>';
    }

    private function conferirAncoras(): void
    {
        foreach ($this->ancoras as $a) {
            if (!isset($this->idsPorArquivo[$a['destino']][$a['ancora']]) && isset($this->idsPorArquivo[$a['destino']])) {
                $this->problemas[] = "{$a['origem']}:{$a['linha']}: a âncora #{$a['ancora']} não existe em {$a['destino']}.";
            }
        }
    }

    /**
     * @param list<array{slug: string, titulo: string, resumo: string}> $capitulos
     * @return array<string, mixed>
     */
    private function montar(array $capitulos): array
    {
        $saida = [];
        $plano = [];
        foreach ($capitulos as $i => $c) {
            $doCapitulo = array_values(array_filter($this->paginas, static fn (array $p): bool => $p['capitulo'] === $c['slug']));
            usort($doCapitulo, static fn (array $a, array $b): int => $a['ordem'] <=> $b['ordem']);
            $saida[$i] = $c + ['peso' => ($i + 1) * 10, 'paginas' => []];
            foreach ($doCapitulo as $j => $p) {
                $plano[] = [$i, $j, $p];
                $saida[$i]['paginas'][$j] = null;
            }
        }
        foreach ($plano as $k => [$i, $j, $p]) {
            $anterior = $plano[$k - 1][2] ?? null;
            $proximo = $plano[$k + 1][2] ?? null;
            $saida[$i]['paginas'][$j] = [
                'slug' => $p['slug'], 'titulo' => $p['titulo'], 'resumo' => $p['resumo'], 'peso' => ($j + 1) * 10,
                'origem' => 'docs/usuario/' . $p['arquivo'], 'html' => $p['html'], 'toc' => self::toc($p['titulos']),
                'navegacao' => self::navegacao($anterior, $proximo), 'hash_origem' => $p['hash_origem'], 'publico' => $p['publico'],
            ];
        }

        return ['formato' => 2, 'trilhas' => $this->trilhas(), 'capitulos' => $saida, 'capturas' => array_keys($this->capturasUsadas), 'avisos' => $this->avisos()];
    }

    /** @param list<array{nivel: int, texto: string, id: string}> $titulos */
    public static function toc(array $titulos): string
    {
        $itens = array_values(array_filter($titulos, static fn (array $t): bool => $t['nivel'] <= 3));
        if (count($itens) < 2) {
            return '';
        }
        $html = '<ul>';
        $aberto = false;
        foreach ($itens as $t) {
            $a = '<a href="#' . $t['id'] . '">' . htmlspecialchars($t['texto'], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
            if ($t['nivel'] === 2) {
                $html .= ($aberto ? '</li>' : '') . '<li>' . $a;
                $aberto = true;
            } else {
                $html .= '<ul><li>' . $a . '</li></ul>';
            }
        }

        return $html . ($aberto ? '</li>' : '') . '</ul>';
    }

    /**
     * @param array<string, mixed>|null $anterior
     * @param array<string, mixed>|null $proximo
     */
    private static function navegacao(?array $anterior, ?array $proximo): string
    {
        $l = static fn (array $p, string $rotulo): string => '<li><a href="' . self::ED . $p['chave'] . '/">' . $rotulo . ': ' . htmlspecialchars((string) $p['titulo'], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></li>';

        return '<ul>' . ($anterior !== null ? $l($anterior, 'Anterior') : '') . ($proximo !== null ? $l($proximo, 'Próximo') : '') . '</ul>';
    }

    /** O JSON do pacote: ordem fixa de chaves e UTF-8, para a geração ser repetível byte a byte. */
    /** @param array<string, mixed> $pacote */
    public static function emJson(array $pacote): string
    {
        return json_encode($pacote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    }
}

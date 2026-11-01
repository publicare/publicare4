<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/**
 * Compila um molde (com herança de layout) e guarda o PHP gerado em `dados/cache/pbl/<hash>.php`.
 * O hash cobre o molde e toda a cadeia de layouts: mudou um, recompila (fluxo 2).
 */
final class Compilador
{
    private const MAX_HERANCA = 5;
    /** Sobe quando o gerador ou o runtime mudam o formato do PHP gerado: invalida todo o cache compilado. */
    public const FORMATO = 1;

    public function __construct(
        private readonly string $pastaCache,
        private readonly Parser $parser = new Parser(),
        private readonly Gerador $gerador = new Gerador(),
        private readonly int $formato = self::FORMATO,
    ) {
    }

    /** Valida e gera o PHP sem gravar nem executar (usado pelo editor de peles no futuro). */
    public function compilar(string $fonte, ?Resolvedor $resolvedor = null): string
    {
        return $this->gerador->gerar($this->resolver($fonte, $resolvedor, 0));
    }

    /** @return \Closure(array<string, mixed>, Runtime): string */
    public function carregar(string $fonte, ?Resolvedor $resolvedor = null): \Closure
    {
        $chave = hash('sha256', 'pbl4-formato-' . $this->formato . "\0" . implode("\0", $this->cadeia($fonte, $resolvedor)));
        $arquivo = $this->pastaCache . '/' . $chave . '.php';
        if (!is_file($arquivo)) {
            $this->gravarAtomico($arquivo, $this->compilar($fonte, $resolvedor));
        }
        $funcao = require $arquivo;
        if (!$funcao instanceof \Closure) {
            throw new \RuntimeException("Cache de molde corrompido: {$arquivo}");
        }

        /** @var \Closure(array<string, mixed>, Runtime): string $funcao */
        return $funcao;
    }

    /**
     * Fontes do molde e de seus layouts, do filho para a raiz.
     *
     * @return list<string>
     */
    private function cadeia(string $fonte, ?Resolvedor $resolvedor): array
    {
        $fontes = [$fonte];
        while (preg_match('/^\s*<@\s*estende\s+(["\'])([^"\']+)\1\s*@>/', $fontes[count($fontes) - 1], $m) === 1) {
            if ($resolvedor === null || count($fontes) > self::MAX_HERANCA) {
                break;
            }
            $fontes[] = $resolvedor->fonte($m[2]);
        }

        return $fontes;
    }

    /** @return list<No> árvore sem `estende`: blocos do filho já aplicados sobre o layout */
    private function resolver(string $fonte, ?Resolvedor $resolvedor, int $nivel, ?string $nome = null): array
    {
        try {
            $arvore = $this->parser->analisar($fonte);
        } catch (ErroSintaxe $e) {
            throw $nome === null ? $e : $e->noArquivo($nome);
        }

        $estende = null;
        foreach ($arvore as $no) {
            if ($no instanceof NoEstende) {
                $estende = $no;
                break;
            }
            if (!$no instanceof NoTexto || trim($no->valor) !== '') {
                break;
            }
        }
        if ($estende === null) {
            return $arvore;
        }
        if ($resolvedor === null) {
            throw new ErroSintaxe('estende não está disponível sem uma pele', $estende->linha);
        }
        if ($nivel >= self::MAX_HERANCA) {
            throw new ErroSintaxe('Herança de layout profunda demais (ciclo?)', $estende->linha);
        }

        $layout = $this->resolver($resolvedor->fonte($estende->layout), $resolvedor, $nivel + 1, $estende->layout);

        return $this->aplicarBlocos($layout, $this->blocosDoFilho($arvore));
    }

    /**
     * @param list<No> $arvore
     * @return array<string, list<No>>
     */
    private function blocosDoFilho(array $arvore): array
    {
        $this->exigirBlocosUnicos($arvore, []);
        $blocos = [];
        foreach ($arvore as $no) {
            if ($no instanceof NoBloco) {
                if (isset($blocos[$no->nome])) {
                    throw new ErroSintaxe("Bloco repetido: {$no->nome}", $no->linha);
                }
                $blocos[$no->nome] = $no->corpo;
            } elseif ($no instanceof NoTexto && trim($no->valor) === '') {
                continue;
            } elseif (!$no instanceof NoEstende) {
                throw new ErroSintaxe('Em um molde que estende um layout, o conteúdo precisa estar dentro de blocos', 1);
            }
        }

        return $blocos;
    }

    /**
     * Um nome de bloco só pode aparecer uma vez no molde filho, inclusive aninhado (`bloco a` dentro de `bloco a`
     * faria a substituição se repetir sem fim).
     *
     * @param list<No> $nos
     * @param array<string, true> $vistos
     */
    private function exigirBlocosUnicos(array $nos, array $vistos): void
    {
        foreach ($nos as $no) {
            if ($no instanceof NoBloco) {
                if (isset($vistos[$no->nome])) {
                    throw new ErroSintaxe("Bloco repetido: {$no->nome}", $no->linha);
                }
                $vistos[$no->nome] = true;
                $this->exigirBlocosUnicos($no->corpo, $vistos);
            } elseif ($no instanceof NoSe) {
                $this->exigirBlocosUnicos(array_merge($no->entao, $no->senao), $vistos);
            } elseif ($no instanceof NoRepetir || $no instanceof NoColecao || $no instanceof NoSemResultado || $no instanceof NoUsarObjeto || $no instanceof NoTemFilho) {
                $this->exigirBlocosUnicos($no->corpo, $vistos);
            }
        }
    }

    /**
     * @param list<No> $nos
     * @param array<string, list<No>> $blocos
     * @return list<No>
     */
    private function aplicarBlocos(array $nos, array $blocos): array
    {
        $saida = [];
        foreach ($nos as $no) {
            if ($no instanceof NoBloco) {
                $corpo = $blocos[$no->nome] ?? $no->corpo;
                $saida[] = new NoBloco($no->nome, $this->aplicarBlocos($corpo, $blocos), $no->linha);
            } elseif ($no instanceof NoSe) {
                $saida[] = new NoSe($no->condicao, $this->aplicarBlocos($no->entao, $blocos), $this->aplicarBlocos($no->senao, $blocos));
            } elseif ($no instanceof NoRepetir) {
                $saida[] = new NoRepetir($no->variavel, $no->inicio, $no->fim, $this->aplicarBlocos($no->corpo, $blocos));
            } elseif ($no instanceof NoColecao) {
                $saida[] = new NoColecao($no->tipo, $no->params, $no->aleatorio, $this->aplicarBlocos($no->corpo, $blocos));
            } elseif ($no instanceof NoUsarObjeto) {
                $saida[] = new NoUsarObjeto($no->params, $this->aplicarBlocos($no->corpo, $blocos));
            } elseif ($no instanceof NoTemFilho) {
                $saida[] = new NoTemFilho($no->params, $this->aplicarBlocos($no->corpo, $blocos));
            } elseif ($no instanceof NoSemResultado) {
                $saida[] = new NoSemResultado($this->aplicarBlocos($no->corpo, $blocos));
            } else {
                $saida[] = $no;
            }
        }

        return $saida;
    }

    private function gravarAtomico(string $arquivo, string $conteudo): void
    {
        if (!is_dir($this->pastaCache) && !mkdir($this->pastaCache, 0775, true) && !is_dir($this->pastaCache)) {
            throw new \RuntimeException("Não foi possível criar {$this->pastaCache}");
        }
        $temporario = $arquivo . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($temporario, $conteudo);
        rename($temporario, $arquivo);
    }
}

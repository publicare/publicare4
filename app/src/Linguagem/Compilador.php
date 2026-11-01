<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** Compila uma vez: o PHP gerado é gravado em `dados/cache/pbl/<hash>.php` e reaproveitado (fluxo 2). */
final class Compilador
{
    public function __construct(
        private readonly string $pastaCache,
        private readonly Parser $parser = new Parser(),
        private readonly Gerador $gerador = new Gerador(),
    ) {
    }

    /** Valida sem executar nem gravar (usado pelo editor de peles no futuro). */
    public function compilar(string $fonte): string
    {
        return $this->gerador->gerar($this->parser->analisar($fonte));
    }

    /** @return \Closure(array<string, mixed>, Runtime): string */
    public function carregar(string $fonte): \Closure
    {
        $arquivo = $this->pastaCache . '/' . hash('sha256', $fonte) . '.php';
        if (!is_file($arquivo)) {
            $this->gravarAtomico($arquivo, $this->compilar($fonte));
        }
        $funcao = require $arquivo;
        if (!$funcao instanceof \Closure) {
            throw new \RuntimeException("Cache de molde corrompido: {$arquivo}");
        }

        /** @var \Closure(array<string, mixed>, Runtime): string $funcao */
        return $funcao;
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

<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

/** Lê de arquivos do próprio servidor: atualização offline (um pacote copiado por FTP) e testes. A assinatura continua sendo exigida. */
final class BaixadorLocal implements Baixador
{
    public function obter(string $origem, int $maximoBytes): string
    {
        $arquivo = $this->arquivo($origem, $maximoBytes);

        return (string) file_get_contents($arquivo);
    }

    public function copiar(string $origem, string $arquivoDestino, int $maximoBytes): void
    {
        $arquivo = $this->arquivo($origem, $maximoBytes);
        if (!@copy($arquivo, $arquivoDestino)) {
            throw new ErroDeAtualizacao('Não foi possível copiar o pacote');
        }
    }

    public function resolver(string $base, string $relativo): string
    {
        if (str_contains($relativo, '..') || str_contains($relativo, "\0") || str_contains($relativo, '://')) {
            throw new ErroDeAtualizacao('Endereço do pacote inválido');
        }

        return str_starts_with($relativo, '/') ? $relativo : dirname($base) . '/' . $relativo;
    }

    private function arquivo(string $origem, int $maximoBytes): string
    {
        $real = realpath($origem);
        if ($real === false || !is_file($real) || is_link($origem)) {
            throw new ErroDeAtualizacao("Arquivo não encontrado: {$origem}");
        }
        if (filesize($real) > $maximoBytes) {
            throw new ErroDeAtualizacao('O arquivo é maior que o esperado');
        }

        return $real;
    }
}

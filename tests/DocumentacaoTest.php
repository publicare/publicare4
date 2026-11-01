<?php

declare(strict_types=1);

namespace Pbl\Testes;

use PHPUnit\Framework\TestCase;

/** Documentação não pode ter link relativo quebrado nem vazar nome de cliente/órgão (repositório público). */
final class DocumentacaoTest extends TestCase
{
    /** @return list<string> */
    private function markdowns(): array
    {
        $raiz = dirname(__DIR__);
        $arquivos = glob($raiz . '/*.md') ?: [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz . '/docs', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'md') {
                $arquivos[] = $f->getPathname();
            }
        }
        foreach (glob($raiz . '/.github/*.md') ?: [] as $f) {
            $arquivos[] = $f;
        }
        sort($arquivos);

        return $arquivos;
    }

    public function testLinksRelativosApontamParaArquivosQueExistem(): void
    {
        $quebrados = [];
        foreach ($this->markdowns() as $arquivo) {
            $texto = (string) file_get_contents($arquivo);
            $texto = (string) preg_replace('/```.*?```/s', '', $texto);
            preg_match_all('/\]\(([^)\s]+)\)/', $texto, $m);
            foreach ($m[1] as $alvo) {
                if (preg_match('#^(https?:|mailto:|\#)#', $alvo) === 1) {
                    continue;
                }
                $caminho = explode('#', $alvo)[0];
                if ($caminho === '' || str_contains($caminho, '*')) {
                    continue;
                }
                $absoluto = realpath(dirname($arquivo) . '/' . $caminho);
                if ($absoluto === false) {
                    $quebrados[] = substr($arquivo, strlen(dirname(__DIR__)) + 1) . " → {$alvo}";
                }
            }
        }

        self::assertSame([], $quebrados, "links quebrados:\n" . implode("\n", $quebrados));
    }

    public function testDocumentacaoPublicaNaoCitaClientesNemOrgaos(): void
    {
        $proibidos = ['antt', 'mcti', 'epl-', 'participamais', 'pousada', 'casa do cerrado', 'tao do cerrado', 'jornal da biosfera'];
        $achados = [];
        foreach ($this->markdowns() as $arquivo) {
            $texto = mb_strtolower((string) file_get_contents($arquivo));
            foreach ($proibidos as $termo) {
                if (str_contains($texto, $termo)) {
                    $achados[] = substr($arquivo, strlen(dirname(__DIR__)) + 1) . " cita \"{$termo}\"";
                }
            }
        }

        self::assertSame([], $achados, implode("\n", $achados));
    }
}

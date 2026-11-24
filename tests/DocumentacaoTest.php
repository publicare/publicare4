<?php

declare(strict_types=1);

namespace Pbl\Testes;

use PHPUnit\Framework\TestCase;

/** Documentação não pode ter link relativo quebrado nem vazar nome de cliente/órgão (repositório público). */
final class DocumentacaoTest extends TestCase
{
    /**
     * Arquivos fora da regra "MySQL/PostgreSQL ainda não funcionam" (caminhos a partir da raiz). Cada um com o motivo.
     */
    private const SEM_REGRA_MULTIBANCO = [
        // O ADR descreve justamente a meta do multi-banco.
        'docs/adr/0028-suporte-mysql-postgresql.md',
        // O plano traz os projetos do multi-banco.
        'docs/plano.md',
        // Histórico datado de versões já publicadas; não se reescreve.
        'CHANGELOG.md',
        // Aqui MySQL/PostgreSQL são o banco de ORIGEM do PBL3, não suporte do produto.
        'docs/tecnica/migrar-do-pbl3.md',
        'docs/tecnica/cli.md',
        'docs/adr/0027-importador-pbl3.md',
    ];

    /** Uma linha que cita MySQL/PostgreSQL precisa trazer um destes qualificadores (futuro, PBL3, ADR 0028…). */
    private const QUALIFICADOR_MULTIBANCO = '/0\.2|0028|previst|planejad|futur|ainda não|não suport|hoje não|meta|pbl3|proposto/iu';

    /**
     * Regra lexical simples: toda linha que cita MySQL, PostgreSQL (ou Postgres, pgsql, MariaDB) deve conter um
     * qualificador de futuro ou de origem. Pode deixar passar afirmação sem qualificador explícito e pode barrar
     * linha legítima sem qualificador; o remédio é incluir o qualificador ou listar o arquivo em SEM_REGRA_MULTIBANCO.
     *
     * @return list<int> números das linhas (a partir de 1) que afirmam suporte sem qualificador
     */
    private function linhasQueAfirmamSuporte(string $texto): array
    {
        $ruins = [];
        foreach (preg_split('/\R/', $texto) ?: [] as $i => $linha) {
            if (preg_match('/mysql|postgres|pgsql|mariadb/i', $linha) === 1
                && preg_match(self::QUALIFICADOR_MULTIBANCO, $linha) !== 1) {
                $ruins[] = $i + 1;
            }
        }

        return $ruins;
    }

    public function testDocsNaoDizemQueMysqlOuPostgresqlJaFuncionam(): void
    {
        $achados = [];
        foreach ($this->markdowns() as $arquivo) {
            $rel = substr($arquivo, strlen(dirname(__DIR__)) + 1);
            if (in_array($rel, self::SEM_REGRA_MULTIBANCO, true)) {
                continue;
            }
            foreach ($this->linhasQueAfirmamSuporte((string) file_get_contents($arquivo)) as $n) {
                $achados[] = "{$rel}:{$n}";
            }
        }

        self::assertSame([], $achados, "MySQL/PostgreSQL só chegam na 0.2; qualifique a linha:\n" . implode("\n", $achados));
    }

    public function testRegraDoMultibancoPegaFraseProibida(): void
    {
        self::assertSame([2], $this->linhasQueAfirmamSuporte("ok\nMySQL e PostgreSQL são suportados.\n"));
        self::assertSame([], $this->linhasQueAfirmamSuporte('MySQL e PostgreSQL chegam na 0.2.'));
    }

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
                if (preg_match('#^(https?:|mailto:|captura:|\#)#', $alvo) === 1) {
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

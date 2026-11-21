<?php

declare(strict_types=1);

namespace Pbl\Backup;

use Pbl\Atualizacao\CopiaDeBanco;
use Pbl\Atualizacao\ErroDeAtualizacao;
use Pbl\Atualizacao\Trava;
use Pbl\Caminhos;
use Pbl\Versao;

/**
 * Cria o pacote `.zip` (ADR 0030): `manifesto.json` + `banco/pbl4.sqlite` (cópia consistente, conferida) + `dados/**`. O pacote contém
 * segredos, então é 0600, fica em pasta negada à web e nada dele vai para log. Sob a mesma trava de atualizar/reverter/republicar.
 */
final class CriadorDeBackup
{
    /** Primeiros segmentos de `dados/` que nunca entram no pacote (cópias, estado, travas, cache refeito, banco vivo). */
    private const EXCLUIDOS = ['backups', 'backup', 'atualizacao', 'cron', 'cache', 'manutencao.json'];

    public function __construct(
        private readonly Caminhos $caminhos,
        private readonly ConfigDeBackup $config,
        private readonly string $versao = Versao::ATUAL,
        private readonly ?\Closure $relogio = null,
    ) {
    }

    /** @throws ErroDeBackup */
    public function criar(string $tipo): InfoDeBackup
    {
        try {
            $trava = Trava::obter($this->caminhos);
        } catch (ErroDeAtualizacao) {
            throw ErroDeBackup::ocupado();
        }
        try {
            return $this->criarSemTrava($tipo);
        } finally {
            Trava::soltar($trava);
        }
    }

    /** Para quem já segura a trava (a restauração). @throws ErroDeBackup */
    public function criarSemTrava(string $tipo): InfoDeBackup
    {
        if (!in_array($tipo, InfoDeBackup::TIPOS, true) || $tipo === 'pre-atualizacao') {
            throw new ErroDeBackup('Tipo de cópia inválido.');
        }
        if (!is_file($this->caminhos->banco())) {
            throw new ErroDeBackup('Não há banco SQLite para copiar (apenas SQLite é suportado nas cópias).');
        }
        $pasta = ValidadorDePasta::resolver($this->caminhos, $this->config->pasta);
        $agora = $this->relogio === null ? time() : ($this->relogio)();
        $id = gmdate('Ymd-His', $agora) . '-' . bin2hex(random_bytes(2));
        $trabalho = $this->caminhos->dados() . '/backup/trabalho/' . $id;
        if (!@mkdir($trabalho, 0700, true) && !is_dir($trabalho)) {
            throw new ErroDeBackup('Não foi possível criar dados/backup/trabalho/.');
        }
        $zipTmp = $pasta . '/.parte-' . $id;
        try {
            $this->exigirEspaco($pasta);
            $banco = $trabalho . '/pbl4.sqlite';
            try {
                CopiaDeBanco::copiar($this->caminhos->banco(), $banco);
            } catch (ErroDeAtualizacao) {
                throw new ErroDeBackup('Não foi possível copiar o banco.');
            }
            if (!CopiaDeBanco::integro($banco)) {
                throw new ErroDeBackup('A cópia do banco não passou na checagem de integridade; nada foi gravado.');
            }
            $schema = CopiaDeBanco::versaoDoEsquema($banco);

            $zip = new \ZipArchive();
            if ($zip->open($zipTmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new ErroDeBackup('Não foi possível criar o pacote na pasta de cópias.');
            }
            $entradas = [['caminho' => 'banco/pbl4.sqlite', 'tamanho' => (int) filesize($banco), 'sha256' => (string) hash_file('sha256', $banco)]];
            $zip->addFile($banco, 'banco/pbl4.sqlite');
            foreach ($this->arquivosDeDados($pasta) as $relativo => $abs) {
                $entradas[] = ['caminho' => 'dados/' . $relativo, 'tamanho' => (int) filesize($abs), 'sha256' => (string) hash_file('sha256', $abs)];
                $zip->addFile($abs, 'dados/' . $relativo);
            }
            $manifesto = [
                'formato' => 1, 'id' => $id, 'tipo' => $tipo, 'criado_em' => gmdate('c', $agora), 'fuso' => $this->config->fuso,
                'versao_pbl4' => $this->versao, 'schema' => $schema, 'driver' => 'sqlite', 'entradas' => $entradas,
            ];
            $zip->addFromString('manifesto.json', json_encode($manifesto, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            if (!$zip->close()) {
                throw new ErroDeBackup('Não foi possível fechar o pacote (disco cheio?).');
            }
            @chmod($zipTmp, 0600);
            $nome = sprintf('pbl4-%s-%s-v%s.zip', gmdate('Ymd-His', $agora), $tipo, preg_replace('/[^A-Za-z0-9.+-]/', '-', $this->versao));
            $final = $pasta . '/' . $nome;
            if (is_file($final)) {
                $nome = str_replace('.zip', '-' . substr($id, -4) . '.zip', $nome);
                $final = $pasta . '/' . $nome;
            }
            $sha = (string) hash_file('sha256', $zipTmp);
            if (!@rename($zipTmp, $final)) {
                throw new ErroDeBackup('Não foi possível gravar o pacote final.');
            }
            @file_put_contents($final . '.sha256', $sha . '  ' . $nome . "\n");
            @chmod($final . '.sha256', 0600);

            return new InfoDeBackup($nome, $tipo, $agora, (int) filesize($final), $this->versao, $schema, true);
        } finally {
            @unlink($zipTmp);
            self::apagarArvore($trabalho);
            @rmdir(dirname($trabalho));
        }
    }

    /** @return \Generator<string, string> caminho relativo a dados/ => caminho absoluto */
    private function arquivosDeDados(string $pastaDeCopias): \Generator
    {
        $dados = realpath($this->caminhos->dados());
        if ($dados === false) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($dados, \FilesystemIterator::SKIP_DOTS),
            function (\SplFileInfo $f) use ($dados, $pastaDeCopias): bool {
                if ($f->isLink()) {
                    return false;
                }
                $rel = substr($f->getPathname(), strlen($dados) + 1);
                $topo = explode('/', $rel)[0];
                if (in_array($topo, self::EXCLUIDOS, true) || str_starts_with($f->getPathname(), $pastaDeCopias)) {
                    return false;
                }
                if (!str_contains($rel, '/') && (str_starts_with($rel, 'pbl4.sqlite') || str_ends_with($rel, '.lock'))) {
                    return false; // banco vivo (e -wal/-shm/-journal) é substituído pela cópia consistente
                }

                return !str_ends_with($rel, '.tmp') && !str_ends_with($rel, '.parte');
            },
        ));
        foreach ($it as $f) {
            if ($f->isFile()) {
                yield substr($f->getPathname(), strlen($dados) + 1) => $f->getPathname();
            }
        }
    }

    private function exigirEspaco(string $pasta): void
    {
        $livre = ValidadorDePasta::espacoLivre($pasta);
        $banco = (int) filesize($this->caminhos->banco());
        if ($livre !== null && $livre < $banco * 2 + 1048576) {
            throw new ErroDeBackup('Espaço livre insuficiente na pasta de cópias.');
        }
    }

    public static function apagarArvore(string $pasta): void
    {
        if (!is_dir($pasta) || is_link($pasta)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pasta, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            ($f->isDir() && !$f->isLink()) ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($pasta);
    }
}

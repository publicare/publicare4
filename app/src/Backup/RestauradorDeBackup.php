<?php

declare(strict_types=1);

namespace Pbl\Backup;

use Pbl\Atualizacao\ErroDeAtualizacao;
use Pbl\Atualizacao\Manutencao;
use Pbl\Atualizacao\Trava;
use Pbl\Banco\Conexao;
use Pbl\Banco\Copia\InstantaneoDeBanco;
use Pbl\Banco\Migrador;
use Pbl\Atualizacao\CopiaDeBanco;
use Pbl\Caminhos;

/**
 * Restaura um pacote (ADR 0031). Tudo é conferido ANTES de escrever: SHA-256 do pacote e de cada entrada, entradas só as do manifesto
 * e com caminho seguro, integridade do banco, esquema não mais novo que o código, espaço livre. Depois: cópia automática do estado
 * atual (`pre-restauracao`), manutenção (503), troca por renomeação e, se algo falhar, volta ao que era.
 */
final class RestauradorDeBackup
{
    public const LIMITE_DESCOMPACTADO = 8 * 1024 * 1024 * 1024;

    /** @param int|null $esquemaDoCodigo só para testes: maior migração conhecida (padrão: calculada do Migrador) */
    public function __construct(
        private readonly Caminhos $caminhos,
        private readonly CatalogoDeBackups $catalogo,
        private readonly CriadorDeBackup $criador,
        private readonly ?int $esquemaDoCodigo = null,
    ) {
    }

    /**
     * Confere sem alterar nada.
     *
     * @return array{manifesto: array<string, mixed>, tamanho: int}
     * @throws ErroDeBackup
     */
    public function conferir(string $nome): array
    {
        $arquivo = $this->catalogo->caminhoDe($nome);
        if (!CatalogoDeBackups::integro($arquivo)) {
            throw new ErroDeBackup('A integridade do pacote não confere (SHA-256 diferente do registrado ou sem registro). Nada foi alterado.');
        }
        $m = CatalogoDeBackups::lerManifesto($arquivo);
        if ($m === null || ($m['formato'] ?? null) !== 1) {
            throw new ErroDeBackup('Pacote sem manifesto válido ou de formato desconhecido.');
        }
        if (($m['driver'] ?? null) !== 'sqlite') {
            throw new ErroDeBackup('Só pacotes de banco SQLite podem ser restaurados.');
        }
        $schema = $m['schema'] ?? null;
        $codigo = $this->esquemaDoCodigo ?? $this->esquemaConhecido();
        if (!is_int($schema) || $schema > $codigo) {
            throw new ErroDeBackup('O pacote vem de uma versão mais nova do PBL4 (esquema do banco maior que o deste código). Atualize o PBL4 antes de restaurar.');
        }
        $entradas = $m['entradas'] ?? null;
        if (!is_array($entradas) || $entradas === []) {
            throw new ErroDeBackup('Manifesto sem lista de arquivos.');
        }
        $total = 0;
        foreach ($entradas as $e) {
            if (!is_array($e) || !is_string($e['caminho'] ?? null) || !is_int($e['tamanho'] ?? null) || !is_string($e['sha256'] ?? null) || !self::caminhoSeguro($e['caminho'])) {
                throw new ErroDeBackup('Manifesto com entrada inválida ou caminho inseguro.');
            }
            $total += $e['tamanho'];
        }
        if ($total > self::LIMITE_DESCOMPACTADO) {
            throw new ErroDeBackup('Conteúdo descompactado grande demais.');
        }
        $livre = ValidadorDePasta::espacoLivre($this->caminhos->dados());
        if ($livre !== null && $livre < $total * 2) {
            throw new ErroDeBackup('Espaço livre insuficiente para restaurar com segurança.');
        }

        return ['manifesto' => $m, 'tamanho' => $total];
    }

    /**
     * @return array{id: string, versao: string, copia_anterior: string}
     * @throws ErroDeBackup
     */
    public function restaurar(string $nome): array
    {
        try {
            $trava = Trava::obter($this->caminhos);
        } catch (ErroDeAtualizacao) {
            throw ErroDeBackup::ocupado();
        }
        $trabalho = null;
        try {
            ['manifesto' => $m] = $this->conferir($nome);
            $arquivo = $this->catalogo->caminhoDe($nome);
            $id = (string) ($m['id'] ?? 'x');
            $trabalho = $this->caminhos->dados() . '/backup/trabalho/restauro-' . bin2hex(random_bytes(4));
            $this->extrair($arquivo, $m, $trabalho);
            if (!CopiaDeBanco::integro($trabalho . '/banco/pbl4.sqlite')) {
                throw new ErroDeBackup('O banco do pacote não passou na checagem de integridade. Nada foi alterado.');
            }
            $antes = $this->criador->criarSemTrava('pre-restauracao'); // se falhar, aborta antes de tocar em qualquer coisa

            Manutencao::ligar($this->caminhos, 'restauração');
            try {
                $this->trocar($trabalho, $m);
            } finally {
                Manutencao::desligar($this->caminhos);
            }

            return ['id' => $id, 'versao' => (string) ($m['versao_pbl4'] ?? '?'), 'copia_anterior' => $antes->arquivo];
        } finally {
            if ($trabalho !== null) {
                CriadorDeBackup::apagarArvore($trabalho);
                @rmdir(dirname($trabalho));
            }
            Trava::soltar($trava);
        }
    }

    public static function caminhoSeguro(string $c): bool
    {
        if ($c === '' || str_starts_with($c, '/') || str_contains($c, "\0") || str_contains($c, '\\')) {
            return false;
        }
        foreach (explode('/', $c) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                return false;
            }
        }

        return $c === 'banco/pbl4.sqlite' || str_starts_with($c, 'dados/');
    }

    private function esquemaConhecido(): int
    {
        $migrador = new Migrador(Conexao::memoria());
        $migrador->migrar();

        return $migrador->versaoAtual();
    }

    /** @param array<string, mixed> $m */
    private function extrair(string $arquivo, array $m, string $destino): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($arquivo, \ZipArchive::RDONLY) !== true) {
            throw new ErroDeBackup('Não foi possível abrir o pacote.');
        }
        try {
            $permitidos = ['manifesto.json' => true];
            foreach ($m['entradas'] as $e) {
                $permitidos[$e['caminho']] = true;
            }
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $nome = (string) $zip->getNameIndex($i);
                if (!str_ends_with($nome, '/') && !isset($permitidos[$nome])) {
                    throw new ErroDeBackup('O pacote tem arquivo fora do manifesto: recusado.');
                }
                if ($zip->getExternalAttributesIndex($i, $os, $attr) && $os === \ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                    throw new ErroDeBackup('O pacote contém link simbólico: recusado.');
                }
            }
            foreach ($m['entradas'] as $e) {
                $alvo = $destino . '/' . $e['caminho'];
                if (!is_dir(dirname($alvo)) && !@mkdir(dirname($alvo), 0700, true)) {
                    throw new ErroDeBackup('Não foi possível preparar a pasta temporária.');
                }
                $in = $zip->getStream($e['caminho']);
                $out = @fopen($alvo, 'wb');
                if ($in === false || $out === false) {
                    throw new ErroDeBackup('Pacote incompleto: falta ' . $e['caminho'] . '.');
                }
                $ctx = hash_init('sha256');
                $bytes = 0;
                while (!feof($in)) {
                    $parte = (string) fread($in, 1048576);
                    hash_update($ctx, $parte);
                    $bytes += strlen($parte);
                    fwrite($out, $parte);
                }
                fclose($in);
                fclose($out);
                if ($bytes !== $e['tamanho'] || !hash_equals($e['sha256'], hash_final($ctx))) {
                    throw new ErroDeBackup('Integridade de ' . $e['caminho'] . ' não confere. Nada foi alterado.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    /** @param array<string, mixed> $m */
    private function trocar(string $trabalho, array $m): void
    {
        $dados = $this->caminhos->dados();
        $banco = $this->caminhos->banco();
        $sufixo = '.antiga-' . bin2hex(random_bytes(3));
        $topos = [];
        foreach ($m['entradas'] as $e) {
            if (str_starts_with($e['caminho'], 'dados/')) {
                $topos[explode('/', $e['caminho'])[1]] = true;
            }
        }
        $feitos = []; // [antigo, guardado] renomeados, para desfazer
        $instantaneo = new InstantaneoDeBanco($this->caminhos);
        $emServidor = !$instantaneo->config()->ehSqlite();
        $guardadoDoServidor = $trabalho . '/banco-de-antes.sqlite';
        $bancoTrocado = false;
        try {
            if ($emServidor) {
                // Banco de servidor não se renomeia: guarda uma cópia do de hoje para devolver se algo falhar.
                $instantaneo->tirar($guardadoDoServidor);
            } else {
                foreach (['', '-wal', '-shm', '-journal'] as $s) {
                    if (file_exists($banco . $s)) {
                        $this->mover($banco . $s, $banco . $s . $sufixo, $feitos);
                    }
                }
                $this->mover($trabalho . '/banco/pbl4.sqlite', $banco, $feitos, false);
                @chmod($banco, 0600);
            }
            foreach (array_keys($topos) as $topo) {
                if (in_array($topo, ['backups', 'backup', 'atualizacao', 'cron', 'cache'], true)) {
                    continue;
                }
                if (file_exists($dados . '/' . $topo)) {
                    $this->mover($dados . '/' . $topo, $dados . '/' . $topo . $sufixo, $feitos);
                }
                $this->mover($trabalho . '/dados/' . $topo, $dados . '/' . $topo, $feitos, false);
            }
            if ($emServidor) {
                $bancoTrocado = true;
                $instantaneo->restaurar($trabalho . '/banco/pbl4.sqlite');
            }
            $schema = $m['schema'] ?? 0;
            $conexao = Conexao::abrir($instantaneo->config());
            if (is_int($schema) && $schema < ($this->esquemaDoCodigo ?? $this->esquemaConhecido())) {
                (new Migrador($conexao))->migrar();
            }
            $conexao->close();
            if ($emServidor ? $instantaneo->versaoDoEsquema() === null : (!CopiaDeBanco::integro($banco) || CopiaDeBanco::versaoDoEsquema($banco) === null)) {
                throw new ErroDeBackup('A verificação depois da restauração falhou.');
            }
        } catch (\Throwable $e) {
            $this->desfazer($feitos);
            if ($bancoTrocado && is_file($guardadoDoServidor)) {
                try {
                    $instantaneo->restaurar($guardadoDoServidor);
                } catch (\Throwable) {
                    // sem como devolver: a cópia pre-restauracao continua no catálogo
                }
            }
            throw $e instanceof ErroDeBackup ? $e : new ErroDeBackup('A restauração falhou e o estado anterior foi devolvido.');
        }
        foreach ($feitos as [, $guardado, $guardar]) {
            if ($guardar) {
                is_dir($guardado) ? CriadorDeBackup::apagarArvore($guardado) : @unlink($guardado);
            }
        }
    }

    /** @param list<array{string, string, bool}> $feitos */
    private function mover(string $de, string $para, array &$feitos, bool $guardar = true): void
    {
        if (!@rename($de, $para)) {
            throw new ErroDeBackup('Não foi possível trocar os arquivos.');
        }
        $feitos[] = [$de, $para, $guardar];
    }

    /** @param list<array{string, string, bool}> $feitos */
    private function desfazer(array $feitos): void
    {
        foreach (array_reverse($feitos) as [$origem, $destino, $guardar]) {
            if ($guardar) {
                @rename($destino, $origem); // devolve o antigo (apaga antes o novo que ocupou o lugar)
            } else {
                is_dir($destino) ? CriadorDeBackup::apagarArvore($destino) : @unlink($destino);
            }
        }
    }
}

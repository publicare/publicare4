<?php

declare(strict_types=1);

namespace Pbl\Demo;

use Pbl\Atualizacao\Manutencao;
use Pbl\Caminhos;
use Pbl\Console\DemoPopularComando;
use Pbl\Conteudo\Modelos;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Reinício da demonstração (ADR 0023, adendo): volta a instalação ao estado "recém-instalada com o conteúdo de exemplo".
 *
 * Só roda em instalação de demonstração ({@see ConfigDaDemo::ativa}); em qualquer outra a chamada lança antes de tocar em
 * qualquer coisa. Passos: trava `dados/demo/reiniciar.lock` (duas execuções não se sobrepõem) → lê nome e domínio principal do
 * site → backup de `dados/` (SQLite, uploads, peles e a marca de instalado) em `dados/demo/backups/`, mantendo os 3 últimos →
 * liga a manutenção (503) → apaga banco, cache, peles, uploads e `instalado.lock` → reinstala com o {@see Instalador} (todos os
 * modelos, administrador com a senha da demo) → popula como `demo-popular --forcar` → liga a agenda do próximo ciclo. **Se
 * qualquer passo falhar, o backup é restaurado** e a manutenção é desligada: o site volta como estava. Ficam intactos
 * `dados/config/` (segredo, rede.json, flag da demo…), `dados/cron/` e `dados/demo/`.
 */
final class ReiniciadorDaDemo
{
    public const LOGIN_ADMIN = 'admin';
    public const SENHA_ADMIN = 'admin-demo-2026';
    public const MANTER_BACKUPS = 3;

    /** @param (\Closure(string): void)|null $aposApagar só para os testes: roda depois de apagar e antes de reinstalar (para simular falha) */
    public function __construct(
        private readonly Caminhos $caminhos,
        private readonly ?AgendaDaDemo $agenda = null,
        private readonly ?\Closure $aposApagar = null,
    ) {
    }

    /**
     * @param bool $simular só descreve o que faria, sem mudar nada
     * @return list<string> o que foi (ou seria) feito
     * @throws \RuntimeException fora de demo, com reinício já em andamento, ou se algo falhou (o backup já foi restaurado)
     */
    public function reiniciar(bool $simular = false): array
    {
        if (!ConfigDaDemo::ativa($this->caminhos)) {
            throw new \RuntimeException('Esta instalação não é uma demo (defina "demo": true em dados/config/demo.json ou PBL_DEMO=1): nada foi feito.');
        }
        if (!is_file($this->caminhos->banco()) || !(new Instalador($this->caminhos))->instalado()) {
            throw new \RuntimeException('A demo ainda não está instalada: não há o que reiniciar.');
        }
        $agenda = $this->agenda ?? new AgendaDaDemo($this->caminhos);
        $pasta = $agenda->pasta();
        if (!is_dir($pasta . '/backups') && !@mkdir($pasta . '/backups', 0775, true) && !is_dir($pasta . '/backups')) {
            throw new \RuntimeException('Não foi possível criar dados/demo/ (falta permissão de escrita em dados/).');
        }
        $trava = @fopen($pasta . '/reiniciar.lock', 'c');
        if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) {
            if ($trava !== false) {
                fclose($trava);
            }
            throw new \RuntimeException('Já existe um reinício da demo em andamento.');
        }
        try {
            [$nome, $host] = $this->identidade();
            $plano = [
                "Backup de dados/ (SQLite, uploads, peles) em dados/demo/backups/, mantendo os " . self::MANTER_BACKUPS . ' últimos',
                'Manutenção (503) ligada durante o reinício',
                'Banco, cache, peles, uploads e dados/config/instalado.lock apagados; dados/config/ (segredo, rede, flag da demo) mantida',
                "Reinstalação: site \"{$nome}\" em {$host}, todos os modelos, administrador \"" . self::LOGIN_ADMIN . '"',
                'Conteúdo de exemplo recriado (como demo-popular --forcar), com as contas autor e editor',
            ];
            if ($simular) {
                return ['(simulação: nada foi alterado)', ...$plano];
            }

            return $this->executar($agenda, $nome, $host, $plano);
        } finally {
            flock($trava, LOCK_UN);
            fclose($trava);
        }
    }

    /**
     * @param list<string> $plano
     * @return list<string>
     */
    private function executar(AgendaDaDemo $agenda, string $nome, string $host, array $plano): array
    {
        $ultimoAntes = $agenda->ultimo();
        $backup = $this->fazerBackup($agenda->pasta() . '/backups');
        Manutencao::ligar($this->caminhos, 'reinicio-da-demo');
        try {
            $this->apagar();
            if ($this->aposApagar !== null) {
                ($this->aposApagar)($host);
            }
            // O aviso "reinicia em…" das páginas publicadas já nasce com o próximo ciclo.
            $agenda->registrar($agenda->agora());
            $this->reinstalar($nome, $host);
        } catch (\Throwable $e) {
            $agenda->registrar($ultimoAntes);
            $agenda->registrarFalha();
            try {
                $this->restaurar($backup);
            } catch (\Throwable $r) {
                Manutencao::desligar($this->caminhos);
                throw new \RuntimeException('O reinício da demo falhou (' . $e->getMessage() . ') e a restauração também (' . $r->getMessage() . '). O backup está em ' . basename($backup) . ' dentro de dados/demo/backups/.', 0, $e);
            }
            self::apagarArvore($backup); // a instalação é idêntica a ele: não gasta uma das vagas dos últimos backups
            Manutencao::desligar($this->caminhos);

            throw new \RuntimeException('O reinício da demo falhou e o estado anterior foi restaurado: ' . $e->getMessage(), 0, $e);
        }
        Manutencao::desligar($this->caminhos);
        $this->podarBackups($agenda->pasta() . '/backups');

        return $plano;
    }

    /** @return array{0: string, 1: string} nome do site e domínio principal atuais */
    private function identidade(): array
    {
        $s = Servicos::doBancoInstalado($this->caminhos);
        try {
            $site = $s->sites->todos()[0] ?? throw new \RuntimeException('A demo não tem site: não há o que reiniciar.');
            $principal = $s->dominios->principalDe($site->id);
            $dominio = $principal ?? ($s->dominios->doSite($site->id)[0] ?? null);
            if ($dominio === null) {
                throw new \RuntimeException('O site da demo não tem domínio cadastrado: não há o que reiniciar.');
            }
            $host = $dominio->host;
        } finally {
            $s->conexao()->close();
        }

        return [$site->nome, $host];
    }

    private function reinstalar(string $nome, string $host): void
    {
        (new Instalador($this->caminhos))->instalar($nome, $host, [
            'login' => self::LOGIN_ADMIN, 'email' => self::LOGIN_ADMIN . '@exemplo.org', 'nome' => 'Administrador da demo', 'senha' => self::SENHA_ADMIN,
        ], Modelos::NOMES);
        $saida = new BufferedOutput();
        $codigo = (new DemoPopularComando($this->caminhos))->run(new ArrayInput(['--forcar' => true]), $saida);
        if ($codigo !== 0) {
            throw new \RuntimeException('o conteúdo de exemplo não foi criado: ' . trim(strip_tags($saida->fetch())));
        }
    }

    /** Apaga o que o reinício recria. Mantém `dados/config/` (menos a marca de instalado), `dados/cron/` e `dados/demo/`. */
    private function apagar(): void
    {
        $c = $this->caminhos;
        foreach (glob($c->dados() . '/pbl4.sqlite*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        foreach ([$c->cache(), $c->peles(), $c->uploads()] as $pasta) {
            self::apagarArvore($pasta);
        }
        @unlink($c->config() . '/instalado.lock');
        if (is_file($c->banco()) || is_file($c->config() . '/instalado.lock')) {
            throw new \RuntimeException('não foi possível apagar o banco e a marca de instalado em dados/');
        }
    }

    /** Copia SQLite (já sem o WAL pendente), uploads, peles e `instalado.lock` para uma pasta nova; devolve o caminho. */
    private function fazerBackup(string $base): string
    {
        $destino = $base . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(2));
        if (!@mkdir($destino, 0775, true) && !is_dir($destino)) {
            throw new \RuntimeException('não foi possível criar a pasta do backup');
        }
        try {
            $s = Servicos::doBancoInstalado($this->caminhos);
            try {
                $s->conexao()->executeQuery('PRAGMA wal_checkpoint(TRUNCATE)')->free();
            } finally {
                $s->conexao()->close();
            }
            foreach (glob($this->caminhos->dados() . '/pbl4.sqlite*') ?: [] as $arquivo) {
                if (!@copy($arquivo, $destino . '/' . basename($arquivo))) {
                    throw new \RuntimeException('não foi possível copiar o banco');
                }
            }
            self::copiarArvore($this->caminhos->uploads(), $destino . '/uploads');
            self::copiarArvore($this->caminhos->peles(), $destino . '/peles');
            if (is_file($this->caminhos->config() . '/instalado.lock')) {
                @copy($this->caminhos->config() . '/instalado.lock', $destino . '/instalado.lock');
            }
        } catch (\Throwable $e) {
            self::apagarArvore($destino);
            throw new \RuntimeException('Backup prévio falhou, nada foi alterado: ' . $e->getMessage(), 0, $e);
        }

        return $destino;
    }

    private function restaurar(string $backup): void
    {
        $c = $this->caminhos;
        $this->apagar();
        foreach (glob($backup . '/pbl4.sqlite*') ?: [] as $arquivo) {
            if (!copy($arquivo, $c->dados() . '/' . basename($arquivo))) {
                throw new \RuntimeException('não foi possível restaurar o banco');
            }
        }
        $c->garantirDados();
        self::copiarArvore($backup . '/uploads', $c->uploads());
        self::copiarArvore($backup . '/peles', $c->peles());
        if (is_file($backup . '/instalado.lock') && !copy($backup . '/instalado.lock', $c->config() . '/instalado.lock')) {
            throw new \RuntimeException('não foi possível restaurar a marca de instalado');
        }
        // O cache de páginas não entra no backup: refaz a partir do banco restaurado.
        $s = Servicos::doBancoInstalado($c);
        try {
            foreach ($s->sites->todos() as $site) {
                $s->publicador->publicarSite($site);
            }
        } finally {
            $s->conexao()->close();
        }
    }

    private function podarBackups(string $base): void
    {
        $pastas = array_values(array_filter(glob($base . '/*', GLOB_ONLYDIR) ?: [], static fn (string $p): bool => preg_match('/^\d{8}-\d{6}-[0-9a-f]{4}$/D', basename($p)) === 1));
        sort($pastas); // o nome começa pela data: ordem alfabética = cronológica
        foreach (array_slice($pastas, 0, max(0, count($pastas) - self::MANTER_BACKUPS)) as $velha) {
            self::apagarArvore($velha);
        }
    }

    public static function copiarArvore(string $origem, string $destino): void
    {
        if (!is_dir($origem)) {
            return;
        }
        if (!is_dir($destino) && !@mkdir($destino, 0775, true) && !is_dir($destino)) {
            throw new \RuntimeException('não foi possível criar uma pasta do backup');
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($origem, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            if ($item->isLink()) {
                continue; // nunca segue link: o backup não sai de dados/
            }
            $relativo = substr($item->getPathname(), strlen(rtrim($origem, '/')) + 1);
            if ($item->isDir()) {
                if (!is_dir("{$destino}/{$relativo}") && !@mkdir("{$destino}/{$relativo}", 0775, true) && !is_dir("{$destino}/{$relativo}")) {
                    throw new \RuntimeException('não foi possível criar uma pasta do backup');
                }
            } elseif (!@copy($item->getPathname(), "{$destino}/{$relativo}")) {
                throw new \RuntimeException('não foi possível copiar um arquivo para o backup');
            }
        }
    }

    public static function apagarArvore(string $pasta): void
    {
        if (is_link($pasta) || !is_dir($pasta)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pasta, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($pasta);
    }
}

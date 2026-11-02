<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

use Pbl\Acesso\Auditoria;
use Pbl\Banco\Conexao;
use Pbl\Caminhos;
use Pbl\Versao;

/**
 * Volta atrás depois de uma atualização concluída (ADR 0006): devolve o código guardado em `dados/atualizacao/anterior/` e o banco
 * copiado em `dados/backups/` antes da atualização.
 *
 * Só volta se TUDO conferir com o `aplicada.json` que a atualização deixou: a versão instalada é a registrada, o código guardado diz
 * ser a versão de antes, a cópia do banco existe, tem o mesmo SHA-256 e o esquema registrado, e o esquema do banco de hoje é o mesmo
 * de logo após a atualização (se outra migração rodou depois, a cópia antiga já não descreve este banco e a volta é recusada).
 *
 * O que se perde de propósito: tudo o que foi criado ou alterado no banco depois da atualização (a cópia é de ANTES). Por isso ela
 * só roda com confirmação explícita e, antes de mexer em qualquer coisa, guarda uma cópia do banco de hoje em `dados/backups/`.
 *
 * Passos, sob a mesma trava da atualização e com o site em manutenção: (1) copia o banco atual (cópia de segurança); (2) prepara e
 * confere a cópia a restaurar; (3) troca as pastas por `rename` (a versão nova vai para `dados/atualizacao/revertida/`);
 * (4) substitui o banco; (5) confere a versão com o código antigo num processo à parte; (6) registra na auditoria. Se qualquer passo
 * falhar, devolve o código novo e o banco de hoje e diz o motivo.
 *
 * Depois da troca de código, o PHP só pode usar classes já carregadas (as pastas já são as antigas, sem estas classes): por isso o
 * que roda depois da troca é só funções do PHP e as classes carregadas em `precarregar()`.
 */
final class Reversor
{
    /** @var \Closure(string, string): void */
    private readonly \Closure $conferir;
    /** @var \Closure(): int */
    private readonly \Closure $relogio;

    /**
     * @param \Closure(string, string): void|null $conferir recebe a raiz já trocada e a versão esperada; lança se o código antigo não funcionar (padrão: processo PHP à parte)
     * @param (\Closure(): int)|null $relogio
     */
    public function __construct(
        private readonly Caminhos $caminhos,
        private readonly string $versaoAtual = Versao::ATUAL,
        ?\Closure $conferir = null,
        ?\Closure $relogio = null,
    ) {
        $this->conferir = $conferir ?? self::conferirComProcesso(...);
        $this->relogio = $relogio ?? static fn (): int => time();
    }

    /** Só olha: dá para voltar atrás agora? Não altera nada nem pega a trava. */
    public function avaliar(): Avaliacao
    {
        $r = RegistroDaAtualizacao::ler($this->caminhos);
        if ($r === null) {
            return new Avaliacao(false, 'Não há atualização concluída para desfazer. O código anterior só fica guardado depois de uma atualização feita por esta instalação, e até a próxima.');
        }
        if ($r['revertida']) {
            return new Avaliacao(false, "A atualização para a {$r['para']} já foi desfeita. Para voltar a ela, atualize de novo.");
        }
        if ($this->versaoAtual !== $r['para']) {
            return new Avaliacao(false, "A versão instalada ({$this->versaoAtual}) não é a da última atualização registrada ({$r['para']}); o registro não vale mais e não é seguro voltar atrás.");
        }
        $anterior = $this->caminhos->dados() . '/atualizacao/anterior';
        foreach ($r['itens'] as $item) {
            if (!in_array($item, $r['sem_anterior'], true) && !file_exists("{$anterior}/{$item}") && !is_link("{$anterior}/{$item}")) {
                return new Avaliacao(false, "A cópia do código anterior está incompleta (falta {$item} em dados/atualizacao/anterior/).");
            }
        }
        $versaoGuardada = self::versaoDoCodigo("{$anterior}/app/src/Versao.php");
        if (!in_array('app', $r['sem_anterior'], true) && in_array('app', $r['itens'], true) && $versaoGuardada !== $r['de']) {
            return new Avaliacao(false, "O código guardado em dados/atualizacao/anterior/ não é da versão {$r['de']} (diz " . ($versaoGuardada ?? 'nenhuma') . ').');
        }
        $restaura = $r['backup'] !== '';
        if ($restaura) {
            $erro = $this->conferirBackup($r);
            if ($erro !== null) {
                return new Avaliacao(false, $erro);
            }
        }
        $banco = $this->caminhos->banco();
        if (is_file($banco)) {
            $hoje = CopiaDeBanco::versaoDoEsquema($banco);
            if ($hoje !== $r['schema_depois']) {
                return new Avaliacao(false, 'O banco mudou de forma incompatível depois da atualização (migração ' . ($hoje ?? 'ilegível') . ' em vez de ' . ($r['schema_depois'] ?? 'nenhuma') . '): a cópia de antes não descreve mais este banco. Voltar atrás não é seguro; restaure à mão se for mesmo preciso.');
            }
        }
        if (!is_writable($this->caminhos->raiz) || !is_writable($this->caminhos->dados())) {
            return new Avaliacao(false, 'Sem permissão de escrita na pasta do PBL4 (e em dados/): o usuário do PHP precisa poder trocar o código.');
        }

        return new Avaliacao(true, "Dá para voltar para a {$r['de']}.", $r['de'], $r['para'], $r['em'], $restaura);
    }

    /**
     * @param string $ator `cli` ou `usuario:ID`, para a auditoria
     * @throws ErroDeAtualizacao com o motivo, se não pôde voltar ou se a volta falhou (e foi desfeita)
     */
    public function reverter(string $ator = 'cli', ?int $usuarioId = null): Revertida
    {
        self::precarregar();
        $trava = Trava::obter($this->caminhos);
        try {
            return $this->revertendo($ator, $usuarioId);
        } finally {
            Trava::soltar($trava);
        }
    }

    private function revertendo(string $ator, ?int $usuarioId): Revertida
    {
        // Avaliar de novo, já sob a trava: nada pode ter mudado entre olhar e agir.
        $a = $this->avaliar();
        $r = RegistroDaAtualizacao::ler($this->caminhos);
        if (!$a->possivel || $r === null) {
            throw new ErroDeAtualizacao($a->motivo);
        }
        $dados = $this->caminhos->dados();
        $pasta = $dados . '/atualizacao';
        $guardadoDaNova = $pasta . '/revertida';
        $this->remover($guardadoDaNova);
        if (!@mkdir($guardadoDaNova, 0750, true) && !is_dir($guardadoDaNova)) {
            throw new ErroDeAtualizacao('Não foi possível preparar dados/atualizacao/revertida/');
        }
        $banco = $this->caminhos->banco();
        $segura = '';
        $preparado = '';
        $movidos = [];
        $bancoTrocado = false;

        Manutencao::ligar($this->caminhos, "Voltando para {$r['de']}");
        try {
            if (is_file($banco)) {
                $backups = $dados . '/backups';
                if (!is_dir($backups) && !@mkdir($backups, 0750, true) && !is_dir($backups)) {
                    throw new ErroDeAtualizacao('Não foi possível criar dados/backups/');
                }
                $segura = $backups . '/pbl4-' . $r['para'] . '-antes-de-voltar-para-' . $r['de'] . '-' . gmdate('Ymd-His', ($this->relogio)()) . '.sqlite';
                CopiaDeBanco::copiar($banco, $segura);
            }
            if ($a->restauraBanco) {
                $preparado = $dados . '/pbl4.sqlite.revertendo';
                @unlink($preparado);
                if (!@copy($dados . '/backups/' . $r['backup'], $preparado) || !CopiaDeBanco::integro($preparado)) {
                    throw new ErroDeAtualizacao('Não foi possível preparar a cópia do banco para restaurar (arquivo ilegível ou danificado).');
                }
                @chmod($preparado, 0600);
            }
            $this->trocarCodigo($r['itens'], $pasta, $guardadoDaNova, $movidos);
            if ($a->restauraBanco) {
                $bancoTrocado = true; // a partir daqui, se falhar, o banco de hoje volta da cópia de segurança
                $this->substituirBanco($preparado);
            }
            ($this->conferir)($this->caminhos->raiz, $r['de']);
        } catch (\Throwable $e) {
            $this->desfazer($movidos, $pasta, $guardadoDaNova, $bancoTrocado ? $segura : '');
            @unlink($preparado);
            Manutencao::desligar($this->caminhos);
            throw new ErroDeAtualizacao('A volta atrás falhou e o estado anterior (versão ' . $r['para'] . ') foi restaurado: ' . $e->getMessage(), 0, $e);
        }
        $r['revertida'] = true;
        $r['revertida_em'] = ($this->relogio)();
        try {
            RegistroDaAtualizacao::gravar($this->caminhos, $r);
        } catch (\Throwable) {
            // o que vale é a troca já feita; sem o registro atualizado, a próxima tentativa é recusada pela versão
        }
        $this->auditar($ator, $usuarioId, $r['para'], $r['de']);
        Manutencao::desligar($this->caminhos);

        return new Revertida($r['para'], $r['de'], $segura);
    }

    /**
     * Para cada item: a versão nova vai para `revertida/` e a antiga volta ao lugar. Item que a versão antiga não tinha só sai.
     *
     * @param list<string> $itens
     * @param list<string> $movidos preenchido item a item, para o desfazer saber até onde chegou
     */
    private function trocarCodigo(array $itens, string $pasta, string $guardadoDaNova, array &$movidos): void
    {
        foreach ($itens as $item) {
            $atual = $this->caminhos->raiz . '/' . $item;
            $antigo = "{$pasta}/anterior/{$item}";
            $nova = "{$guardadoDaNova}/{$item}";
            if (file_exists($atual) || is_link($atual)) {
                if (!@rename($atual, $nova)) {
                    throw new ErroDeAtualizacao("Não foi possível tirar a versão nova de {$item}");
                }
            }
            $movidos[] = $item;
            if (file_exists($antigo) || is_link($antigo)) {
                if (!@rename($antigo, $atual)) {
                    throw new ErroDeAtualizacao("Não foi possível devolver a versão anterior de {$item}");
                }
            }
        }
    }

    /** @param list<string> $movidos */
    private function desfazer(array $movidos, string $pasta, string $guardadoDaNova, string $copiaSegura): void
    {
        foreach (array_reverse($movidos) as $item) {
            $atual = $this->caminhos->raiz . '/' . $item;
            $antigo = "{$pasta}/anterior/{$item}";
            $nova = "{$guardadoDaNova}/{$item}";
            if (!file_exists($nova) && !is_link($nova)) {
                continue; // a versão nova deste item nunca saiu do lugar
            }
            if (file_exists($atual) || is_link($atual)) {
                @rename($atual, $antigo); // a versão antiga que já tinha voltado
            }
            @rename($nova, $atual);
        }
        if ($copiaSegura !== '' && is_file($copiaSegura)) {
            $banco = $this->caminhos->banco();
            @unlink($banco . '-wal');
            @unlink($banco . '-shm');
            @copy($copiaSegura, $banco);
        }
    }

    private function substituirBanco(string $preparado): void
    {
        $banco = $this->caminhos->banco();
        // Restos do modo WAL de outro processo valeriam para o banco antigo, não para o que entra.
        @unlink($banco . '-wal');
        @unlink($banco . '-shm');
        if (!@rename($preparado, $banco)) {
            throw new ErroDeAtualizacao('Não foi possível colocar o banco restaurado no lugar.');
        }
    }

    /** @param array{backup: string, backup_sha256: string, schema_antes: int|null} $r */
    private function conferirBackup(array $r): ?string
    {
        if (preg_match('/^[A-Za-z0-9._+-]{1,200}\.sqlite$/D', $r['backup']) !== 1) {
            return 'O registro da atualização aponta para um nome de cópia de banco inválido.';
        }
        $arquivo = $this->caminhos->dados() . '/backups/' . $r['backup'];
        if (!is_file($arquivo) || is_link($arquivo)) {
            return "A cópia do banco feita antes da atualização não existe mais (dados/backups/{$r['backup']}); sem ela não dá para voltar atrás com segurança.";
        }
        $hash = hash_file('sha256', $arquivo);
        if ($hash === false || $r['backup_sha256'] === '' || !hash_equals($r['backup_sha256'], $hash)) {
            return "A cópia do banco dados/backups/{$r['backup']} foi alterada depois da atualização (SHA-256 não confere); não vou restaurá-la.";
        }
        if (CopiaDeBanco::versaoDoEsquema($arquivo) !== $r['schema_antes']) {
            return 'A cópia do banco não está na versão de esquema registrada pela atualização.';
        }

        return null;
    }

    /** Grava na auditoria do banco já restaurado. Só PDO e SQL: as classes do banco novo já não estão no disco. */
    private function auditar(string $ator, ?int $usuarioId, string $desfeita, string $restaurada): void
    {
        try {
            $pdo = new \PDO('sqlite:' . $this->caminhos->banco());
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $s = $pdo->prepare('INSERT INTO auditoria (quando, usuario_id, ator, acao, objeto_id, detalhe, ip_hash) VALUES (?, ?, ?, ?, NULL, ?, NULL)');
            $s->execute([gmdate('c', ($this->relogio)()), $usuarioId, mb_substr($ator, 0, 100), 'atualizacao.revertida', json_encode(['de' => $desfeita, 'para' => $restaurada], JSON_UNESCAPED_UNICODE)]);
        } catch (\Throwable) {
            // banco sem tabela de auditoria (versão muito antiga) ou travado: a volta já foi feita e a saída do comando a registra
        }
    }

    /** Versão declarada em `Versao.php` do código guardado, lida como texto (nunca incluída). */
    private static function versaoDoCodigo(string $arquivo): ?string
    {
        if (!is_file($arquivo) || is_link($arquivo) || filesize($arquivo) > 65536) {
            return null;
        }

        return preg_match("/const\\s+ATUAL\\s*=\\s*'([^']{1,64})'/", (string) file_get_contents($arquivo), $m) === 1 ? $m[1] : null;
    }

    private function remover(string $caminho): void
    {
        if (is_link($caminho) || is_file($caminho)) {
            @unlink($caminho);

            return;
        }
        if (is_dir($caminho)) {
            foreach (scandir($caminho) ?: [] as $nome) {
                if ($nome !== '.' && $nome !== '..') {
                    $this->remover($caminho . '/' . $nome);
                }
            }
            @rmdir($caminho);
        }
    }

    /** Carrega agora o que será usado depois da troca de pastas, quando o autoload já aponta para o código antigo. */
    private static function precarregar(): void
    {
        foreach ([ErroDeAtualizacao::class, Manutencao::class, RegistroDaAtualizacao::class, Revertida::class, Avaliacao::class, Trava::class, CopiaDeBanco::class, Conexao::class, Auditoria::class, Semver::class] as $classe) {
            class_exists($classe);
        }
    }

    /** Roda o código ANTIGO num processo à parte e confere que ele diz a versão esperada. */
    private static function conferirComProcesso(string $raiz, string $versao): void
    {
        $proc = proc_open([PHP_BINARY, $raiz . '/bin/pbl', 'versao'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raiz);
        if (!is_resource($proc)) {
            throw new ErroDeAtualizacao('Não foi possível iniciar o PHP para conferir a versão restaurada');
        }
        $saida = (string) stream_get_contents($pipes[1]);
        $erro = (string) stream_get_contents($pipes[2]);
        if (proc_close($proc) !== 0) {
            throw new ErroDeAtualizacao('O comando versao do código restaurado falhou: ' . trim($erro . $saida));
        }
        if (!str_contains($saida, 'PBL4 ' . $versao)) {
            throw new ErroDeAtualizacao('O código restaurado diz ser outra versão: ' . trim($saida));
        }
    }
}

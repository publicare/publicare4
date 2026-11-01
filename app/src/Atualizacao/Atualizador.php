<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

use Pbl\Banco\Conexao;
use Pbl\Caminhos;
use Pbl\Versao;

/**
 * Atualização com verificação, cópia de segurança e volta atrás (ADR 0006).
 *
 *  1. baixa `latest.json` + `.sig` e confere a assinatura Ed25519 (sem ela, nada mais acontece);
 *  2. baixa o pacote, confere tamanho e SHA-256 do manifesto assinado, extrai em área de preparo conferindo cada arquivo;
 *  3. copia o banco SQLite para `dados/backups/`;
 *  4. liga a manutenção, troca as pastas do código por `rename` (as antigas ficam em `dados/atualizacao/anterior/`);
 *  5. roda as migrações e confere a versão com o código NOVO, num processo à parte;
 *  6. se qualquer passo depois da troca falhar, devolve o código antigo. As migrações só acrescentam (expandir/contrair),
 *     então o código antigo continua funcionando com o banco já migrado.
 * `dados/` nunca faz parte da troca.
 */
final class Atualizador
{
    /** @var \Closure(string): void */
    private readonly \Closure $conferirNovo;

    /**
     * @param list<string> $chaves chaves públicas de confiança (hex)
     * @param \Closure(string): void|null $conferirNovo recebe a raiz já trocada e lança se o código novo não funcionar (padrão: processo PHP à parte)
     */
    public function __construct(
        private readonly Caminhos $caminhos,
        private readonly Baixador $baixador,
        private readonly array $chaves = Chaves::PUBLICAS,
        private readonly string $versaoAtual = Versao::ATUAL,
        ?\Closure $conferirNovo = null,
        private readonly string $phpAtual = PHP_VERSION,
    ) {
        $this->conferirNovo = $conferirNovo ?? self::conferirComProcesso(...);
    }

    public function verificar(string $urlDoManifesto, string $canal = 'estavel'): Verificacao
    {
        if ($this->chaves === []) {
            throw new ErroDeAtualizacao('Esta instalação não tem chave de assinatura embutida: não é possível confiar em nenhuma atualização.');
        }
        $texto = $this->baixador->obter($urlDoManifesto, 65536);
        $assinatura = $this->baixador->obter($urlDoManifesto . '.sig', 4096);
        if (!Assinatura::verificar($texto, $assinatura, $this->chaves)) {
            throw new ErroDeAtualizacao('A assinatura do manifesto não confere: a atualização foi recusada.');
        }
        $m = Manifesto::analisar($texto);
        if ($m->canal !== $canal) {
            throw new ErroDeAtualizacao("O manifesto é do canal {$m->canal}, e o configurado é {$canal}.");
        }
        if (Semver::comparar($m->versao, $this->versaoAtual) <= 0) {
            return new Verificacao(false, $m, "Já está na versão mais recente ({$this->versaoAtual}).");
        }
        if (Semver::comparar($this->versaoAtual, $m->atualizarAPartirDe) < 0) {
            return new Verificacao(false, $m, "Para chegar à {$m->versao} atualize antes para a {$m->atualizarAPartirDe} ou mais nova.");
        }
        if (version_compare($this->phpAtual, $m->phpMinimo, '<')) {
            return new Verificacao(false, $m, "A {$m->versao} pede PHP {$m->phpMinimo} ou mais novo; este servidor tem {$this->phpAtual}.");
        }

        return new Verificacao(true, $m, "Disponível: {$m->versao}.");
    }

    public function aplicar(Verificacao $v, string $urlDoManifesto): Aplicada
    {
        if (!$v->disponivel || $v->manifesto === null) {
            throw new ErroDeAtualizacao($v->motivo);
        }
        $m = $v->manifesto;
        $trabalho = $this->caminhos->dados() . '/atualizacao';
        $this->limpar($trabalho);
        $this->preparar($trabalho);
        $this->exigirPermissoes();

        $zip = $trabalho . '/pacote.zip';
        $this->baixador->copiar($this->baixador->resolver($urlDoManifesto, $m->urlDoPacote), $zip, $m->tamanho);
        if (filesize($zip) !== $m->tamanho || !hash_equals($m->sha256, (string) hash_file('sha256', $zip))) {
            $this->limpar($trabalho);
            throw new ErroDeAtualizacao('O pacote baixado não confere com o manifesto assinado (tamanho ou SHA-256).');
        }
        Pacote::extrair($zip, $trabalho . '/novo', $m->versao);
        @unlink($zip);

        // Manutenção antes da cópia: com o site respondendo 503 o banco não recebe escritas novas durante o backup.
        Manutencao::ligar($this->caminhos, "Atualizando para {$m->versao}");
        $backup = '';
        $trocados = [];
        try {
            $backup = $this->copiarBanco($m->versao);
            $trocados = $this->trocar($trabalho);
            ($this->conferirNovo)($this->caminhos->raiz);
        } catch (\Throwable $e) {
            $this->desfazer($trabalho, $trocados);
            Manutencao::desligar($this->caminhos);
            throw new ErroDeAtualizacao('A atualização falhou e o código anterior foi restaurado: ' . $e->getMessage(), 0, $e);
        }
        Manutencao::desligar($this->caminhos);
        $this->limpar($trabalho . '/novo');

        return new Aplicada($this->versaoAtual, $m->versao, $backup);
    }

    /** @return list<string> itens trocados */
    private function trocar(string $trabalho): array
    {
        $trocados = [];
        foreach (Pacote::ITENS as $item) {
            $novo = "{$trabalho}/novo/{$item}";
            if (!file_exists($novo)) {
                continue;
            }
            $atual = $this->caminhos->raiz . '/' . $item;
            $guardado = "{$trabalho}/anterior/{$item}";
            if (file_exists($atual) || is_link($atual)) {
                if (!@rename($atual, $guardado)) {
                    throw new ErroDeAtualizacao("Não foi possível guardar {$item} para trocar");
                }
            }
            $trocados[] = $item;
            if (!@rename($novo, $atual)) {
                throw new ErroDeAtualizacao("Não foi possível colocar a versão nova de {$item}");
            }
        }

        return $trocados;
    }

    /** @param list<string> $trocados */
    private function desfazer(string $trabalho, array $trocados): void
    {
        foreach (array_reverse($trocados) as $item) {
            $atual = $this->caminhos->raiz . '/' . $item;
            $guardado = "{$trabalho}/anterior/{$item}";
            if (file_exists($guardado)) {
                $this->remover($atual);
                @rename($guardado, $atual);
            }
        }
    }

    private function copiarBanco(string $versao): string
    {
        $banco = $this->caminhos->banco();
        if (!is_file($banco)) {
            return '';
        }
        $pasta = $this->caminhos->dados() . '/backups';
        if (!is_dir($pasta) && !@mkdir($pasta, 0750, true) && !is_dir($pasta)) {
            throw new ErroDeAtualizacao('Não foi possível criar dados/backups/');
        }
        $destino = $pasta . '/pbl4-' . $this->versaoAtual . '-antes-de-' . $versao . '-' . gmdate('Ymd-His') . '.sqlite';
        try {
            // VACUUM INTO gera uma cópia consistente mesmo com o arquivo aberto por outro processo (SQLite 3.27+).
            Conexao::sqlite($banco)->executeStatement('VACUUM INTO ' . "'" . str_replace("'", "''", $destino) . "'");
        } catch (\Throwable) {
            @unlink($destino);
            if (!@copy($banco, $destino)) {
                throw new ErroDeAtualizacao('Não foi possível copiar o banco: a atualização não começou.');
            }
        }
        @chmod($destino, 0600);

        return $destino;
    }

    private function exigirPermissoes(): void
    {
        $raiz = $this->caminhos->raiz;
        foreach (array_merge(Pacote::ITENS, ['dados']) as $item) {
            $caminho = "{$raiz}/{$item}";
            if (file_exists($caminho) && !is_writable(dirname($caminho))) {
                throw new ErroDeAtualizacao('Sem permissão para trocar o código nesta pasta. Dê permissão de escrita à pasta do PBL4 ao usuário do PHP.');
            }
        }
        if (!is_writable($raiz)) {
            throw new ErroDeAtualizacao('Sem permissão de escrita na pasta do PBL4.');
        }
    }

    private function preparar(string $trabalho): void
    {
        foreach ([$trabalho, "{$trabalho}/novo", "{$trabalho}/anterior"] as $pasta) {
            if (!is_dir($pasta) && !@mkdir($pasta, 0750, true) && !is_dir($pasta)) {
                throw new ErroDeAtualizacao('Não foi possível preparar dados/atualizacao/');
            }
        }
    }

    private function limpar(string $pasta): void
    {
        $this->remover($pasta);
    }

    private function remover(string $caminho): void
    {
        if (is_link($caminho) || is_file($caminho)) {
            @unlink($caminho);

            return;
        }
        if (!is_dir($caminho)) {
            return;
        }
        foreach (scandir($caminho) ?: [] as $nome) {
            if ($nome !== '.' && $nome !== '..') {
                $this->remover($caminho . '/' . $nome);
            }
        }
        @rmdir($caminho);
    }

    /** Roda o código NOVO num processo à parte: migra o banco e confere que ele diz a versão esperada. */
    private static function conferirComProcesso(string $raiz): void
    {
        $php = PHP_BINARY;
        foreach (['migrar', 'versao'] as $comando) {
            $proc = proc_open([$php, $raiz . '/bin/pbl', $comando], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raiz);
            if (!is_resource($proc)) {
                throw new ErroDeAtualizacao('Não foi possível iniciar o PHP para conferir a versão nova');
            }
            $saida = (string) stream_get_contents($pipes[1]);
            $erro = (string) stream_get_contents($pipes[2]);
            if (proc_close($proc) !== 0) {
                throw new ErroDeAtualizacao("O comando {$comando} da versão nova falhou: " . trim($erro . $saida));
            }
        }
    }
}

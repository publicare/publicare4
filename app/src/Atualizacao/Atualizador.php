<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

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
 *  6. se qualquer passo depois da troca falhar, devolve o código antigo. Concluída, grava `dados/atualizacao/aplicada.json`, que permite
 *     voltar atrás depois (`Reversor`, `bin/pbl reverter`) enquanto `anterior/` e a cópia do banco existirem. As migrações só acrescentam (expandir/contrair),
 *     então o código antigo continua funcionando com o banco já migrado.
 * `dados/` nunca faz parte da troca.
 */
final class Atualizador
{
    /** @var \Closure(string): void */
    private readonly \Closure $conferirNovo;

    /**
     * @param list<string> $chaves chaves públicas de produção em que se confia (hex): a de uso e, se estiver aqui, a de recuperação
     * @param list<string> $recuperacao quais chaves são de recuperação (as únicas que assinam troca de chave de uso e revogação); inclui-se na confiança
     * @param list<string> $revogadas chaves recusadas desde o nascimento do produto (soma-se às revogadas por manifesto, em `dados/config/`)
     * @param list<string> $chavesDeTeste chaves do canal `teste`, que só valem nesse canal
     * @param \Closure(string): void|null $conferirNovo recebe a raiz já trocada e lança se o código novo não funcionar (padrão: processo PHP à parte)
     */
    public function __construct(
        private readonly Caminhos $caminhos,
        private readonly Baixador $baixador,
        private readonly array $chaves = Chaves::PUBLICAS,
        private readonly string $versaoAtual = Versao::ATUAL,
        ?\Closure $conferirNovo = null,
        private readonly string $phpAtual = PHP_VERSION,
        private readonly array $recuperacao = Chaves::RECUPERACAO,
        private readonly array $revogadas = Chaves::REVOGADAS,
        private readonly array $chavesDeTeste = Chaves::TESTE,
    ) {
        $this->conferirNovo = $conferirNovo ?? self::conferirComProcesso(...);
    }

    public function verificar(string $urlDoManifesto, string $canal = 'estavel'): Verificacao
    {
        $teste = $canal === 'teste';
        $local = $teste ? new ChavesLocais() : ChavesLocais::ler($this->caminhos);
        $confiaveis = $teste ? $this->chavesDeTeste : $this->confiaveisDeProducao($local);
        if ($confiaveis === []) {
            throw new ErroDeAtualizacao('Esta instalação não tem chave de assinatura embutida: não é possível confiar em nenhuma atualização.');
        }
        $texto = $this->baixador->obter($urlDoManifesto, 65536);
        $assinatura = $this->baixador->obter($urlDoManifesto . '.sig', 4096);
        $assinou = Assinatura::quemAssinou($texto, $assinatura, $confiaveis);
        if ($assinou === null) {
            throw new ErroDeAtualizacao('A assinatura do manifesto não confere: a atualização foi recusada.');
        }
        $m = Manifesto::analisar($texto);
        if ($m->canal !== $canal) {
            throw new ErroDeAtualizacao("O manifesto é do canal {$m->canal}, e o configurado é {$canal}.");
        }
        $this->aplicarMudancaDeChaves($m, $assinou, $teste, $local);
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

    /**
     * Troca da chave de uso e revogação só valem se o manifesto foi assinado pela chave de **recuperação**; vinda da chave de uso
     * (ou do canal de teste), é recusada em vez de ignorada: quem tem só a chave de uso comprometida não pode se perpetuar.
     */
    private function aplicarMudancaDeChaves(Manifesto $m, string $assinou, bool $teste, ChavesLocais $local): void
    {
        if ($m->novaChaveDeUso === null && $m->chavesRevogadas === []) {
            return;
        }
        if ($teste || !in_array($assinou, $this->recuperacao, true)) {
            throw new ErroDeAtualizacao('Troca ou revogação de chave só vale com assinatura da chave de recuperação: a atualização foi recusada.');
        }
        foreach ($m->chavesRevogadas as $r) {
            if (in_array($r, $this->recuperacao, true)) {
                throw new ErroDeAtualizacao('Um manifesto não pode revogar a chave de recuperação.');
            }
        }
        if ($m->novaChaveDeUso !== null && in_array($m->novaChaveDeUso, array_merge($this->revogadas, $local->revogadas, $m->chavesRevogadas), true)) {
            throw new ErroDeAtualizacao('O manifesto escolhe como chave de uso uma chave revogada.');
        }
        $revogadas = array_merge($local->revogadas, $m->chavesRevogadas);
        $uso = $local->uso;
        if ($m->novaChaveDeUso !== null) {
            // a de uso que vale até aqui sai de cena: quem a tiver (vazada, por exemplo) não assina mais nada
            foreach ($this->usoEfetivo($local) as $antiga) {
                if ($antiga !== $m->novaChaveDeUso) {
                    $revogadas[] = $antiga;
                }
            }
            $uso = $m->novaChaveDeUso;
        }
        (new ChavesLocais($uso, array_values(array_unique($revogadas))))->gravar($this->caminhos);
    }

    /** @return list<string> chaves de uso em vigor: a trocada por manifesto, ou as embutidas */
    private function usoEfetivo(ChavesLocais $local): array
    {
        if ($local->uso !== null) {
            return [$local->uso];
        }

        return array_values(array_filter($this->chaves, fn (string $c): bool => !in_array($c, $this->recuperacao, true)));
    }

    /** @return list<string> uso (em vigor) + recuperação, sem as revogadas (a recuperação nunca é revogada) */
    private function confiaveisDeProducao(ChavesLocais $local): array
    {
        $revogadas = array_merge($this->revogadas, $local->revogadas);
        $todas = array_merge($this->usoEfetivo($local), $this->recuperacao);

        return array_values(array_unique(array_filter(
            $todas,
            fn (string $c): bool => in_array($c, $this->recuperacao, true) || !in_array($c, $revogadas, true),
        )));
    }

    public function aplicar(Verificacao $v, string $urlDoManifesto): Aplicada
    {
        if (!$v->disponivel || $v->manifesto === null) {
            throw new ErroDeAtualizacao($v->motivo);
        }
        $trava = Trava::obter($this->caminhos);
        try {
            return $this->aplicarTravado($v, $urlDoManifesto);
        } finally {
            Trava::soltar($trava);
        }
    }

    private function aplicarTravado(Verificacao $v, string $urlDoManifesto): Aplicada
    {
        $m = $v->manifesto ?? throw new ErroDeAtualizacao($v->motivo);
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
        $this->registrar($trabalho, $m->versao, $backup, $trocados);
        Manutencao::desligar($this->caminhos);
        $this->limpar($trabalho . '/novo');

        return new Aplicada($this->versaoAtual, $m->versao, $backup);
    }

    /**
     * Guarda o que a volta atrás (`Reversor`) precisa conferir. Falhar aqui não desfaz uma atualização que deu certo: só deixa de
     * haver como voltar atrás pelo comando (o código anterior continua em `anterior/`).
     *
     * @param list<string> $trocados
     */
    private function registrar(string $trabalho, string $para, string $backup, array $trocados): void
    {
        try {
            RegistroDaAtualizacao::gravar($this->caminhos, [
                'de' => $this->versaoAtual, 'para' => $para, 'em' => time(),
                'backup' => $backup === '' ? '' : basename($backup),
                'backup_sha256' => $backup === '' ? '' : (string) hash_file('sha256', $backup),
                'schema_antes' => $backup === '' ? null : CopiaDeBanco::versaoDoEsquema($backup),
                'schema_depois' => CopiaDeBanco::versaoDoEsquema($this->caminhos->banco()),
                'itens' => $trocados,
                'sem_anterior' => array_values(array_filter($trocados, static fn (string $i): bool => !file_exists("{$trabalho}/anterior/{$i}") && !is_link("{$trabalho}/anterior/{$i}"))),
                'revertida' => false, 'revertida_em' => 0,
            ]);
        } catch (\Throwable) {
        }
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
            CopiaDeBanco::copiar($banco, $destino);
        } catch (ErroDeAtualizacao) {
            throw new ErroDeAtualizacao('Não foi possível copiar o banco: a atualização não começou.');
        }
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

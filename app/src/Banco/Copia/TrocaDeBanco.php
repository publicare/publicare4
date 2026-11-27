<?php

declare(strict_types=1);

namespace Pbl\Banco\Copia;

use Pbl\Arquivos\GravacaoAtomica;
use Pbl\Atualizacao\Manutencao;
use Pbl\Banco\CarregadorDeConfigBanco;
use Pbl\Banco\ConfigBanco;
use Pbl\Banco\Conexao;
use Pbl\Banco\GravadorDeConfigBanco;
use Pbl\Banco\Migrador;
use Pbl\Caminhos;

/**
 * Passa o site de um banco para outro com ele já instalado e com conteúdo (ADR 0028, g): copia tudo, confere tabela a tabela
 * e só então aponta a configuração para o banco novo. O banco antigo não é tocado: `voltar()` devolve a configuração anterior.
 */
final class TrocaDeBanco
{
    /** @param array<string, string>|null $ambiente variáveis de ambiente a considerar (null = as do processo); serve aos testes */
    public function __construct(private readonly Caminhos $caminhos, private readonly ?array $ambiente = null)
    {
    }

    private function arquivoAnterior(): string
    {
        return $this->caminhos->config() . '/banco.anterior.json';
    }

    /** O banco de hoje, lido só do arquivo (as variáveis de ambiente não se trocam por aqui). */
    public function atual(): ConfigBanco
    {
        return (new CarregadorDeConfigBanco($this->caminhos, []))->carregar();
    }

    /**
     * @return list<string> motivos para não trocar; vazio = pode
     */
    public function impedimentos(ConfigBanco $destino): array
    {
        $erros = [];
        foreach (array_keys($this->ambiente ?? getenv()) as $k) {
            if (str_starts_with((string) $k, 'PBL_BANCO_')) {
                $erros[] = "A variável de ambiente {$k} define o banco e vence o arquivo de configuração: tire-a do ambiente antes de trocar.";
                break;
            }
        }
        $atual = $this->atual();
        if ($atual->driver === $destino->driver && $atual->nome === $destino->nome && $atual->host === $destino->host && $atual->porta === $destino->porta) {
            $erros[] = 'O destino é o banco que já está em uso.';
        }

        return $erros;
    }

    /**
     * Só lê: conta as linhas de cada tabela da origem e confere se o destino é alcançável e está sem conteúdo.
     *
     * @return array{tabelas: array<string, int>, destino: string}
     */
    public function simular(ConfigBanco $destino): array
    {
        $this->conferir($destino);
        $origem = Conexao::abrir($this->atual());
        $tabelas = [];
        foreach (CopiadorDeTabelas::tabelas($origem) as $t) {
            $tabelas[$t] = (int) $origem->fetchOne('SELECT COUNT(*) FROM ' . $origem->quoteSingleIdentifier($t));
        }
        $conexao = Conexao::abrir($destino);
        $migrado = $conexao->createSchemaManager()->tablesExist(['schema_versao']);

        return ['tabelas' => $tabelas, 'destino' => $migrado ? 'já tem as tabelas do Publicare (sem conteúdo)' : 'vazio: as tabelas serão criadas'];
    }

    /**
     * @param (callable(string, int): void)|null $progresso
     *
     * @return array<string, int> tabela => linhas copiadas
     */
    public function trocar(ConfigBanco $destino, ?callable $progresso = null): array
    {
        $this->conferir($destino);
        $anterior = $this->atual();
        $origem = Conexao::abrir($anterior);
        $novo = Conexao::abrir($destino);

        Manutencao::ligar($this->caminhos, 'Troca de banco de dados');
        try {
            (new Migrador($novo))->migrar();
            $copiador = new CopiadorDeTabelas($origem, $novo);
            $copiadas = $copiador->copiar($progresso);
            $divergencias = $copiador->divergencias();
            if ($divergencias !== []) {
                throw new \RuntimeException("A conferência depois da cópia achou diferenças; a configuração NÃO foi trocada:\n- " . implode("\n- ", $divergencias));
            }
            $this->guardarAnterior();
            GravadorDeConfigBanco::gravar($this->caminhos, $destino);
        } finally {
            Manutencao::desligar($this->caminhos);
        }

        return $copiadas;
    }

    /** Devolve a configuração de banco de antes da troca. O que foi gravado no banco novo depois da troca não volta. */
    public function voltar(): void
    {
        $arquivo = $this->arquivoAnterior();
        if (!is_file($arquivo)) {
            throw new \RuntimeException('Não há troca de banco para desfazer (falta banco.anterior.json).');
        }
        $conteudo = (string) file_get_contents($arquivo);
        $destino = $this->caminhos->config() . '/banco.json';
        if (trim($conteudo) === 'NENHUM') {
            @unlink($destino);
        } else {
            GravacaoAtomica::gravar($destino, $conteudo);
        }
        @unlink($arquivo);
    }

    private function conferir(ConfigBanco $destino): void
    {
        $erros = $this->impedimentos($destino);
        if ($erros !== []) {
            throw new \RuntimeException(implode("\n", $erros));
        }
    }

    private function guardarAnterior(): void
    {
        $atual = $this->caminhos->config() . '/banco.json';
        GravacaoAtomica::gravar($this->arquivoAnterior(), is_file($atual) ? (string) file_get_contents($atual) : 'NENHUM');
        @chmod($this->arquivoAnterior(), 0600);
    }
}

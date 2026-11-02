<?php

declare(strict_types=1);

namespace Pbl\Arquivos;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Metadados dos arquivos enviados (a tabela `arquivo`). Nunca grava nem lê o conteúdo do arquivo: isso é o `ArmazenamentoDeArquivos`.
 * Uma linha nunca é alterada depois de criada; a única forma de sumir é `apagar`, usada só pelo `bin/pbl limpar-uploads`.
 */
final class ArquivoRepositorio
{
    public function __construct(private readonly Connection $conexao)
    {
    }

    public function criar(
        int $siteId,
        int $objetoId,
        string $propriedade,
        string $chave,
        string $nome,
        string $extensao,
        string $mime,
        int $tamanho,
        string $sha256,
        ?int $largura,
        ?int $altura,
        bool $sanitizada,
        ?int $criadoPor,
    ): Arquivo {
        $criadoEm = gmdate('c');
        $this->conexao->insert('arquivo', [
            'site_id' => $siteId,
            'objeto_id' => $objetoId,
            'propriedade' => $propriedade,
            'chave' => $chave,
            'nome' => $nome,
            'extensao' => $extensao,
            'mime' => $mime,
            'tamanho' => $tamanho,
            'sha256' => $sha256,
            'largura' => $largura,
            'altura' => $altura,
            'sanitizada' => $sanitizada ? 1 : 0,
            'criado_em' => $criadoEm,
            'criado_por' => $criadoPor,
        ]);

        return new Arquivo((int) $this->conexao->lastInsertId(), $siteId, $objetoId, $propriedade, $chave, $nome, $extensao, $mime, $tamanho, $sha256, $largura, $altura, $sanitizada, $criadoEm, $criadoPor);
    }

    public function porId(int $id): ?Arquivo
    {
        $linha = $this->conexao->fetchAssociative('SELECT * FROM arquivo WHERE id = ?', [$id]);

        return $linha === false ? null : $this->hidratar($linha);
    }

    /** Já existe um arquivo igual (mesmo conteúdo e extensão) guardado em disco? Usado para não escrever o mesmo arquivo duas vezes. */
    public function chaveExistente(string $sha256, string $extensao): ?string
    {
        $chave = $this->conexao->fetchOne('SELECT chave FROM arquivo WHERE sha256 = ? AND extensao = ? LIMIT 1', [$sha256, $extensao]);

        return $chave === false ? null : (string) $chave;
    }

    /**
     * Um lote de linhas (por id, crescente) para o `bin/pbl limpar-uploads` varrer sem carregar tudo em memória.
     *
     * @return list<Arquivo>
     */
    public function lote(int $apartirDeId, int $tamanho = 500): array
    {
        $linhas = $this->conexao->fetchAllAssociative('SELECT * FROM arquivo WHERE id > ? ORDER BY id LIMIT ?', [$apartirDeId, $tamanho], [ParameterType::INTEGER, ParameterType::INTEGER]);

        return array_map($this->hidratar(...), $linhas);
    }

    /** Apaga a linha (não toca no arquivo em disco: quem chama decide se e quando remover o arquivo físico). */
    public function apagar(int $id): void
    {
        $this->conexao->delete('arquivo', ['id' => $id]);
    }

    /** @param array<string, mixed> $linha */
    private function hidratar(array $linha): Arquivo
    {
        return new Arquivo(
            (int) $linha['id'],
            (int) $linha['site_id'],
            (int) $linha['objeto_id'],
            (string) $linha['propriedade'],
            (string) $linha['chave'],
            (string) $linha['nome'],
            (string) $linha['extensao'],
            (string) $linha['mime'],
            (int) $linha['tamanho'],
            (string) $linha['sha256'],
            $linha['largura'] === null ? null : (int) $linha['largura'],
            $linha['altura'] === null ? null : (int) $linha['altura'],
            (int) $linha['sanitizada'] === 1,
            (string) $linha['criado_em'],
            $linha['criado_por'] === null ? null : (int) $linha['criado_por'],
        );
    }
}

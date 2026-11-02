<?php

declare(strict_types=1);

namespace Pbl\Arquivos;

use Doctrine\DBAL\Connection;
use Pbl\Conteudo\ClasseRepositorio;

/**
 * `bin/pbl limpar-uploads`: apaga arquivos que nenhum objeto (nem versão antiga) referencia mais. Nunca roda na requisição
 * (fluxo 7, ADR 0014): trocar o arquivo de uma propriedade cria um `arquivo` novo e simplesmente deixa de apontar para o
 * antigo, que seria caro (e arriscado, numa corrida) apagar na hora. Um arquivo só é órfão de verdade se **nenhuma** linha de
 * `objeto` e **nenhuma** de `objeto_versao` ainda tem o seu id numa propriedade `arquivo`/`imagem` — isso inclui o histórico,
 * porque restaurar uma versão antiga pode trazer o arquivo de volta.
 */
final class LimpadorDeUploads
{
    /** Nunca apaga um arquivo enviado há menos tempo que isto: pode ser um envio cujo formulário ainda não terminou de salvar. */
    private const CARENCIA_SEGUNDOS = 3600;

    public function __construct(
        private readonly Connection $conexao,
        private readonly ClasseRepositorio $classes,
        private readonly ArquivoRepositorio $arquivos,
        private readonly ArmazenamentoDeArquivos $armazenamento,
    ) {
    }

    /**
     * @return array{examinados: int, orfaos: int, apagados: int, bytes: int, nomes: list<string>} `nomes` só nos primeiros (para o relatório)
     */
    public function executar(bool $simular): array
    {
        $referenciados = $this->idsReferenciados();
        $limite = gmdate('c', time() - self::CARENCIA_SEGUNDOS);
        $examinados = 0;
        $apagados = 0;
        $bytes = 0;
        $nomes = [];
        $cursor = 0;
        while (($lote = $this->arquivos->lote($cursor)) !== []) {
            foreach ($lote as $arquivo) {
                $cursor = $arquivo->id;
                $examinados++;
                if (in_array($arquivo->id, $referenciados, true) || $arquivo->criadoEm > $limite) {
                    continue;
                }
                $apagados++;
                $bytes += $arquivo->tamanho;
                if (count($nomes) < 20) {
                    $nomes[] = $arquivo->nome . ' (nº ' . $arquivo->id . ')';
                }
                if (!$simular) {
                    $this->armazenamento->remover($arquivo->chave, $arquivo->extensao);
                    $this->arquivos->apagar($arquivo->id);
                }
            }
        }

        return ['examinados' => $examinados, 'orfaos' => $apagados, 'apagados' => $simular ? 0 : $apagados, 'bytes' => $bytes, 'nomes' => $nomes];
    }

    /** @return list<int> todo id de `arquivo` que ainda aparece em algum `objeto` ou `objeto_versao`, hoje ou em qualquer versão antiga */
    private function idsReferenciados(): array
    {
        $nomesDeArquivo = [];
        foreach ($this->classes->todas() as $classe) {
            foreach ($classe->propriedades as $p) {
                if (in_array($p['tipo'], ['arquivo', 'imagem'], true)) {
                    $nomesDeArquivo[$p['nome']] = true;
                }
            }
        }
        if ($nomesDeArquivo === []) {
            return [];
        }
        $ids = [];
        foreach (['objeto', 'objeto_versao'] as $tabela) {
            $cursor = 0;
            while (($linhas = $this->conexao->fetchAllAssociative("SELECT id, dados FROM {$tabela} WHERE id > ? ORDER BY id LIMIT 1000", [$cursor])) !== []) {
                foreach ($linhas as $linha) {
                    $cursor = (int) $linha['id'];
                    $dados = json_decode((string) $linha['dados'], true);
                    if (!is_array($dados)) {
                        continue;
                    }
                    foreach ($nomesDeArquivo as $nome => $_) {
                        if (isset($dados[$nome]) && (is_int($dados[$nome]) || (is_string($dados[$nome]) && ctype_digit($dados[$nome])))) {
                            $ids[(int) $dados[$nome]] = true;
                        }
                    }
                }
            }
        }

        return array_keys($ids);
    }
}

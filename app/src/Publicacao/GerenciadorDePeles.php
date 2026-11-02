<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

use Doctrine\DBAL\Connection;
use Pbl\Caminhos;
use Pbl\Linguagem\Compilador;
use Pbl\Linguagem\ErroSintaxe;

/**
 * Leitura e escrita dos moldes de `dados/peles/<pele>/<nome>.pbl` pela administração (ADR 0012, fase 5e; histórico e
 * rascunho na fase 5j).
 *
 * É a única superfície de escrita em disco da administração, então tudo é fechado: o nome da pele e o do molde passam por
 * expressões ancoradas e sem ambiguidade (`[a-z0-9_-]` / `[a-z0-9_]` com `/` só entre partes, no máximo 3 níveis), a extensão
 * é sempre `.pbl` (escolhida aqui, nunca digitada), o tamanho é limitado, o conteúdo é UTF-8 sem NUL, a pasta e o arquivo não
 * podem ser links simbólicos e o caminho real tem de ficar dentro da pasta da pele. A gravação é atômica (arquivo temporário
 * + `rename`). O conteúdo só vale se o parser da linguagem de moldes o aceitar; moldes não executam PHP (o `Gerador` só emite
 * literais e chamadas ao `Runtime`).
 *
 * **Histórico** (`pele_molde_versao`, migração 15): toda gravação, criação ou remoção de um molde guarda uma linha com o
 * conteúdo inteiro, o hash, quem fez e quando; nunca é apagado pela administração, só pela retenção (`MAX_VERSOES_POR_MOLDE`
 * mais novas por molde). **Rascunho** (`pele_molde_rascunho`): no máximo um por molde, não grava no arquivo nem gera versão;
 * publicar o rascunho é uma gravação comum (gera versão) seguida do apagamento da linha.
 */
final class GerenciadorDePeles
{
    public const MAX_BYTES = 200_000;
    public const MAX_MOLDES = 300;
    public const MAX_PELES = 100;
    public const MAX_NIVEIS = 3;

    /** Retenção do histórico (ADR 0012 §9): as versões mais novas de cada molde; as mais antigas somem sozinhas. */
    public const MAX_VERSOES_POR_MOLDE = 200;

    private readonly Compilador $compilador;

    public function __construct(private readonly Caminhos $caminhos, private readonly Connection $conexao)
    {
        // Só valida (`compilar` não grava nem executa), então o cache fica sem uso.
        $this->compilador = new Compilador($caminhos->cachePbl());
    }

    public static function nomeDePeleValido(string $nome): bool
    {
        return strlen($nome) <= 50 && preg_match('/^[a-z0-9_-]+$/D', $nome) === 1;
    }

    public static function nomeDeMoldeValido(string $nome): bool
    {
        return strlen($nome) <= 100 && preg_match('/^[a-z0-9_]++(?:\/[a-z0-9_]++){0,' . (self::MAX_NIVEIS - 1) . '}$/D', $nome) === 1;
    }

    /** @return list<string> */
    public function peles(): array
    {
        $pasta = $this->caminhos->peles();
        $nomes = [];
        foreach (is_dir($pasta) ? (scandir($pasta) ?: []) : [] as $n) {
            if (self::nomeDePeleValido($n) && is_dir("{$pasta}/{$n}") && !is_link("{$pasta}/{$n}")) {
                $nomes[] = $n;
            }
            if (count($nomes) >= self::MAX_PELES) {
                break;
            }
        }
        sort($nomes);

        return $nomes;
    }

    public function peleExiste(string $pele): bool
    {
        return self::nomeDePeleValido($pele) && $this->pastaDaPele($pele) !== null;
    }

    /**
     * Nomes dos moldes da pele (sem `.pbl`), em ordem. Ignora o que não tiver nome válido.
     *
     * @return list<string>
     */
    public function moldes(string $pele): array
    {
        $raiz = $this->pastaDaPele($pele);
        if ($raiz === null) {
            return [];
        }
        $achados = [];
        $this->varrer($raiz, '', 0, $achados);
        sort($achados);

        return $achados;
    }

    /** @param list<string> $achados */
    private function varrer(string $pasta, string $prefixo, int $nivel, array &$achados): void
    {
        foreach (scandir($pasta) ?: [] as $n) {
            if ($n === '.' || $n === '..' || is_link("{$pasta}/{$n}") || count($achados) >= self::MAX_MOLDES) {
                continue;
            }
            if (is_dir("{$pasta}/{$n}")) {
                if ($nivel < self::MAX_NIVEIS - 1 && preg_match('/^[a-z0-9_]+$/D', $n) === 1) {
                    $this->varrer("{$pasta}/{$n}", $prefixo . $n . '/', $nivel + 1, $achados);
                }
            } elseif (str_ends_with($n, '.pbl') && self::nomeDeMoldeValido($prefixo . substr($n, 0, -4))) {
                $achados[] = $prefixo . substr($n, 0, -4);
            }
        }
    }

    public function ler(string $pele, string $nome): ?string
    {
        $arquivo = $this->arquivoExistente($pele, $nome);

        return $arquivo === null ? null : (string) file_get_contents($arquivo);
    }

    /** Só o tamanho e a codificação (usado pelo rascunho, que não passa pelo parser). Null = certo. */
    public function validarBasico(string $conteudo): ?string
    {
        if (strlen($conteudo) > self::MAX_BYTES) {
            return 'O molde passa do tamanho máximo de ' . (int) (self::MAX_BYTES / 1000) . ' mil bytes.';
        }
        if (!mb_check_encoding($conteudo, 'UTF-8') || str_contains($conteudo, "\0")) {
            return 'O molde precisa ser texto UTF-8 válido, sem caractere nulo.';
        }

        return null;
    }

    /**
     * Valida o texto como molde da pele (herança incluída). Devolve null se estiver certo, ou a mensagem com a linha.
     *
     * @return array{mensagem: string, linha: int|null}|null
     */
    public function validar(string $pele, string $conteudo): ?array
    {
        $basico = $this->validarBasico($conteudo);
        if ($basico !== null) {
            return ['mensagem' => $basico, 'linha' => null];
        }
        try {
            $this->compilador->compilar($conteudo, new ResolvedorDePele($this->caminhos, $pele));
        } catch (ErroSintaxe $e) {
            return ['mensagem' => preg_replace('/ \(linha \d+\)$/', '', $e->getMessage()) ?? $e->getMessage(), 'linha' => $e->linha];
        } catch (\RuntimeException $e) {
            return ['mensagem' => $e->getMessage(), 'linha' => null];
        }

        return null;
    }

    /**
     * Grava (cria ou troca) o molde de forma atômica e guarda a versão anterior no histórico. Quem chama já validou
     * o conteúdo. `$acao` é só o que vai para o histórico (`criar`, `salvar`, `voltar`); nunca vem da requisição.
     */
    public function gravar(string $pele, string $nome, string $conteudo, ?int $usuarioId, string $acao): void
    {
        $raiz = $this->pastaDaPele($pele) ?? throw new \RuntimeException('Pele não encontrada.');
        if (!self::nomeDeMoldeValido($nome) || strlen($conteudo) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Nome ou tamanho do molde inválido.');
        }
        $partes = explode('/', $nome);
        $arquivo = array_pop($partes);
        $pasta = $raiz;
        foreach ($partes as $parte) {
            $pasta .= '/' . $parte;
            if (is_link($pasta)) {
                throw new \RuntimeException('Pasta inválida na pele.');
            }
            if (!is_dir($pasta) && !@mkdir($pasta, 0775) && !is_dir($pasta)) {
                throw new \RuntimeException('Não foi possível criar a pasta do molde.');
            }
        }
        $real = realpath($pasta);
        if ($real === false || $real !== $raiz . substr($pasta, strlen($raiz))) {
            throw new \RuntimeException('Pasta fora da pele.');
        }
        $destino = "{$pasta}/{$arquivo}.pbl";
        if (is_link($destino) || (is_dir($destino))) {
            throw new \RuntimeException('Arquivo inválido na pele.');
        }
        $temporario = "{$pasta}/.{$arquivo}." . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporario, $conteudo, LOCK_EX) === false) {
            @unlink($temporario);
            throw new \RuntimeException('Não foi possível gravar o molde.');
        }
        if (!rename($temporario, $destino)) {
            @unlink($temporario);
            throw new \RuntimeException('Não foi possível gravar o molde.');
        }
        $this->registrarVersao($pele, $nome, $conteudo, $acao, $usuarioId);
    }

    public function apagar(string $pele, string $nome, ?int $usuarioId): void
    {
        $arquivo = $this->arquivoExistente($pele, $nome) ?? throw new \InvalidArgumentException('Molde não encontrado.');
        $conteudo = (string) file_get_contents($arquivo);
        if (!unlink($arquivo)) {
            throw new \RuntimeException('Não foi possível apagar o molde.');
        }
        $this->registrarVersao($pele, $nome, $conteudo, 'apagar', $usuarioId);
    }

    // ---------------------------------------------------------------- histórico (fase 5j)

    /** Grava uma linha de histórico e aplica a retenção (as `MAX_VERSOES_POR_MOLDE` mais novas). */
    private function registrarVersao(string $pele, string $nome, string $conteudo, string $acao, ?int $usuarioId): void
    {
        $this->conexao->transactional(function () use ($pele, $nome, $conteudo, $acao, $usuarioId): void {
            $ultima = (int) $this->conexao->fetchOne('SELECT COALESCE(MAX(versao), 0) FROM pele_molde_versao WHERE pele = ? AND molde = ?', [$pele, $nome]);
            $this->conexao->insert('pele_molde_versao', [
                'pele' => $pele, 'molde' => $nome, 'versao' => $ultima + 1, 'conteudo' => $conteudo,
                'hash' => hash('sha256', $conteudo), 'acao' => $acao, 'usuario_id' => $usuarioId, 'criado_em' => gmdate('c'),
            ]);
            $limite = $ultima + 1 - self::MAX_VERSOES_POR_MOLDE;
            if ($limite > 0) {
                $this->conexao->executeStatement('DELETE FROM pele_molde_versao WHERE pele = ? AND molde = ? AND versao <= ?', [$pele, $nome, $limite]);
            }
        });
    }

    /**
     * Histórico do molde (pele + nome), da versão mais nova para a mais antiga, sem o conteúdo (lista).
     *
     * @return list<array{versao: int, hash: string, acao: string, usuario_id: int|null, criado_em: string}>
     */
    public function historico(string $pele, string $nome): array
    {
        if (!self::nomeDePeleValido($pele) || !self::nomeDeMoldeValido($nome)) {
            return [];
        }
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT versao, hash, acao, usuario_id, criado_em FROM pele_molde_versao WHERE pele = ? AND molde = ? ORDER BY versao DESC',
            [$pele, $nome],
        );

        return array_map(static fn (array $l): array => [
            'versao' => (int) $l['versao'], 'hash' => (string) $l['hash'], 'acao' => (string) $l['acao'],
            'usuario_id' => $l['usuario_id'] === null ? null : (int) $l['usuario_id'], 'criado_em' => (string) $l['criado_em'],
        ], $linhas);
    }

    /**
     * Uma versão do histórico, com o conteúdo. Null se pele/nome/versão não existem (nunca revela por que).
     *
     * @return array{versao: int, conteudo: string, hash: string, acao: string, usuario_id: int|null, criado_em: string}|null
     */
    public function versaoDoHistorico(string $pele, string $nome, int $versao): ?array
    {
        if (!self::nomeDePeleValido($pele) || !self::nomeDeMoldeValido($nome) || $versao < 1) {
            return null;
        }
        $l = $this->conexao->fetchAssociative('SELECT versao, conteudo, hash, acao, usuario_id, criado_em FROM pele_molde_versao WHERE pele = ? AND molde = ? AND versao = ?', [$pele, $nome, $versao]);
        if ($l === false) {
            return null;
        }

        return [
            'versao' => (int) $l['versao'], 'conteudo' => (string) $l['conteudo'], 'hash' => (string) $l['hash'],
            'acao' => (string) $l['acao'], 'usuario_id' => $l['usuario_id'] === null ? null : (int) $l['usuario_id'],
            'criado_em' => (string) $l['criado_em'],
        ];
    }

    // ---------------------------------------------------------------- rascunho (fase 5j)

    /**
     * O rascunho guardado do molde, se houver (não lê nem compara com o arquivo).
     *
     * @return array{conteudo: string, usuario_id: int|null, atualizado_em: string}|null
     */
    public function rascunho(string $pele, string $nome): ?array
    {
        if (!self::nomeDePeleValido($pele) || !self::nomeDeMoldeValido($nome)) {
            return null;
        }
        $l = $this->conexao->fetchAssociative('SELECT conteudo, usuario_id, atualizado_em FROM pele_molde_rascunho WHERE pele = ? AND molde = ?', [$pele, $nome]);
        if ($l === false) {
            return null;
        }

        return ['conteudo' => (string) $l['conteudo'], 'usuario_id' => $l['usuario_id'] === null ? null : (int) $l['usuario_id'], 'atualizado_em' => (string) $l['atualizado_em']];
    }

    /** Salva (cria ou troca) o rascunho do molde. Não toca no arquivo nem gera versão. */
    public function salvarRascunho(string $pele, string $nome, string $conteudo, ?int $usuarioId): void
    {
        if (!self::nomeDePeleValido($pele) || !self::nomeDeMoldeValido($nome)) {
            throw new \InvalidArgumentException('Nome de pele ou de molde inválido.');
        }
        $this->conexao->executeStatement('DELETE FROM pele_molde_rascunho WHERE pele = ? AND molde = ?', [$pele, $nome]);
        $this->conexao->insert('pele_molde_rascunho', [
            'pele' => $pele, 'molde' => $nome, 'conteudo' => $conteudo, 'usuario_id' => $usuarioId, 'atualizado_em' => gmdate('c'),
        ]);
    }

    public function apagarRascunho(string $pele, string $nome): void
    {
        $this->conexao->executeStatement('DELETE FROM pele_molde_rascunho WHERE pele = ? AND molde = ?', [$pele, $nome]);
    }

    /**
     * Outros moldes da pele que citam `$nome` entre aspas (`estende`, `incluir`): apagar `$nome` os quebraria.
     *
     * @return list<string>
     */
    public function quemUsa(string $pele, string $nome): array
    {
        $usam = [];
        foreach ($this->moldes($pele) as $outro) {
            $fonte = $outro === $nome ? null : $this->ler($pele, $outro);
            if ($fonte !== null && (str_contains($fonte, '"' . $nome . '"') || str_contains($fonte, "'" . $nome . "'"))) {
                $usam[] = $outro;
            }
        }

        return $usam;
    }

    /** Pasta real da pele (sem link simbólico e dentro de `dados/peles`), ou null. */
    private function pastaDaPele(string $pele): ?string
    {
        if (!self::nomeDePeleValido($pele)) {
            return null;
        }
        $base = realpath($this->caminhos->peles());
        $pasta = $this->caminhos->peles() . '/' . $pele;
        if ($base === false || is_link($pasta) || !is_dir($pasta)) {
            return null;
        }
        $real = realpath($pasta);

        return $real !== false && $real === $base . '/' . $pele ? $real : null;
    }

    private function arquivoExistente(string $pele, string $nome): ?string
    {
        $raiz = $this->pastaDaPele($pele);
        if ($raiz === null || !self::nomeDeMoldeValido($nome)) {
            return null;
        }
        $arquivo = "{$raiz}/{$nome}.pbl";
        if (is_link($arquivo) || !is_file($arquivo)) {
            return null;
        }
        $real = realpath($arquivo);

        return $real !== false && $real === $arquivo ? $arquivo : null;
    }
}

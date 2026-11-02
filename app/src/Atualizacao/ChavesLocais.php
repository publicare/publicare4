<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

use Pbl\Caminhos;

/**
 * O que um manifesto assinado pela chave de **recuperação** mudou nas chaves de confiança desta instalação: a chave de uso
 * atual (substitui as embutidas) e as chaves revogadas. Fica em `dados/config/chaves-confiaveis.json`, fora de
 * `dados/atualizacao/` (que o atualizador apaga). Só o `Atualizador` grava, e só depois de conferir a assinatura da recuperação.
 */
final class ChavesLocais
{
    /**
     * @param list<string> $revogadas
     */
    public function __construct(public readonly ?string $uso = null, public readonly array $revogadas = [])
    {
    }

    public static function ler(Caminhos $caminhos): self
    {
        $arquivo = $caminhos->config() . '/chaves-confiaveis.json';
        $d = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;
        if (!is_array($d)) {
            return new self();
        }
        $uso = isset($d['uso']) && is_string($d['uso']) && self::valida($d['uso']) ? $d['uso'] : null;
        $revogadas = [];
        foreach (is_array($d['revogadas'] ?? null) ? $d['revogadas'] : [] as $r) {
            if (is_string($r) && self::valida($r)) {
                $revogadas[] = $r;
            }
        }

        return new self($uso, array_values(array_unique($revogadas)));
    }

    public function gravar(Caminhos $caminhos): void
    {
        $pasta = $caminhos->config();
        if (!is_dir($pasta) && !@mkdir($pasta, 0750, true) && !is_dir($pasta)) {
            throw new ErroDeAtualizacao('Não foi possível gravar as chaves de confiança em dados/config/');
        }
        $json = json_encode(['uso' => $this->uso, 'revogadas' => $this->revogadas], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
        $tmp = $pasta . '/chaves-confiaveis.json.tmp';
        if (file_put_contents($tmp, $json) === false || !@rename($tmp, $pasta . '/chaves-confiaveis.json')) {
            throw new ErroDeAtualizacao('Não foi possível gravar as chaves de confiança em dados/config/');
        }
    }

    public static function valida(string $hex): bool
    {
        return preg_match('/^[0-9a-f]{64}$/D', $hex) === 1;
    }
}

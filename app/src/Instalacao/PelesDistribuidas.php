<?php

declare(strict_types=1);

namespace Pbl\Instalacao;

use Pbl\Caminhos;

/**
 * As peles que o PBL4 distribui (`app/recursos/peles/<pele>/`: moldes `.pbl` e `assets/`) e a cópia delas para `dados/peles/`
 * (ADR 0022). Na instalação tudo é copiado. Em instalação existente (`bin/pbl migrar`, que o atualizador roda): arquivo que
 * falta é copiado; arquivo que já existe só é trocado se for **byte a byte** uma versão que o PBL4 distribuiu antes
 * (`anteriores.json`, SHA-256) — o que a administração personalizou nunca é sobrescrito e vira um aviso.
 */
final class PelesDistribuidas
{
    public function __construct(private readonly Caminhos $caminhos)
    {
    }

    /** @return list<string> nomes das peles distribuídas */
    public function nomes(): array
    {
        $base = $this->caminhos->app() . '/recursos/peles';
        $nomes = [];
        foreach (is_dir($base) ? (scandir($base) ?: []) : [] as $n) {
            if (preg_match('/^[a-z0-9_-]+$/D', $n) === 1 && is_dir("{$base}/{$n}") && !is_link("{$base}/{$n}")) {
                $nomes[] = $n;
            }
        }
        sort($nomes);

        return $nomes;
    }

    /**
     * @param list<string>|null $apenas limita às peles citadas (null: todas)
     * @return array{gravados: list<string>, avisos: list<string>}
     */
    public function sincronizar(?array $apenas = null): array
    {
        $anteriores = $this->anteriores();
        $gravados = [];
        $avisos = [];
        foreach ($this->nomes() as $pele) {
            if ($apenas !== null && !in_array($pele, $apenas, true)) {
                continue;
            }
            $origem = $this->caminhos->app() . "/recursos/peles/{$pele}";
            foreach ($this->arquivos($origem) as $relativo) {
                $de = "{$origem}/{$relativo}";
                $para = $this->caminhos->peles() . "/{$pele}/{$relativo}";
                $chave = "{$pele}/{$relativo}";
                if (is_file($para)) {
                    $hash = (string) hash_file('sha256', $para);
                    if ($hash === hash_file('sha256', $de)) {
                        continue;
                    }
                    if (!in_array($hash, $anteriores[$chave] ?? [], true)) {
                        $avisos[] = "\"{$chave}\" foi personalizado e não foi alterado (a versão nova está em app/recursos/peles/{$chave}).";
                        continue;
                    }
                }
                if (!is_dir(dirname($para)) && !@mkdir(dirname($para), 0775, true) && !is_dir(dirname($para))) {
                    $avisos[] = "Não foi possível criar a pasta de {$chave}.";
                    continue;
                }
                if (@copy($de, $para)) {
                    $gravados[] = $chave;
                } else {
                    $avisos[] = "Não foi possível gravar {$chave}.";
                }
            }
        }

        return ['gravados' => $gravados, 'avisos' => $avisos];
    }

    /** @return list<string> caminhos relativos (`padrao.pbl`, `assets/x.css`), sem links simbólicos */
    private function arquivos(string $raiz, string $prefixo = '', int $nivel = 0): array
    {
        $lista = [];
        foreach (is_dir($raiz . '/' . $prefixo) ? (scandir($raiz . '/' . $prefixo) ?: []) : [] as $n) {
            $caminho = $raiz . '/' . $prefixo . $n;
            if ($n === '.' || $n === '..' || is_link($caminho)) {
                continue;
            }
            if (is_dir($caminho)) {
                if ($nivel < 3 && preg_match('/^[a-z0-9_-]+$/D', $n) === 1) {
                    array_push($lista, ...$this->arquivos($raiz, $prefixo . $n . '/', $nivel + 1));
                }
            } elseif (preg_match('/^[A-Za-z0-9._-]+$/D', $n) === 1) {
                $lista[] = $prefixo . $n;
            }
        }

        return $lista;
    }

    /** @return array<string, list<string>> */
    private function anteriores(): array
    {
        $arquivo = $this->caminhos->app() . '/recursos/peles/anteriores.json';
        $json = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;

        /** @var array<string, list<string>> */
        return is_array($json) ? $json : [];
    }
}

<?php

declare(strict_types=1);

namespace Pbl\Instalacao;

use Pbl\Atualizacao\Chaves;

/**
 * Gera o `instalar.php` a partir do modelo `infra/instalador/instalar.php`. É a ÚNICA forma de trocar as chaves de confiança:
 * o arquivo distribuído nunca lê chave de parâmetro, ambiente ou rede (ADR 0019). O perfil de produção reescreve o bloco CONFIG
 * com `Chaves::USO`/`Chaves::RECUPERACAO`; o perfil de teste (só para testes, em arquivo temporário, nunca distribuído) troca as
 * chaves por um par de teste e o transporte HTTPS por um que lê de uma pasta de fixtures.
 */
final class GeradorDoInstalador
{
    private const CONFIG = ['//@CONFIG-INICIO', '//@CONFIG-FIM'];
    private const TRANSPORTE = ['//@TRANSPORTE-INICIO', '//@TRANSPORTE-FIM'];

    /** @return string o instalar.php de produção (com as chaves de Chaves::*) */
    public static function producao(string $modelo, string $versaoDica): string
    {
        return self::trocar($modelo, self::CONFIG, self::config(Chaves::USO, Chaves::RECUPERACAO, 'https://atualizacoes.publicare.org', $versaoDica));
    }

    /**
     * @param list<string> $uso
     * @param list<string> $recuperacao
     * @return string um instalar.php SÓ PARA TESTE (chaves de teste e transporte por pasta de fixtures)
     */
    public static function paraTeste(string $modelo, array $uso, array $recuperacao, string $pastaDeFixtures): string
    {
        $arquivo = self::trocar($modelo, self::CONFIG, self::config($uso, $recuperacao, 'https://atualizacoes.publicare.org', 'teste'));
        $pasta = var_export($pastaDeFixtures, true);
        $transporte = <<<PHP
        function pblx_baixar(string \$url, int \$max, string \$destino = ''): string
        {
            pblx_url_permitida(\$url, PBLX_URL_BASE);
            \$arquivo = {$pasta} . '/' . basename((string) parse_url(\$url, PHP_URL_PATH));
            if (!is_file(\$arquivo)) {
                throw new PblxErro('O servidor de atualizações respondeu com um erro (código 404). Tente de novo em alguns minutos.');
            }
            if (filesize(\$arquivo) > \$max) {
                throw new PblxErro('O arquivo baixado é maior que o permitido.');
            }
            if (\$destino !== '') {
                copy(\$arquivo, \$destino);

                return '';
            }

            return (string) file_get_contents(\$arquivo);
        }
        PHP;

        return self::trocar($arquivo, self::TRANSPORTE, $transporte . "\n");
    }

    /**
     * @param list<string> $uso
     * @param list<string> $recuperacao
     */
    private static function config(array $uso, array $recuperacao, string $urlBase, string $versaoDica): string
    {
        $lista = static fn (array $chaves): string => '[' . implode(', ', array_map(static fn (string $c): string => var_export($c, true), $chaves)) . ']';

        return 'const PBLX_CHAVES_USO = ' . $lista($uso) . ";\n"
            . 'const PBLX_CHAVES_RECUPERACAO = ' . $lista($recuperacao) . ";\n"
            . 'const PBLX_URL_BASE = ' . var_export($urlBase, true) . ";\n"
            . 'const PBLX_VERSAO_DICA = ' . var_export($versaoDica, true) . ";\n";
    }

    /** @param array{string, string} $marcas */
    private static function trocar(string $modelo, array $marcas, string $novo): string
    {
        $inicio = strpos($modelo, $marcas[0] . "\n");
        $fim = strpos($modelo, $marcas[1]);
        if ($inicio === false || $fim === false || $fim < $inicio) {
            throw new \RuntimeException("O modelo do instalador não tem o bloco {$marcas[0]}");
        }
        $depoisDoInicio = $inicio + strlen($marcas[0]) + 1;

        return substr($modelo, 0, $depoisDoInicio) . $novo . substr($modelo, $fim);
    }
}

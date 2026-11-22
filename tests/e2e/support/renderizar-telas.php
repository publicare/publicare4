<?php

declare(strict_types=1);

/**
 * Renderiza as telas do painel num site descartável (só leitura) e grava um HTML por tela na pasta dada.
 * Uso: php tests/e2e/support/renderizar-telas.php <pasta-de-saida>
 * Usado pelos testes de contrato e regressão (tests/e2e/contract, tests/e2e/regression).
 */

use Pbl\Testes\Texto\TelasDoPainel;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$saida = $argv[1] ?? '';
if ($saida === '' || (!is_dir($saida) && !mkdir($saida, 0775, true))) {
    fwrite(STDERR, "Uso: php renderizar-telas.php <pasta-de-saida>\n");
    exit(2);
}
foreach ((new TelasDoPainel())->renderizar() as $nome => $html) {
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', $nome))), '-');
    file_put_contents($saida . '/' . $slug . '.html', $html);
}

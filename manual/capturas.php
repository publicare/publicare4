<?php

declare(strict_types=1);

/**
 * Gera os PEDIDOS de captura de tela do manual (formato v2 da esteira da prévia) a partir de docs/usuario/capturas.json.
 * Uso: php manual/capturas.php [pasta-de-saída]   (padrão: manual/gerado/pedidos)
 * Grava previa-pedido-1.json, previa-pedido-2.json… (8 páginas por pedido). Quem executa o navegador é a esteira, não este script;
 * as imagens que ela devolver (<id>-desktop.png) entram no manual por `bin/pbl manual-gerar --capturas=<pasta>` (renomeie para <id>.png).
 * Sem dependências, sem rede.
 */

require __DIR__ . '/../app/src/Manual/PedidoV2.php';

$raiz = dirname(__DIR__);
$lido = json_decode((string) file_get_contents($raiz . '/docs/usuario/capturas.json'), true);
if (!is_array($lido) || !is_array($lido['capturas'] ?? null)) {
    fwrite(STDERR, "docs/usuario/capturas.json inválido.\n");
    exit(1);
}
try {
    $pedidos = Pbl\Manual\PedidoV2::montar($lido['capturas']);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
$saida = $argv[1] ?? $raiz . '/manual/gerado/pedidos';
if (!is_dir($saida) && !mkdir($saida, 0755, true) && !is_dir($saida)) {
    fwrite(STDERR, "Não foi possível criar {$saida}.\n");
    exit(1);
}
foreach ($pedidos as $i => $pedido) {
    $arquivo = $saida . '/previa-pedido-' . ($i + 1) . '.json';
    file_put_contents($arquivo, json_encode($pedido, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    echo $arquivo, "\n";
}

#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Nucleo\MigracaoBanco;

echo "🔧 Executando migrações do Publicare...\n";

try {
    MigracaoBanco::executar();
    echo "✅ Migrações concluídas com sucesso.\n";
    exit(0);
} catch (Throwable $e) {
    echo "❌ Erro ao executar migrações:\n";
    echo $e->getMessage() . "\n";
    exit(1);
}

#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Seeds\SeedUsuarioPadrao;
use Seeds\SeedClassesPadrao;
use Seeds\SeedPaginaHome;

echo "🔧 Executando seeds do Publicare...\n";

try {
    SeedUsuarioPadrao::executar();
    SeedClassesPadrao::executar();
    SeedPaginaHome::executar();
    echo "✅ Seeds executados com sucesso.\n";
    exit(0);
} catch (Throwable $e) {
    echo "❌ Erro ao executar seeds:\n";
    echo $e->getMessage() . "\n";
    exit(1);
}

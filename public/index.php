<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Nucleo\MigracaoBanco;

MigracaoBanco::executar();

echo 'Olá mundo! Banco customizável preparado 🔧';

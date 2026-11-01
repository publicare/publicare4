<?php

declare(strict_types=1);

// Servidor embutido do PHP (só desenvolvimento, `php -S … public/index.php`): arquivo que existe em public/ (os estilos da
// administração em `_pbl/assets/`, por exemplo) é entregue direto por ele, como fazem o Apache (.htaccess) e o nginx (try_files).
if (PHP_SAPI === 'cli-server') {
    $estatico = realpath(__DIR__ . rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH)));
    if ($estatico !== false && $estatico !== __FILE__ && is_file($estatico) && str_starts_with($estatico, __DIR__ . DIRECTORY_SEPARATOR)
        && !str_starts_with(basename($estatico), '.')) {
        return false;
    }
}

require __DIR__ . '/../vendor/autoload.php';

use Pbl\Aplicacao;
use Pbl\Caminhos;
use Symfony\Component\HttpFoundation\Request;

$aplicacao = new Aplicacao(new Caminhos(dirname(__DIR__)));
$aplicacao->tratar(Request::createFromGlobals())->send();

<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Pbl\Aplicacao;
use Pbl\Caminhos;
use Symfony\Component\HttpFoundation\Request;

$aplicacao = new Aplicacao(new Caminhos(dirname(__DIR__)));
$aplicacao->tratar(Request::createFromGlobals())->send();

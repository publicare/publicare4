<?php

declare(strict_types=1);

namespace Pbl;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Conexao;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Conteudo\SiteRepositorio;
use Pbl\Linguagem\Compilador;
use Pbl\Publicacao\Publicador;
use Pbl\Publicacao\Renderizador;

/** Monta os serviços a partir do banco instalado. Sem container: o grafo ainda é pequeno. */
final class Servicos
{
    public readonly ClasseRepositorio $classes;
    public readonly ObjetoRepositorio $objetos;
    public readonly SiteRepositorio $sites;
    public readonly Publicador $publicador;

    public function __construct(Caminhos $caminhos, Connection $conexao)
    {
        $this->classes = new ClasseRepositorio($conexao);
        $this->objetos = new ObjetoRepositorio($conexao);
        $this->sites = new SiteRepositorio($conexao);
        $renderizador = new Renderizador($caminhos, $this->objetos, $this->classes, new Compilador($caminhos->cachePbl()));
        $this->publicador = new Publicador($caminhos, $this->objetos, $renderizador);
    }

    public static function doBancoInstalado(Caminhos $caminhos): self
    {
        if (!is_file($caminhos->banco())) {
            throw new \RuntimeException('PBL4 não está instalado. Rode: bin/pbl instalar');
        }

        return new self($caminhos, Conexao::sqlite($caminhos->banco()));
    }
}

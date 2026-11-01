<?php

declare(strict_types=1);

namespace Pbl;

use Doctrine\DBAL\Connection;
use Pbl\Acesso\Auditoria;
use Pbl\Acesso\Permissoes;
use Pbl\Acesso\UsuarioRepositorio;
use Pbl\Banco\Conexao;
use Pbl\Consulta\MotorDeConsulta;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\DominioRepositorio;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Conteudo\RedirecionamentoRepositorio;
use Pbl\Conteudo\SiteRepositorio;
use Pbl\Linguagem\Compilador;
use Pbl\Publicacao\FonteRepositorio;
use Pbl\Publicacao\Publicador;
use Pbl\Publicacao\Renderizador;

/** Monta os serviços a partir do banco instalado. Sem container: o grafo ainda é pequeno. */
final class Servicos
{
    public readonly ClasseRepositorio $classes;
    public readonly ObjetoRepositorio $objetos;
    public readonly SiteRepositorio $sites;
    public readonly MotorDeConsulta $motor;
    public readonly DominioRepositorio $dominios;
    public readonly RedirecionamentoRepositorio $redirecionamentos;
    public readonly Auditoria $auditoria;
    public readonly UsuarioRepositorio $usuarios;
    public readonly Permissoes $permissoes;
    public readonly Publicador $publicador;

    public function __construct(Caminhos $caminhos, Connection $conexao)
    {
        $this->classes = new ClasseRepositorio($conexao);
        $this->auditoria = new Auditoria($conexao);
        $this->objetos = new ObjetoRepositorio($conexao, $this->auditoria);
        $this->sites = new SiteRepositorio($conexao);
        $this->motor = new MotorDeConsulta($conexao, $this->objetos);
        $this->dominios = new DominioRepositorio($conexao);
        $this->redirecionamentos = new RedirecionamentoRepositorio($conexao);
        $this->usuarios = new UsuarioRepositorio($conexao, $this->auditoria);
        $this->permissoes = new Permissoes($conexao);
        $fonte = new FonteRepositorio($this->objetos, $this->classes, $this->motor);
        $renderizador = new Renderizador($caminhos, $fonte, $this->classes, new Compilador($caminhos->cachePbl()));
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

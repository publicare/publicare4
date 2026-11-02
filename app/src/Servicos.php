<?php

declare(strict_types=1);

namespace Pbl;

use Doctrine\DBAL\Connection;
use Pbl\Acesso\Auditoria;
use Pbl\Acesso\Csrf;
use Pbl\Acesso\LimiteDeTentativas;
use Pbl\Acesso\Segredo;
use Pbl\Acesso\SessaoRepositorio;
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
use Pbl\Publicacao\GerenciadorDePeles;
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
    public readonly GerenciadorDePeles $peles;

    private ?string $segredo = null;

    public function __construct(public readonly Caminhos $caminhos, private readonly Connection $conexao)
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
        $this->peles = new GerenciadorDePeles($caminhos);
    }

    /** Segredo do site (criado na primeira vez se ainda não existir). Lido só quando preciso, para a área pública não depender dele. */
    public function segredo(): string
    {
        return $this->segredo ??= Segredo::obter($this->caminhos);
    }

    public function sessoes(?\Closure $relogio = null): SessaoRepositorio
    {
        return new SessaoRepositorio($this->conexao, $this->usuarios, $relogio);
    }

    public function csrf(?\Closure $relogio = null): Csrf
    {
        return new Csrf($this->segredo(), $relogio);
    }

    public function limiteDeTentativas(?\Closure $relogio = null): LimiteDeTentativas
    {
        return new LimiteDeTentativas($this->conexao, $this->segredo(), $relogio);
    }

    public static function doBancoInstalado(Caminhos $caminhos): self
    {
        if (!is_file($caminhos->banco())) {
            throw new \RuntimeException('PBL4 não está instalado. Rode: bin/pbl instalar');
        }

        return new self($caminhos, Conexao::sqlite($caminhos->banco()));
    }
}

<?php

declare(strict_types=1);

namespace Pbl;

use Doctrine\DBAL\Connection;
use Pbl\Acesso\Auditoria;
use Pbl\Arquivos\ArmazenamentoDeArquivos;
use Pbl\Arquivos\ArquivoRepositorio;
use Pbl\Arquivos\ServicoDeMiniaturas;
use Pbl\Arquivos\ServicoDeUpload;
use Pbl\Acesso\Csrf;
use Pbl\Acesso\LimiteDeEnvios;
use Pbl\Acesso\LimiteDeTentativas;
use Pbl\Acesso\ModoElevado;
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
use Pbl\Formularios\FormularioParaMolde;
use Pbl\Formularios\FormularioRepositorio;
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
    public readonly FormularioRepositorio $formularios;
    public readonly ArquivoRepositorio $arquivos;

    private ?string $segredo = null;

    public function __construct(public readonly Caminhos $caminhos, private readonly Connection $conexao)
    {
        $this->classes = new ClasseRepositorio($conexao);
        $this->auditoria = new Auditoria($conexao);
        $this->objetos = new ObjetoRepositorio($conexao, $this->auditoria);
        $this->arquivos = new ArquivoRepositorio($conexao);
        $this->sites = new SiteRepositorio($conexao);
        $this->motor = new MotorDeConsulta($conexao, $this->objetos);
        $this->dominios = new DominioRepositorio($conexao);
        $this->redirecionamentos = new RedirecionamentoRepositorio($conexao);
        $this->usuarios = new UsuarioRepositorio($conexao, $this->auditoria);
        $this->permissoes = new Permissoes($conexao);
        $this->formularios = new FormularioRepositorio($this->objetos, $this->classes);
        $fonte = new FonteRepositorio($this->objetos, $this->classes, $this->motor, new FormularioParaMolde($this->formularios, fn (): Csrf => $this->csrf()), $this->sites, $this->arquivos);
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

    public function limiteDeEnvios(?\Closure $relogio = null): LimiteDeEnvios
    {
        return new LimiteDeEnvios($this->conexao, $this->segredo(), $relogio);
    }

    /** "Modo elevado" do painel (ADR 0011/0012, fase 5h): confirmação de senha para ações sensíveis. */
    public function modoElevado(?\Closure $relogio = null): ModoElevado
    {
        return new ModoElevado($this->conexao, $this->usuarios, $this->limiteDeTentativas($relogio), $this->auditoria, $relogio);
    }

    /** A conexão, para o que ainda não tem repositório próprio (hoje só `bin/pbl limpar-uploads`, que varre `objeto`/`objeto_versao` direto). */
    public function conexao(): Connection
    {
        return $this->conexao;
    }

    public function uploads(): ServicoDeUpload
    {
        return new ServicoDeUpload(new ArmazenamentoDeArquivos($this->caminhos), $this->arquivos);
    }

    public function miniaturas(): ServicoDeMiniaturas
    {
        return new ServicoDeMiniaturas($this->caminhos, new ArmazenamentoDeArquivos($this->caminhos));
    }

    public static function doBancoInstalado(Caminhos $caminhos): self
    {
        if (!is_file($caminhos->banco())) {
            throw new \RuntimeException('PBL4 não está instalado. Rode: bin/pbl instalar');
        }

        return new self($caminhos, Conexao::sqlite($caminhos->banco()));
    }
}

<?php

declare(strict_types=1);

namespace Pbl\Instalacao;

use Pbl\Acesso\Auditoria;
use Pbl\Acesso\Segredo;
use Pbl\Acesso\UsuarioRepositorio;
use Pbl\Banco\Conexao;
use Pbl\Banco\Migrador;
use Pbl\Caminhos;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\Dominio;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Conteudo\SiteRepositorio;

/**
 * Instalação mínima da fase 0: pastas de dados, banco SQLite, migrações, pele básica embutida e
 * um site de exemplo. O assistente web e a galeria de peles vêm na fase 4 (fluxo 3).
 */
final class Instalador
{
    public function __construct(private readonly Caminhos $caminhos)
    {
    }

    public function instalado(): bool
    {
        return is_file($this->trava());
    }

    /**
     * @param array{login: string, email: string, nome: string, senha: string}|null $administrador conta de administrador global criada junto
     */
    public function instalar(string $nomeDoSite, string $host, ?array $administrador = null): void
    {
        if ($this->instalado()) {
            throw new \RuntimeException('PBL4 já está instalado (apague dados/config/instalado.lock só se souber o que faz)');
        }
        $host = strtolower(trim($host));
        if (!Dominio::hostValido($host)) {
            throw new \InvalidArgumentException("Domínio inválido: {$host}");
        }
        if (trim($nomeDoSite) === '') {
            throw new \InvalidArgumentException('O nome do site não pode ser vazio');
        }
        $this->caminhos->garantirDados();
        foreach ([$this->caminhos->config(), $this->caminhos->cache(), $this->caminhos->uploads(), $this->caminhos->peles()] as $pasta) {
            if (!is_writable($pasta)) {
                throw new \RuntimeException("O PBL4 não consegue gravar em {$pasta}. Dê permissão de escrita a essa pasta e tente de novo.");
            }
        }
        Segredo::obter($this->caminhos);
        $this->copiarPeleBasica();

        $conexao = Conexao::sqlite($this->caminhos->banco());
        (new Migrador($conexao))->migrar();

        // Tudo ou nada: se algo falhar no meio, o banco volta ao estado limpo e dá para tentar de novo sem "classe já existe".
        $conexao->transactional(function () use ($conexao, $nomeDoSite, $host, $administrador): void {
            $classes = new ClasseRepositorio($conexao);
            $objetos = new ObjetoRepositorio($conexao);
            $sites = new SiteRepositorio($conexao);

            $pagina = $classes->criar('pagina', [
                ['nome' => 'resumo', 'tipo' => 'texto', 'indexavel' => true],
                ['nome' => 'corpo', 'tipo' => 'html'],
            ]);
            $site = $sites->criar($nomeDoSite, $host);
            $inicio = $objetos->criar($site->id, $pagina, null, $nomeDoSite, '', [
                'resumo' => 'Site instalado com o PBL4.',
                'corpo' => '<p>Tudo funcionando. Edite este conteúdo pelo admin (em breve).</p>',
            ]);
            if ($administrador !== null) {
                (new UsuarioRepositorio($conexao, new Auditoria($conexao)))->criar(
                    $administrador['login'], $administrador['email'], $administrador['nome'], $administrador['senha'], true, 'instalador',
                );
            }
            $sites->definirRaiz($site->id, $inicio->id);
            $objetos->criar($site->id, $pagina, $inicio->id, 'Sobre', 'sobre', [
                'resumo' => 'Sobre este site',
                'corpo' => '<p>Página de exemplo.</p>',
            ]);
        });

        file_put_contents($this->trava(), gmdate('c') . "\n");
    }

    private function trava(): string
    {
        return $this->caminhos->config() . '/instalado.lock';
    }

    private function copiarPeleBasica(): void
    {
        $origem = $this->caminhos->app() . '/recursos/peles/basica';
        $destino = $this->caminhos->peles() . '/basica';
        if (!is_dir($destino) && !mkdir($destino, 0775, true) && !is_dir($destino)) {
            throw new \RuntimeException("Não foi possível criar {$destino}");
        }
        foreach (glob($origem . '/*.pbl') ?: [] as $arquivo) {
            copy($arquivo, $destino . '/' . basename($arquivo));
        }
    }
}

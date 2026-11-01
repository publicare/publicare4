<?php

declare(strict_types=1);

namespace Pbl\Instalacao;

use Pbl\Banco\Conexao;
use Pbl\Banco\Migrador;
use Pbl\Caminhos;
use Pbl\Conteudo\ClasseRepositorio;
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

    public function instalar(string $nomeDoSite, string $host): void
    {
        if ($this->instalado()) {
            throw new \RuntimeException('PBL4 já está instalado (apague dados/config/instalado.lock só se souber o que faz)');
        }
        $this->caminhos->garantirDados();
        $this->copiarPeleBasica();

        $conexao = Conexao::sqlite($this->caminhos->banco());
        (new Migrador($conexao))->migrar();

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
        $sites->definirRaiz($site->id, $inicio->id);
        $objetos->criar($site->id, $pagina, $inicio->id, 'Sobre', 'sobre', [
            'resumo' => 'Sobre este site',
            'corpo' => '<p>Página de exemplo.</p>',
        ]);

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

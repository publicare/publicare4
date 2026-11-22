<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\Perfil;
use Pbl\Caminhos;
use Pbl\Console\Conteudo\EntradaEmTexto;
use Pbl\Console\ConteudoComando;
use Pbl\Instalacao\Instalador;
use Pbl\Servicos;
use Symfony\Component\Console\Tester\CommandTester;

/** Instalação de teste ("Site Exemplo") com contas de cada papel e atalho para rodar `bin/pbl conteudo`. */
trait ConteudoAuxiliar
{
    private string $raiz;
    private Caminhos $caminhos;
    private Servicos $s;
    private int $siteId;
    private int $inicioId;
    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->raiz = Ajuda::raizDeProjeto();
        $this->caminhos = new Caminhos($this->raiz);
        (new Instalador($this->caminhos))->instalar('Site Exemplo', 'www.exemplo.org');
        $this->s = Servicos::doBancoInstalado($this->caminhos);
        $site = $this->s->sites->porHost('www.exemplo.org');
        self::assertNotNull($site);
        $this->siteId = $site->id;
        $this->inicioId = (int) $site->objetoRaizId;
        $u = $this->s->usuarios;
        $senha = 'uma senha bem longa 2026';
        $this->ids['admin'] = $u->criar('admin', 'admin@exemplo.org', 'Admin', $senha, true)->id;
        $this->ids['editor'] = $u->criar('editor', 'editor@exemplo.org', 'Editor', $senha)->id;
        $this->ids['autor'] = $u->criar('autor', 'autor@exemplo.org', 'Autor', $senha)->id;
        $this->ids['leitor'] = $u->criar('leitor', 'leitor@exemplo.org', 'Leitor', $senha)->id;
        $this->ids['claudim'] = $u->criarContaDeServico('claudim', 'Claudim', 'teste')->id;
        $u->conceder($this->ids['editor'], $this->inicioId, Perfil::Editor);
        $u->conceder($this->ids['autor'], $this->inicioId, Perfil::Autor);
        $u->conceder($this->ids['leitor'], $this->inicioId, Perfil::Leitor);
        $u->conceder($this->ids['claudim'], $this->inicioId, Perfil::Editor);
    }

    protected function tearDown(): void
    {
        Ajuda::apagar($this->raiz);
    }

    /** Serviços novos (conexão nova), como cada execução real do CLI teria. */
    private function servicos(): Servicos
    {
        return Servicos::doBancoInstalado($this->caminhos);
    }

    /**
     * Roda `bin/pbl conteudo`.
     *
     * @param array<string, mixed> $entrada argumentos e opções do Symfony (`acao`, `alvo`, `--como`...)
     * @return array{0: int, 1: string, 2: array<string, mixed>} código, saída, JSON decodificado (vazio se a saída não for JSON)
     */
    private function cli(array $entrada, string $stdin = ''): array
    {
        $t = new CommandTester(new ConteudoComando($this->caminhos, new EntradaEmTexto($stdin)));
        $codigo = $t->execute($entrada + ['--site' => 'www.exemplo.org']);
        $saida = $t->getDisplay();
        $json = json_decode($saida, true);

        /** @var array<string, mixed> $decodificado */
        $decodificado = is_array($json) ? $json : [];

        return [$codigo, $saida, $decodificado];
    }

    /** O objeto como está no banco agora (conexão nova); falha o teste se não existir. */
    private function objeto(int $id): \Pbl\Conteudo\Objeto
    {
        $o = $this->servicos()->objetos->porId($id);
        self::assertNotNull($o);

        return $o;
    }

    /** Cria uma página (privada) direto pelo repositório e devolve o id. */
    private function pagina(string $titulo, string $url, string $status = 'privado', ?int $pai = null, ?int $criadoPor = null): int
    {
        $classe = $this->s->classes->porNome('pagina');
        self::assertNotNull($classe);

        return $this->s->objetos->criarDentro($this->siteId, $classe, $pai ?? $this->inicioId, $titulo, $url, ['corpo' => '<p>Texto</p>'], $status, ['criado_por' => $criadoPor])->id;
    }
}

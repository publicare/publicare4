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
use Pbl\Conteudo\ConfigDoSiteRepositorio;
use Pbl\Conteudo\ClassesPadrao;
use Pbl\Conteudo\Dominio;
use Pbl\Conteudo\Modelos;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Conteudo\SiteRepositorio;

/**
 * Instalação mínima da fase 0: pastas de dados, banco SQLite, migrações, pele básica embutida e
 * um site de exemplo com o pacote mínimo de tipos de conteúdo (ADR 0015). O assistente web e a galeria de peles vêm na fase 4 (fluxo 3).
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
     * @param list<string> $modelos modelos opcionais a ativar já na instalação (`Modelos::NOMES`, ADR 0021)
     */
    public function instalar(string $nomeDoSite, string $host, ?array $administrador = null, array $modelos = []): void
    {
        $modelos = array_values(array_unique($modelos));
        foreach ($modelos as $modelo) {
            if (!in_array($modelo, Modelos::NOMES, true)) {
                throw new \InvalidArgumentException("Modelo desconhecido: {$modelo}. Os modelos são: " . implode(', ', Modelos::NOMES));
            }
        }
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
        $conexao->transactional(function () use ($conexao, $nomeDoSite, $host, $administrador, $modelos): void {
            $classes = new ClasseRepositorio($conexao);
            $objetos = new ObjetoRepositorio($conexao);
            $sites = new SiteRepositorio($conexao);

            // Pacote mínimo de tipos de conteúdo (ADR 0015); `formulario` já veio da migração 11.
            ClassesPadrao::garantir($classes);
            $pagina = $classes->porNome('pagina') ?? throw new \RuntimeException('Tipo pagina não existe');
            $pasta = $classes->porNome('pasta') ?? throw new \RuntimeException('Tipo pasta não existe');
            $noticia = $classes->porNome('noticia') ?? throw new \RuntimeException('Tipo noticia não existe');
            $formulario = $classes->porNome('formulario') ?? throw new \RuntimeException('Tipo formulario não existe');

            $site = $sites->criar($nomeDoSite, $host);
            $inicio = $objetos->criar($site->id, $pagina, null, $nomeDoSite, '', [
                'resumo' => 'Site instalado com o PBL4.',
                'corpo' => '<p>Tudo funcionando. Edite este conteúdo pelo painel de administração.</p>',
            ]);
            if ($administrador !== null) {
                (new UsuarioRepositorio($conexao, new Auditoria($conexao)))->criar(
                    $administrador['login'], $administrador['email'], $administrador['nome'], $administrador['senha'], true, 'instalador',
                );
            }
            $sites->definirRaiz($site->id, $inicio->id);
            (new ConfigDoSiteRepositorio($conexao))->definir($site->id, ['nome' => $nomeDoSite, 'idioma' => 'pt-BR']);

            // Conteúdo de exemplo: serve de modelo e se edita ou apaga pelo painel.
            $objetos->criar($site->id, $pagina, $inicio->id, 'Sobre', 'sobre', [
                'resumo' => 'Sobre este site',
                'corpo' => '<p>Página de exemplo. Conte aqui quem você é e o que o site oferece.</p>',
            ], 'publicado', ['peso' => 10, 'no_menu' => true]);
            $noticias = $objetos->criar($site->id, $pasta, $inicio->id, 'Notícias', 'noticias', [], 'publicado', ['peso' => 20, 'descricao' => 'Novidades e avisos', 'no_menu' => true]);
            $objetos->criar($site->id, $noticia, $noticias->id, 'Bem-vindo ao novo site', 'bem-vindo-ao-novo-site', [
                'resumo' => 'Nossa primeira notícia.',
                'corpo' => '<p>Esta é uma notícia de exemplo. Edite-a ou apague-a pelo painel de administração.</p>',
            ]);

            // Contato: o formulário (tipo fixo `formulario`) recebe as mensagens como conteúdo privado numa pasta que o público não vê.
            $caixa = $objetos->criar($site->id, $pasta, $inicio->id, 'Mensagens recebidas', 'mensagens-recebidas', [], 'privado', ['descricao' => 'Mensagens enviadas pelo formulário de contato']);
            $objetos->criar($site->id, $formulario, $inicio->id, 'Contato', 'contato', [
                'corpo' => '<p>Escreva para nós. Respondemos assim que possível.</p>',
                'destino' => $caixa->id,
                'classe_envio' => 'mensagem_contato',
                'campos' => 'nome,email,mensagem',
                'mensagem' => 'Recebemos a sua mensagem. Obrigado!',
                'botao' => 'Enviar mensagem',
            ], 'publicado', ['peso' => 30, 'no_menu' => true]);

            // Modelos opcionais escolhidos (ADR 0021): classes, exemplos e moldes, na mesma transação do resto.
            $ativador = new Modelos($conexao, $this->caminhos);
            foreach ($modelos as $modelo) {
                $ativador->ativar($modelo, $site->id);
            }
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

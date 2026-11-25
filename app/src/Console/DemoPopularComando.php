<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Acesso\Perfil;
use Pbl\Acesso\Usuario;
use Pbl\Caminhos;
use Pbl\Conteudo\Modelos;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\Site;
use Pbl\Demo\ConteudoPublicare;
use Pbl\Instalacao\Instalador;
use Pbl\Instalacao\PelesDistribuidas;
use Pbl\Servicos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Popula um site com o site de exemplo do próprio Publicare 4 (conteúdo real de `ConteudoPublicare`; só as contas e os e-mails de
 * demonstração são fictícios), para demo, desenvolvimento e testes de aparência (ADR 0021, adendo). Ativa os quatro modelos opcionais,
 * troca a pele do site para a `publicare` (se ainda for a básica), cria páginas, guias atemporais, perguntas, documentos e imagens, e publica. Idempotente (cada conteúdo é identificado pelo endereço) e recusa sites que já têm conteúdo demais.
 */
#[AsCommand(name: 'demo-popular', description: 'Popula um site com o site de exemplo do Publicare 4 (apresentação do produto): só para desenvolvimento e demos')]
final class DemoPopularComando extends Command
{
    private const LIMITE = 30;
    /** Senha das contas de demonstração (`autor` e `editor`): SÓ para demo e desenvolvimento, nunca em produção. Tem o mínimo que a política de senhas exige. */
    public const SENHA_DEMO = 'demo-2026-pbl4';

    private Modelos $m;
    private Servicos $servicos;
    private Site $site;
    private int $raiz;

    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('site', null, InputOption::VALUE_REQUIRED, 'Número do site (padrão: o primeiro)');
        $this->addOption('forcar', null, InputOption::VALUE_NONE, 'Popula mesmo que o site já tenha mais de ' . self::LIMITE . ' conteúdos');
        $this->addOption('pele', null, InputOption::VALUE_REQUIRED, 'Pele do exemplo (padrão: a ' . PelesDistribuidas::PADRAO . ', se o site ainda usa a básica; `--pele=basica` mantém a básica)');
        $this->addOption('sem-publicar', null, InputOption::VALUE_NONE, 'Não publica o site no final');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->servicos = Servicos::doBancoInstalado($this->caminhos);
            $siteOpcao = $input->getOption('site');
            $site = is_numeric($siteOpcao) ? $this->servicos->sites->porId((int) $siteOpcao) : ($this->servicos->sites->todos()[0] ?? null);
            if ($site === null || $site->objetoRaizId === null) {
                throw new \InvalidArgumentException('Site não encontrado');
            }
            $this->site = $site;
            $this->raiz = $site->objetoRaizId;
            $existentes = (int) $this->servicos->conexao()->fetchOne("SELECT COUNT(*) FROM objeto WHERE site_id = ? AND status <> 'apagado'", [$site->id]);
            if ($existentes > self::LIMITE && !$input->getOption('forcar')) {
                throw new \RuntimeException("O site {$site->id} já tem {$existentes} conteúdos: o demo é para sites novos ou de teste. Use --forcar se tem certeza.");
            }
            $this->site = $this->usarPeleDoExemplo($site, (string) ($input->getOption('pele') ?? ''));
            $this->m = new Modelos($this->servicos->conexao(), $this->caminhos);
            foreach (Modelos::NOMES as $modelo) {
                $this->m->ativar($modelo, $site->id, false);
            }
            $this->servicos->conexao()->transactional(function (): void {
                $this->popular();
            });
            $output->writeln('Demo criado: ' . $this->m->criados() . ' conteúdo(s) novo(s).');
            $output->writeln('Contas de demonstração: autor e editor (senha ' . self::SENHA_DEMO . ', só para demo; apague as contas se este site for para produção).');
            if (!$input->getOption('sem-publicar')) {
                $n = $this->servicos->publicador->publicarSite($this->site);
                $output->writeln("{$site->nome}: {$n} página(s) publicada(s)");
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /** O exemplo mostra a pele `publicare` (ou a pedida em `--pele`): copia a pele, sem sobrescrever o que foi personalizado, e a escolhe. Peles de site próprias (que não são as distribuídas) ficam como estão. */
    private function usarPeleDoExemplo(Site $site, string $pedida): Site
    {
        $pele = $pedida !== '' ? $pedida : ($site->pele === 'basica' ? PelesDistribuidas::PADRAO : $site->pele);
        if ($pedida !== '' && !in_array($pele, PelesDistribuidas::COM_MODELOS, true)) {
            throw new \InvalidArgumentException("A pele do exemplo precisa trazer os moldes dos modelos opcionais: " . implode(' ou ', PelesDistribuidas::COM_MODELOS));
        }
        if (in_array($pele, PelesDistribuidas::COM_MODELOS, true)) {
            (new PelesDistribuidas($this->caminhos))->sincronizar([$pele]);
        }
        if ($pele !== $site->pele && is_file($this->caminhos->peles() . '/' . $pele . '/padrao.pbl')) {
            $this->servicos->sites->definirPele($site->id, $pele);
        }

        return $this->servicos->sites->porId($site->id) ?? $site;
    }

    private function popular(): void
    {
        $criar = fn (string $classe, ?int $pai, string $titulo, string $url, array $dados = [], string $status = 'publicado', array $meta = [], ?\Closure $apos = null): Objeto
            => $this->m->garantirObjeto($this->site, $classe, $pai, $titulo, $url, $dados, $status, $meta, $apos);
        $pagina = static fn (string $resumo, string $corpo): array => ['resumo' => $resumo, 'corpo' => $corpo];
        $objetos = $this->servicos->objetos;

        $this->servicos->configs->definir($this->site->id, ['slogan' => ConteudoPublicare::PROMESSA, 'descricao' => ConteudoPublicare::PROMESSA]);

        // --- Página inicial -------------------------------------------------------------------------------------------
        $inicio = $objetos->porId($this->raiz);
        if ($inicio !== null && str_contains((string) ($inicio->dados['corpo'] ?? ''), Instalador::MARCA_DO_INICIO)) {
            $objetos->atualizar($inicio->id, ['titulo' => ConteudoPublicare::NOME_DO_SITE, 'dados' => ConteudoPublicare::inicio()], 'Demo');
        }

        // --- Chamadas da home (botões do hero e da faixa final) e pilares ------------------------------------------------
        $chamadas = $criar('pasta', $this->raiz, 'Pronto para experimentar?', 'chamadas', [], 'publicado', ['peso' => 990, 'descricao' => 'Instale em poucos minutos ou veja o que o Publicare 4 faz.']);
        $paginas = ConteudoPublicare::paginas();
        $ids = [];
        foreach ($paginas as $url => $p) {
            $ids[$url] = $criar('pagina', $this->raiz, $p['titulo'], $url, $pagina($p['resumo'], $p['corpo']), 'publicado', ['peso' => $p['peso'], 'no_menu' => true, 'rotulo_menu' => $p['rotulo'], 'descricao' => $p['descricao']]);
        }
        // Na instalação leve estes atalhos já existem, apontando para a documentação no GitHub: aqui passam a apontar para as páginas do exemplo.
        $ligar = function (string $url, int $pai, string $titulo, int $destino, int $peso, string $descricao = '') use ($criar): void {
            $dados = ['destino_interno' => $destino, 'destino_externo' => null, 'nova_aba' => false];
            $meta = ['peso' => $peso] + ($descricao === '' ? [] : ['descricao' => $descricao]);
            $existente = $this->servicos->objetos->porUrl($this->site->id, $url);
            if ($existente === null) {
                $criar('link', $pai, $titulo, $url, ['destino_interno' => $destino], 'publicado', $meta);
            } elseif (($existente->dados['destino_externo'] ?? '') !== '') {
                $this->servicos->objetos->atualizar($existente->id, ['titulo' => $titulo, 'dados' => $dados] + $meta, 'Demo');
            }
        };
        $ligar('chamada-instalar', $chamadas->id, 'Como instalar', $ids['instalacao-e-requisitos']->id, 10);
        $ligar('chamada-demonstracao', $chamadas->id, 'O que ele faz', $ids['o-que-ele-faz']->id, 20);
        $criar('link', $chamadas->id, 'Código no GitHub', 'chamada-github', ['destino_externo' => ConteudoPublicare::REPOSITORIO, 'nova_aba' => true], 'publicado', ['peso' => 30]);
        foreach (ConteudoPublicare::pilares() as $i => $pilar) {
            $ligar($pilar['url'], $this->raiz, $pilar['titulo'], $ids[$pilar['destino']]->id, ($i + 1) * 10, $pilar['descricao']);
        }

        // --- Sobre este exemplo (página criada pela instalação) e contato -----------------------------------------------
        $sobre = $objetos->porUrl($this->site->id, 'sobre');
        if ($sobre !== null && str_contains((string) ($sobre->dados['corpo'] ?? ''), 'Página de exemplo')) {
            $objetos->atualizar($sobre->id, [
                'titulo' => 'Sobre este exemplo', 'no_menu' => false, 'peso' => 900, 'descricao' => 'Como este site foi feito',
                'dados' => $pagina('Este site foi montado com o próprio Publicare 4.', '<p>Este é o <strong>site de exemplo</strong> do Publicare 4. Ele foi criado pelo comando <code>php bin/pbl demo-popular</code> e usa a pele <strong>publicare</strong>, que acompanha o programa.</p><p>Tudo aqui se edita pelo painel de administração: páginas, perguntas frequentes, notícias, agenda e blocos. As contas de demonstração e os e-mails <code>@exemplo.invalid</code> são fictícios.</p><p>Se este site for para produção, apague as contas de demonstração e o conteúdo de exemplo.</p>'),
            ], 'Demo');
        }
        $contato = $objetos->porUrl($this->site->id, 'contato');
        if ($contato !== null && $contato->paiId === $this->raiz && isset($ids['comunidade'])) {
            $objetos->mover($contato->id, $ids['comunidade']->id, 'Demo');
        }

        // --- Destaques da página inicial (imagens geradas) -----------------------------------------------------------
        $pastaDestaques = $criar('pasta', $this->raiz, 'Destaques', 'destaques', [], 'publicado', ['peso' => 995, 'descricao' => 'Banners da página inicial']);
        foreach ([
            ['conhecer', 'Veja o que ele faz', 'Conteúdo em árvore, moldes, revisão, domínios, busca e cópias de segurança.', [194, 65, 12], 'o-que-ele-faz'],
            ['instalar', 'Instale e experimente', 'Requisitos do servidor e caminhos de instalação.', [232, 89, 12], 'instalacao-e-requisitos'],
            ['participar', 'Participe do projeto', 'Você não precisa programar para ajudar.', [255, 154, 60], 'comunidade'],
        ] as $i => [$u, $t, $txt, $cor, $alvo]) {
            $criar('destaque', $pastaDestaques->id, $t, 'destaque-' . $u, ['texto' => $txt, 'nova_aba' => false, 'destino_interno' => $ids[$alvo]->id], 'publicado', ['peso' => ($i + 1) * 10],
                $this->imagem('imagem', $t, $cor, 'destaque-' . $u . '.png', 1200, 500, $u));
        }

        // --- Guias atemporais (tipo "noticia", sem data de publicação: nada que envelheça) (a pasta "Notícias" vem da instalação) ----------
        $guias = $objetos->porUrl($this->site->id, 'noticias');
        if ($guias === null) {
            $guias = $criar('pasta', $this->raiz, 'Conceitos e guias', 'noticias', [], 'publicado', ['peso' => 20, 'no_menu' => true, 'descricao' => 'Conceitos do Publicare 4, explicados em poucas linhas']);
        } elseif ($guias->titulo === 'Notícias') {
            $guias = $objetos->atualizar($guias->id, ['titulo' => 'Conceitos e guias', 'descricao' => 'Conceitos do Publicare 4, explicados em poucas linhas', 'peso' => 10], 'Demo');
        }
        $acompanhar = $ids['como-acompanhar'];
        if ($guias->paiId === $this->raiz) {
            $objetos->mover($guias->id, $acompanhar->id, 'Demo');
        }
        foreach (ConteudoPublicare::guias() as $i => $g) {
            $criar('noticia', $guias->id, $g['titulo'], $g['url'], ['resumo' => $g['resumo'], 'corpo' => $g['corpo']], 'publicado', ['peso' => ($i + 1) * 10, 'tags' => $g['tags']]);
        }
        $bemVindo = $objetos->porUrl($this->site->id, 'bem-vindo-ao-novo-site');
        if ($bemVindo !== null && str_contains((string) ($bemVindo->dados['corpo'] ?? ''), 'notícia de exemplo')) {
            $objetos->atualizar($bemVindo->id, ['titulo' => 'Este site é o exemplo do Publicare 4', 'peso' => 900, 'dados' => [
                'resumo' => 'Foi montado com o próprio programa e se refaz com um comando.',
                'corpo' => '<p>Este site foi criado pelo comando <code>php bin/pbl demo-popular</code>. Ele serve para ver o Publicare 4 funcionando e para testar a aparência das peles.</p>',
            ]], 'Demo');
        }
        $criar('noticia', $guias->id, 'Rascunho: guia de estilo', 'rascunho-guia-de-estilo', ['resumo' => 'Texto ainda em revisão.', 'corpo' => '<p>Rascunho não publicado (estado privado).</p>'], 'privado');

        // --- Perguntas frequentes (modelo "faq") ---------------------------------------------------------------------------
        $faq = $criar('perguntas', $this->raiz, 'Perguntas frequentes', 'perguntas-frequentes', [], 'publicado', ['peso' => 50, 'no_menu' => true, 'rotulo_menu' => 'Perguntas', 'descricao' => 'Licença, requisitos, instalação, atualização, backups, migração, acessibilidade e segurança']);
        foreach (ConteudoPublicare::perguntas() as $i => [$p, $r, $cat]) {
            $criar('faq', $faq->id, $p, 'faq-' . ($i + 1), ['resposta' => $r, 'categoria' => $cat], 'publicado', ['peso' => ($i + 1) * 10]);
        }

        // --- Documentos e imagens (arquivos e texto alternativo) -------------------------------------------------------------
        $comunidade = $ids['comunidade'];
        $docs = $criar('pasta', $comunidade->id, 'Documentos', 'documentos', [], 'publicado', ['peso' => 20, 'no_menu' => false, 'descricao' => 'Arquivos para baixar e imagens de exemplo']);
        $requisitos = "REQUISITOS DO PUBLICARE 4\n\nOs requisitos atuais (versao do PHP e extensoes) estao em docs/usuario/instalar.md e sao conferidos pelo assistente de instalacao.\nA pasta dados/ precisa ser gravavel pelo PHP.\n";
        foreach ([
            ['requisitos', 'Requisitos do servidor (texto)', $requisitos, 'txt'],
            ['resumo-em-pdf', 'Resumo do Publicare 4 (PDF)', $this->pdfMinimo('Publicare 4 - plataforma livre para sites'), 'pdf'],
        ] as $i => [$u, $t, $conteudo, $ext]) {
            $criar('arquivo', $docs->id, $t, $u, [], 'publicado', ['peso' => ($i + 1) * 10, 'descricao' => 'Arquivo de exemplo (' . strtoupper($ext) . ')'], $this->arquivo('arquivo', $conteudo, $u . '.' . $ext, $ext === 'pdf' ? 'application/pdf' : 'text/plain'));
        }
        $galeria = $criar('pasta', $docs->id, 'Imagens de exemplo', 'galeria-de-imagens', [], 'publicado', ['peso' => 40, 'descricao' => 'Imagens geradas pelo comando, cada uma com texto alternativo']);
        foreach ([[194, 65, 12], [232, 89, 12], [255, 154, 60]] as $i => $cor) {
            $criar('imagem', $galeria->id, 'Imagem ' . ($i + 1), 'galeria-imagem-' . ($i + 1), ['legenda' => 'Ilustração ' . ($i + 1), 'texto_alternativo' => 'Ilustração abstrata em formas suaves e tons de laranja e âmbar, número ' . ($i + 1)], 'publicado', ['peso' => ($i + 1) * 10],
                $this->imagem('imagem', 'Imagem ' . ($i + 1), $cor, 'galeria-' . ($i + 1) . '.png', 1200, 800, 'g' . $i));
        }

        // --- Links úteis ---------------------------------------------------------------------------------------------------
        $links = $criar('pasta', $comunidade->id, 'Links úteis', 'links-uteis', [], 'publicado', ['peso' => 30, 'no_menu' => false, 'descricao' => 'Atalhos internos e externos']);
        $criar('link', $links->id, 'Como acompanhar o projeto', 'link-acompanhar', ['destino_interno' => $acompanhar->id], 'publicado', ['peso' => 10, 'descricao' => 'Atalho interno']);
        $criar('link', $links->id, 'Código no GitHub', 'link-github', ['destino_externo' => ConteudoPublicare::REPOSITORIO, 'nova_aba' => true], 'publicado', ['peso' => 20, 'descricao' => 'Atalho externo, abre em nova aba']);
        $criar('link', $links->id, 'CHANGELOG', 'link-changelog', ['destino_externo' => ConteudoPublicare::REPOSITORIO . '/blob/main/CHANGELOG.md'], 'publicado', ['peso' => 30, 'descricao' => 'O que mudou em cada versão']);

        // --- Blocos: aviso no topo e rodapé -----------------------------------------------------------------------------------
        $blocos = $criar('pasta', $this->raiz, 'Blocos', 'blocos', [], 'publicado', ['peso' => 996, 'descricao' => 'Trechos reutilizáveis']);
        $criar('bloco', $blocos->id, 'Aviso', 'bloco-aviso', ['corpo' => '<p>Site de exemplo do Publicare 4: o conteúdo mostra o produto, e as contas e os e-mails de demonstração são fictícios.</p>']);
        $criar('bloco', $blocos->id, 'Rodapé', 'bloco-rodape', ['corpo' => '<p>O Publicare 4 é software livre, sob a licença Apache-2.0. <a href="' . ConteudoPublicare::REPOSITORIO . '">Código-fonte no GitHub</a> · <a href="/comunidade">Comunidade</a> · <a href="/contato">Contato</a> · <a href="/sobre">Sobre este exemplo</a></p>']);

        $this->atalhosUteis($criar);
        $this->fluxoDeRevisao($comunidade);
    }

    /**
     * "Veja também" na página inicial.
     *
     * Um site, uma árvore de objetos: trocar a pele não cria objetos (a pele onepage virá depois, renderizando esta mesma árvore).
     *
     * @param \Closure(string, ?int, string, string, array<string, string|int|float|bool>, string, array<string, mixed>, ?\Closure=): Objeto $criar
     */
    private function atalhosUteis(\Closure $criar): void
    {
        // Links publicados direto sob a página inicial (molde home), depois dos quatro pilares; a ordem dos ícones acompanha pele.css.
        $docs = ConteudoPublicare::REPOSITORIO . '/blob/main/';
        $uteis = [
            ['link-atualizar', 'Como atualizar', 'Atualização assinada, com cópia do banco e volta atrás: o passo a passo.', ['destino_externo' => $docs . 'docs/usuario/atualizar.md', 'nova_aba' => true], 50],
            ['link-documentacao', 'Documentação do usuário', 'Guias para quem edita e para quem administra o site.', ['destino_externo' => ConteudoPublicare::REPOSITORIO . '/tree/main/docs/usuario', 'nova_aba' => true], 60],
            ['link-linguagem', 'Linguagem de moldes', 'Como escrever peles e moldes .pbl, em português.', ['destino_externo' => $docs . 'docs/linguagem-pbl4.md', 'nova_aba' => true], 70],
            ['link-seguranca', 'Segurança', 'Como relatar uma falha de segurança, em privado.', ['destino_externo' => $docs . 'SECURITY.md', 'nova_aba' => true], 100],
        ];
        foreach ($uteis as [$url, $titulo, $texto, $dados, $peso]) {
            $existente = $this->servicos->objetos->porUrl($this->site->id, $url);
            if ($existente === null) {
                $criar('link', $this->raiz, $titulo, $url, $dados, 'publicado', ['peso' => $peso, 'descricao' => $texto]);
            } elseif ($existente->paiId !== $this->raiz) {
                $this->servicos->objetos->atualizar($existente->id, ['titulo' => $titulo, 'descricao' => $texto, 'peso' => $peso], 'Demo');
                $this->servicos->objetos->mover($existente->id, $this->raiz, 'Demo');
            }
        }
    }

    /**
     * Fluxo de revisão (fase 6): duas contas, `autor` e `editor`, com acesso só à seção Comunidade, e dois envios de exemplo
     * (um pendente na fila do editor, um devolvido com motivo). Idempotente: conta que já existe fica como está e os exemplos só
     * mudam de estado enquanto ainda não têm histórico de revisão.
     */
    private function fluxoDeRevisao(Objeto $area): void
    {
        $contas = [];
        foreach (['autor' => ['Autora de demonstração', Perfil::Autor], 'editor' => ['Editor de demonstração', Perfil::Editor]] as $login => [$nome, $perfil]) {
            $conta = $this->servicos->usuarios->porLogin($login);
            if ($conta === null) {
                $conta = $this->servicos->usuarios->criar($login, $login . '@exemplo.invalid', $nome, self::SENHA_DEMO, false, 'demo');
                $this->servicos->usuarios->conceder($conta->id, $area->id, $perfil, 'demo');
            }
            $contas[$login] = $conta;
        }
        /** @var array{autor: Usuario, editor: Usuario} $contas */
        $exemplos = [
            ['envio-pendente-guia-de-estilo', 'Guia de estilo da documentação (aguardando revisão)', 'Texto enviado pela autora de exemplo e aguardando o editor.'],
            ['envio-devolvido-texto-de-contribuicao', 'Texto sobre contribuição (devolvido)', 'Texto devolvido pelo editor com um pedido de ajuste.'],
        ];
        foreach ($exemplos as $i => [$url, $titulo, $resumo]) {
            $o = $this->m->garantirObjeto($this->site, 'pagina', $area->id, $titulo, $url, ['resumo' => $resumo, 'corpo' => '<p>' . $resumo . '</p>'], 'privado', ['criado_por' => $contas['autor']->id, 'peso' => 900 + $i]);
            if ($o->status !== 'privado' || $this->servicos->revisao->historico($o->id, 1) !== []) {
                continue;
            }
            $this->servicos->revisao->enviar($contas['autor'], $o->id);
            if ($i === 1) {
                $this->servicos->revisao->devolver($contas['editor'], $o->id, 'Faltou citar a fonte no repositório e revisar o último parágrafo. Ajuste e reenvie.');
            }
        }
    }

    /**
     * Imagem PNG colorida com texto (GD sem FreeType: fonte embutida, sem acentos), enviada como upload na criação do objeto.
     *
     * @param array{0: int, 1: int, 2: int} $cor
     */
    private function imagem(string $campo, string $texto, array $cor, string $nome, int $largura, int $altura, string $semente = ''): \Closure
    {
        $conteudo = $this->png($texto, $cor, $largura, $altura);

        return $this->arquivo($campo, $conteudo, $nome, 'image/png');
    }

    private function arquivo(string $campo, string $conteudo, string $nome, string $mime): \Closure
    {
        $propriedade = ['nome' => $campo, 'tipo' => $mime === 'image/png' ? 'imagem' : 'arquivo'];

        return function (int $novoId) use ($conteudo, $nome, $mime, $propriedade, $campo): array {
            $tmp = tempnam(sys_get_temp_dir(), 'pbl-demo');
            if ($tmp === false || file_put_contents($tmp, $conteudo) === false) {
                throw new \RuntimeException('Não foi possível gravar o arquivo temporário do demo');
            }
            try {
                $arquivo = $this->servicos->uploads()->enviar(new UploadedFile($tmp, $nome, $mime, null, true), $propriedade, $this->site->id, $novoId, null);
            } finally {
                @unlink($tmp);
            }

            return [$campo => $arquivo->id];
        };
    }

    /**
     * PNG de demonstração, nítido e sem texto (a fonte embutida do GD não tem acentos): gradiente da cor dada para uma versão mais
     * escura, mais formas translúcidas cuja posição vem do `$texto` (mesmo título, mesma imagem). Desenhada no dobro do tamanho e
     * reduzida, para as bordas ficarem suaves.
     *
     * @param array{0: int, 1: int, 2: int} $cor
     */
    private function png(string $texto, array $cor, int $largura, int $altura): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('A extensão GD é necessária para gerar as imagens do demo');
        }
        $largura = max(1, $largura);
        $altura = max(1, $altura);
        $esc = 2;
        $w = $largura * $esc;
        $h = $altura * $esc;
        [$r, $g, $b] = [max(0, min(255, $cor[0])), max(0, min(255, $cor[1])), max(0, min(255, $cor[2]))];
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, true);
        for ($y = 0; $y < $h; ++$y) {
            $t = $y / max(1, $h - 1);
            $f = 1.1 - 0.5 * $t;
            $c = imagecolorallocate($im, max(0, min(255, (int) ($r * $f))), max(0, min(255, (int) ($g * $f))), max(0, min(255, (int) ($b * $f))));
            if ($c !== false) {
                imageline($im, 0, $y, $w, $y, $c);
            }
        }
        $estado = crc32($texto) ?: 1;
        $prox = static function () use (&$estado): float {
            $estado = ($estado * 1103515245 + 12345) & 0x7fffffff;

            return $estado / 0x7fffffff;
        };
        for ($i = 0; $i < 5; ++$i) {
            $diametro = (int) ($altura * $esc * (0.5 + 0.9 * $prox()));
            $alfa = max(0, min(127, 95 + (int) (20 * $prox())));
            $tom = $i % 2 === 0 ? 255 : 0;
            $forma = imagecolorallocatealpha($im, $tom, $tom, $tom, $alfa);
            if ($forma !== false) {
                imagefilledellipse($im, (int) ($w * $prox()), (int) ($h * $prox()), $diametro, $diametro, $forma);
            }
        }
        $final = imagecreatetruecolor($largura, $altura);
        imagecopyresampled($final, $im, 0, 0, 0, 0, $largura, $altura, $w, $h);
        ob_start();
        imagepng($final);

        return (string) ob_get_clean();
    }

    private function pdfMinimo(string $texto): string
    {
        $fluxo = 'BT /F1 18 Tf 72 720 Td (' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texto) . ") Tj ET";
        $objs = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length ' . strlen($fluxo) . " >>\nstream\n{$fluxo}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $pos = [];
        foreach ($objs as $i => $o) {
            $pos[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n{$o}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($pos as $p) {
            $pdf .= sprintf("%010d 00000 n \n", $p);
        }

        return $pdf . 'trailer << /Size ' . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}

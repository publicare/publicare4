<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Acesso\Perfil;
use Pbl\Acesso\Usuario;
use Pbl\Caminhos;
use Pbl\Conteudo\Modelos;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\Site;
use Pbl\Servicos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Popula um site com um conteúdo de demonstração rico (o "Instituto Exemplo", fictício), para desenvolvimento, demo e testes
 * de aparência (ADR 0021). Ativa os quatro modelos opcionais, cria páginas, notícias, agenda, perguntas, documentos e galeria,
 * e publica. Idempotente (cada conteúdo é identificado pelo endereço) e recusa sites que já têm conteúdo demais.
 */
#[AsCommand(name: 'demo-popular', description: 'Popula um site com conteúdo de demonstração (Instituto Exemplo): só para desenvolvimento e demos')]
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
                $n = $this->servicos->publicador->publicarSite($site);
                $output->writeln("{$site->nome}: {$n} página(s) publicada(s)");
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function popular(): void
    {
        $dia = static fn (int $d, string $hora = '00:00:00'): string => gmdate('Y-m-d', strtotime(sprintf('%+d days', $d)) ?: time()) . ' ' . $hora;
        $criar = fn (string $classe, ?int $pai, string $titulo, string $url, array $dados = [], string $status = 'publicado', array $meta = [], ?\Closure $apos = null): Objeto
            => $this->m->garantirObjeto($this->site, $classe, $pai, $titulo, $url, $dados, $status, $meta, $apos);
        $pagina = static fn (string $resumo, string $corpo): array => ['resumo' => $resumo, 'corpo' => $corpo];

        // --- Página inicial -------------------------------------------------------------------------------------------
        $inicio = $this->servicos->objetos->porId($this->raiz);
        if ($inicio !== null && str_contains((string) ($inicio->dados['corpo'] ?? ''), 'Tudo funcionando')) {
            $this->servicos->objetos->atualizar($inicio->id, ['titulo' => 'Instituto Exemplo', 'dados' => [
                'resumo' => 'Educação, cultura e cidadania para a comunidade, desde 1998.',
                'corpo' => $this->boasVindas(),
                'seo_titulo' => 'Instituto Exemplo: educação, cultura e cidadania',
                'seo_descricao' => 'Organização fictícia usada para demonstrar o PBL4: notícias, agenda de eventos, perguntas frequentes e documentos.',
            ]], 'Demo');
        }

        // --- Destaques da página inicial (imagens geradas) -----------------------------------------------------------
        $pastaDestaques = $criar('pasta', $this->raiz, 'Destaques', 'destaques', [], 'publicado', ['peso' => 90, 'descricao' => 'Banners da página inicial']);
        foreach ([['oficinas', 'Oficinas de leitura', 'Inscrições abertas para crianças e adolescentes.', [34, 112, 87]], ['biblioteca', 'Biblioteca comunitária', 'Mais de cinco mil títulos para empréstimo gratuito.', [52, 95, 140]], ['voluntariado', 'Seja voluntário', 'Doe algumas horas por mês e transforme o bairro.', [150, 82, 40]]] as $i => [$u, $t, $txt, $cor]) {
            $criar('destaque', $pastaDestaques->id, $t, 'destaque-' . $u, ['texto' => $txt, 'nova_aba' => false], 'publicado', ['peso' => ($i + 1) * 10],
                $this->imagem('imagem', $t, $cor, 'destaque-' . $u . '.png', 1200, 500, $u));
        }

        // --- Institucional (2 níveis) ---------------------------------------------------------------------------------
        $inst = $criar('pagina', $this->raiz, 'Institucional', 'institucional', $pagina('Quem somos, o que fazemos e como prestamos contas.', '<p>O Instituto Exemplo é uma organização da sociedade civil, sem fins lucrativos, que atua com educação, cultura e cidadania. Nesta seção você conhece a nossa história, os nossos valores, as pessoas que fazem o instituto funcionar e os documentos de transparência.</p>'), 'publicado', ['peso' => 10, 'no_menu' => true, 'descricao' => 'Conheça o instituto']);
        $quem = $criar('pagina', $inst->id, 'Quem somos', 'quem-somos', $pagina('Uma história de mais de duas décadas.', '<h2>Nossa história</h2><p>Fundado em 1998 por um grupo de professores e moradores, o instituto começou com uma sala emprestada e vinte livros. Hoje atende cerca de <strong>1.200 pessoas por ano</strong> em cinco programas.</p><h2>O que fazemos</h2><ul><li>Oficinas de leitura e escrita para crianças e jovens;</li><li>Biblioteca comunitária aberta a todos;</li><li>Formação para educadores da rede pública;</li><li>Apoio a pequenos produtores culturais.</li></ul>'), 'publicado', ['peso' => 10, 'no_menu' => true, 'descricao' => 'Nossa história e nossos programas'], null);
        $criar('pagina', $quem->id, 'Linha do tempo', 'linha-do-tempo', $pagina('Os marcos de 1998 até hoje.', '<table class="table"><thead><tr><th scope="col">Ano</th><th scope="col">Marco</th></tr></thead><tbody><tr><td>1998</td><td>Fundação e primeira sala de leitura</td></tr><tr><td>2005</td><td>Inauguração da biblioteca comunitária</td></tr><tr><td>2012</td><td>Programa de formação de educadores</td></tr><tr><td>2019</td><td>Sede própria, com auditório</td></tr><tr><td>2024</td><td>Título de utilidade pública municipal</td></tr></tbody></table>'), 'publicado', ['peso' => 10, 'no_menu' => true]);
        $criar('pagina', $quem->id, 'Parceiros', 'parceiros', $pagina('Quem caminha com a gente.', '<p>Contamos com o apoio de empresas locais, escolas públicas e doadores individuais. Todos os parceiros são listados no relatório anual.</p>'), 'publicado', ['peso' => 20, 'no_menu' => true]);
        $criar('pagina', $inst->id, 'Missão e valores', 'missao-e-valores', $pagina('Por que existimos e como agimos.', '<h2>Missão</h2><p>Ampliar o acesso à leitura, à cultura e à participação cidadã.</p><h2>Valores</h2><ol><li>Respeito à diversidade;</li><li>Transparência em tudo o que fazemos;</li><li>Gratuidade das atividades abertas ao público;</li><li>Trabalho em rede com a comunidade.</li></ol><blockquote><p>Ler é um direito, não um privilégio.</p></blockquote>'), 'publicado', ['peso' => 20, 'no_menu' => true, 'descricao' => 'Missão e valores']);
        $equipe = $criar('pagina', $inst->id, 'Equipe', 'equipe', $pagina('As pessoas por trás do instituto.', '<p>Somos 14 profissionais e mais de 60 voluntários. A diretoria é eleita a cada dois anos.</p><h2>Diretoria</h2><ul><li><strong>Marta Souza Lima</strong>, presidente</li><li><strong>Paulo Ribeiro Dias</strong>, vice-presidente</li><li><strong>Helena Prado</strong>, tesoureira</li><li><strong>Júlio Cardoso</strong>, secretário</li></ul>'), 'publicado', ['peso' => 30, 'no_menu' => true]);
        $criar('pagina', $equipe->id, 'Voluntariado', 'voluntariado', $pagina('Como participar.', '<p>Basta ter 16 anos e disponibilidade de duas horas por semana. Escreva pelo formulário de <a href="/contato">contato</a>.</p>'), 'publicado', ['peso' => 10, 'no_menu' => true]);
        $transp = $criar('pagina', $inst->id, 'Transparência', 'transparencia', $pagina('Relatórios, balanços e estatuto.', '<p>Publicamos todos os anos o relatório de atividades e o balanço financeiro. Os documentos estão em <a href="/documentos">Documentos</a>.</p><p>Dúvidas sobre as contas? Veja as <a href="/perguntas-frequentes">perguntas frequentes</a>.</p>'), 'publicado', ['peso' => 40, 'no_menu' => true], null);
        $criar('pagina', $transp->id, 'Política de privacidade', 'politica-de-privacidade', $pagina('Como tratamos os seus dados.', '<p>Guardamos apenas os dados necessários para as inscrições e jamais os repassamos a terceiros.</p>'), 'publicado', ['peso' => 10, 'no_menu' => false, 'descricao' => 'Privacidade'] );

        // --- Notícias --------------------------------------------------------------------------------------------------
        $noticias = $criar('pasta', $this->raiz, 'Notícias', 'noticias', [], 'publicado', ['peso' => 20, 'no_menu' => true, 'descricao' => 'Novidades e avisos']);
        $lista = [
            ['abertas-inscricoes-oficinas', 'Abertas as inscrições para as oficinas de leitura', 'Há 60 vagas para o segundo semestre.', 3, [34, 112, 87]],
            ['biblioteca-novo-horario', 'Biblioteca comunitária muda de horário', 'Agora abrimos também aos sábados.', 9, [52, 95, 140]],
            ['feira-de-livros-2026', 'Feira de livros reúne 800 visitantes', 'Dois dias de troca de livros, música e contação de histórias.', 21, [140, 60, 90]],
            ['formacao-de-educadores', 'Turma de formação de educadores começa em março', 'Curso gratuito de 80 horas para profissionais da rede pública.', 45, [90, 60, 130]],
            ['relatorio-anual-publicado', 'Relatório anual de atividades já está disponível', 'Veja os números do ano e a prestação de contas.', 70, [60, 100, 100]],
            ['campanha-do-agasalho', 'Campanha do agasalho arrecada 2 mil peças', 'Obrigado a todas as pessoas que doaram.', 110, [160, 90, 50]],
            ['novo-conselho-eleito', 'Novo conselho é eleito em assembleia', 'A posse acontece no próximo mês.', 160, [70, 70, 70]],
            ['dez-anos-da-biblioteca', 'Biblioteca comunitária completa vinte anos', 'Uma celebração com a presença de antigos leitores.', 240, [110, 70, 120]],
        ];
        foreach ($lista as $i => [$u, $t, $r, $atras, $cor]) {
            $criar('noticia', $noticias->id, $t, $u, [
                'resumo' => $r,
                'corpo' => '<p>' . $r . ' O instituto convida toda a comunidade a participar e a acompanhar as próximas novidades por aqui.</p><h2>Como participar</h2><p>As informações completas estão na <a href="/agenda">agenda</a>. Em caso de dúvida, fale conosco pelo <a href="/contato">formulário de contato</a>.</p>',
                ...($i === 0 ? ['seo_titulo' => $t . ' | Instituto Exemplo', 'seo_descricao' => $r] : []),
            ], 'publicado', ['peso' => 0, 'data_publicacao' => $dia(-$atras, '09:00:00')],
                $this->imagem('imagem_destaque', $t, $cor, $u . '.png', 960, 540, (string) $i));
        }
        $criar('noticia', $noticias->id, 'Balanço do semestre (em breve)', 'balanco-do-semestre', ['resumo' => 'Será publicada na data programada.', 'corpo' => '<p>Esta notícia está agendada e só aparece depois da data de publicação.</p>'], 'publicado', ['data_publicacao' => $dia(20, '08:00:00')]);
        $criar('noticia', $noticias->id, 'Rascunho: regimento interno', 'rascunho-regimento-interno', ['resumo' => 'Texto ainda em revisão.', 'corpo' => '<p>Rascunho não publicado (estado privado).</p>'], 'privado');

        // --- Agenda ----------------------------------------------------------------------------------------------------
        $agenda = $criar('agenda', $this->raiz, 'Agenda', 'agenda', [], 'publicado', ['peso' => 40, 'no_menu' => true, 'descricao' => 'Próximos eventos e eventos passados']);
        $eventos = [
            ['roda-de-leitura', 'Roda de leitura para famílias', 7, '15:00:00', '17:00:00', 'Biblioteca comunitária', 'https://exemplo.org/inscricao/roda', [34, 112, 87]],
            ['oficina-de-poesia', 'Oficina de poesia', 15, '19:00:00', '21:00:00', 'Sala 2', '', [140, 60, 90]],
            ['assembleia-geral', 'Assembleia geral ordinária', 30, '10:00:00', '12:00:00', 'Auditório', '', [70, 70, 70]],
            ['feira-de-livros-passada', 'Feira de livros', -21, '09:00:00', '18:00:00', 'Praça central', '', [160, 90, 50]],
            ['palestra-direitos', 'Palestra sobre direitos do consumidor', -60, '19:00:00', '20:30:00', 'Auditório', '', [52, 95, 140]],
        ];
        foreach ($eventos as [$u, $t, $d, $ini, $fim, $local, $link, $cor]) {
            $criar('evento', $agenda->id, $t, $u, [
                'resumo' => $t . ': atividade aberta ao público, com entrada gratuita.',
                'corpo' => '<p>Participe! Leve um documento com foto. Há acessibilidade para pessoas com deficiência.</p>',
                'inicio' => $dia($d, $ini), 'fim' => $dia($d, $fim), 'local' => $local,
            ] + ($link === '' ? [] : ['link_inscricao' => $link]), 'publicado', [], $this->imagem('imagem', $t, $cor, $u . '.png', 960, 540, $u));
        }

        // --- Perguntas frequentes ---------------------------------------------------------------------------------------
        $faq = $criar('perguntas', $this->raiz, 'Perguntas frequentes', 'perguntas-frequentes', [], 'publicado', ['peso' => 50, 'no_menu' => true]);
        $perguntas = [
            ['Quem pode usar a biblioteca?', 'Qualquer pessoa, com documento de identidade e comprovante de endereço no primeiro empréstimo.', 'Biblioteca'],
            ['Quantos livros posso levar?', 'Até três livros por vez, por 15 dias, renováveis uma vez.', 'Biblioteca'],
            ['A biblioteca tem acesso para cadeirantes?', 'Sim, a entrada e os banheiros são acessíveis, e há leitores de tela nos computadores.', 'Biblioteca'],
            ['Como me inscrevo nas oficinas?', 'Pelo formulário de contato ou presencialmente, de segunda a sexta, das 9h às 17h.', 'Oficinas'],
            ['As oficinas são gratuitas?', 'Sim. Quando há material, pedimos apenas a doação de itens de papelaria.', 'Oficinas'],
            ['Qual a idade mínima?', 'As turmas infantis começam aos 7 anos; as de jovens e adultos, aos 14.', 'Oficinas'],
            ['Posso doar livros?', 'Sim, aceitamos livros em bom estado. Entregue-os na recepção.', 'Biblioteca'],
            ['Como acompanho a prestação de contas?', 'Os relatórios e balanços ficam na página de documentos.', 'Oficinas'],
        ];
        foreach ($perguntas as $i => [$p, $r, $cat]) {
            $criar('faq', $faq->id, $p, 'faq-' . ($i + 1), ['resposta' => '<p>' . $r . '</p>', 'categoria' => $cat], 'publicado', ['peso' => ($i + 1) * 10]);
        }

        // --- Documentos e galeria --------------------------------------------------------------------------------------
        $docs = $criar('pasta', $this->raiz, 'Documentos', 'documentos', [], 'publicado', ['peso' => 60, 'no_menu' => true, 'descricao' => 'Estatuto, relatórios e fotos']);
        foreach ([
            ['estatuto-social', 'Estatuto social', "ESTATUTO SOCIAL DO INSTITUTO EXEMPLO\n\nArt. 1. O Instituto Exemplo e uma associacao civil sem fins lucrativos.\nArt. 2. Sao seus objetivos a educacao, a cultura e a cidadania.\n", 'txt'],
            ['relatorio-anual', 'Relatório anual de atividades', "RELATORIO ANUAL\n\nAtendimentos: 1200 pessoas.\nOficinas realizadas: 48.\nLivros emprestados: 9300.\n", 'txt'],
            ['balanco-financeiro', 'Balanço financeiro', $this->pdfMinimo('Balanco financeiro - Instituto Exemplo'), 'pdf'],
        ] as $i => [$u, $t, $conteudo, $ext]) {
            $criar('arquivo', $docs->id, $t, $u, [], 'publicado', ['peso' => ($i + 1) * 10, 'descricao' => 'Documento de exemplo (' . strtoupper($ext) . ')'], $this->arquivo('arquivo', $conteudo, $u . '.' . $ext, $ext === 'pdf' ? 'application/pdf' : 'text/plain'));
        }
        $galeria = $criar('pasta', $docs->id, 'Galeria de fotos', 'galeria-de-fotos', [], 'publicado', ['peso' => 40, 'descricao' => 'Fotos de atividades (imagens geradas)']);
        foreach ([[34, 112, 87], [52, 95, 140], [150, 82, 40], [140, 60, 90], [70, 70, 70], [160, 90, 50]] as $i => $cor) {
            $criar('imagem', $galeria->id, 'Foto ' . ($i + 1), 'galeria-foto-' . ($i + 1), ['legenda' => 'Atividade ' . ($i + 1), 'texto_alternativo' => 'Imagem ilustrativa colorida número ' . ($i + 1)], 'publicado', ['peso' => ($i + 1) * 10],
                $this->imagem('imagem', 'Foto ' . ($i + 1), $cor, 'galeria-' . ($i + 1) . '.png', 800, 600, 'g' . $i));
        }

        // --- Links úteis -----------------------------------------------------------------------------------------------
        $links = $criar('pasta', $this->raiz, 'Links úteis', 'links-uteis', [], 'publicado', ['peso' => 70, 'no_menu' => true, 'descricao' => 'Atalhos internos e externos']);
        $criar('link', $links->id, 'Agenda de eventos', 'link-agenda', ['destino_interno' => $agenda->id], 'publicado', ['peso' => 10, 'descricao' => 'Atalho interno']);
        $criar('link', $links->id, 'Portal de dados abertos (exemplo)', 'link-dados-abertos', ['destino_externo' => 'https://dados.exemplo.org', 'nova_aba' => true], 'publicado', ['peso' => 20, 'descricao' => 'Atalho externo, abre em nova aba']);
        $criar('link', $links->id, 'Fale com a biblioteca', 'link-email', ['destino_externo' => 'mailto:biblioteca@exemplo.org'], 'publicado', ['peso' => 30]);

        $this->versoesDoSite($criar, $pagina, $links->id);
        $this->fluxoDeRevisao($inst);

        // Os destaques apontam para páginas internas.
        foreach (['oficinas' => $agenda->id, 'biblioteca' => $docs->id, 'voluntariado' => $this->idDe('voluntariado')] as $u => $alvo) {
            $d = $this->servicos->objetos->porUrl($this->site->id, 'destaque-' . $u);
            if ($d !== null && !isset($d->dados['destino_interno']) && $alvo > 0) {
                $this->servicos->objetos->atualizar($d->id, ['dados' => ['destino_interno' => $alvo]], 'Demo');
            }
        }
    }

    /**
     * As duas versões de site em paralelo (ADR 0022): `/onepage` (pele onepage) e `/portal` (pele portal). Cada uma é um objeto-raiz de
     * pele; todo o conteúdo abaixo dele herda a pele. Os endereços levam prefixo (`op-`, `pt-`) porque são únicos por site.
     *
     * @param \Closure(string, ?int, string, string, array<string, string|int|float|bool>, string, array<string, mixed>, ?\Closure=): Objeto $criar
     * @param \Closure(string, string): array{resumo: string, corpo: string} $pagina
     */
    private function versoesDoSite(\Closure $criar, \Closure $pagina, int $pastaDeLinks): void
    {
        $this->logoEHero();

        // --- Onepage: uma página só, com seções vindas dos filhos --------------------------------------------------------------------
        $op = $criar('pagina', $this->raiz, 'Instituto Exemplo (uma página)', 'onepage', $pagina('A mesma organização numa página só, com seções.', '<p>Versão <strong>onepage</strong>: tudo na mesma tela, com menu de âncoras.</p>'), 'publicado', ['peso' => 80, 'no_menu' => false, 'rotulo_menu' => 'Versão onepage', 'pele' => 'onepage']);
        $criar('pagina', $op->id, 'Sobre', 'op-sobre', $pagina('Educação, cultura e cidadania desde 1998.', '<p>O Instituto Exemplo atende cerca de 1.200 pessoas por ano com oficinas de leitura, biblioteca comunitária e formação de educadores.</p>'), 'publicado', ['peso' => 10]);
        $servicos = $criar('pasta', $op->id, 'Serviços', 'op-servicos', [], 'publicado', ['peso' => 20, 'descricao' => 'O que oferecemos']);
        foreach (['Oficinas de leitura' => 'Para crianças e jovens.', 'Biblioteca comunitária' => 'Empréstimo gratuito.', 'Formação de educadores' => 'Curso de 80 horas.'] as $t => $r) {
            $criar('pagina', $servicos->id, $t, 'op-servico-' . $this->slug($t), $pagina($r, '<p>' . $r . ' Saiba como participar falando conosco.</p>'), 'publicado', ['peso' => 10]);
        }
        $destaques = $criar('pasta', $op->id, 'Destaques', 'op-destaques', [], 'publicado', ['peso' => 30, 'descricao' => 'Em evidência']);
        foreach ([['oficinas', 'Inscrições abertas', [34, 112, 87]], ['biblioteca', 'Biblioteca aberta aos sábados', [52, 95, 140]], ['voluntario', 'Seja voluntário', [150, 82, 40]]] as $i => [$u, $t, $cor]) {
            $criar('destaque', $destaques->id, $t, 'op-destaque-' . $u, ['texto' => 'Saiba como participar.', 'nova_aba' => false], 'publicado', ['peso' => ($i + 1) * 10],
                $this->imagem('imagem', $t, $cor, 'op-destaque-' . $u . '.png', 1200, 500, $u));
        }
        $criar('pagina', $op->id, 'Contato', 'op-contato', $pagina('Fale com a gente.', '<p>Rua das Letras, 100, Centro. Telefone (11) 5555-0100. Ou use o <a href="/contato">formulário de contato</a>.</p>'), 'publicado', ['peso' => 40]);

        // --- Portal: várias páginas, com notícias --------------------------------------------------------------------------------------
        $pt = $criar('pagina', $this->raiz, 'Instituto Exemplo (portal)', 'portal', $pagina('Notícias, serviços e institucional num portal.', '<p>Versão <strong>portal</strong>: várias páginas, hero com carrossel, listagem de notícias e rodapé.</p>'), 'publicado', ['peso' => 90, 'no_menu' => false, 'rotulo_menu' => 'Versão portal', 'pele' => 'portal']);
        $criar('pagina', $pt->id, 'Sobre o portal', 'pt-sobre', $pagina('Quem somos.', '<p>Portal institucional de demonstração do Instituto Exemplo.</p>'), 'publicado', ['peso' => 10, 'no_menu' => true]);
        $noticias = $criar('pasta', $pt->id, 'Notícias do portal', 'pt-noticias', [], 'publicado', ['peso' => 20, 'no_menu' => true, 'descricao' => 'Novidades']);
        foreach ([['inscricoes', 'Inscrições abertas para as oficinas', 2, [34, 112, 87]], ['horario', 'Biblioteca abre aos sábados', 8, [52, 95, 140]], ['feira', 'Feira de livros reúne 800 visitantes', 20, [140, 60, 90]], ['relatorio', 'Relatório anual publicado', 40, [60, 100, 100]]] as $i => [$u, $t, $atras, $cor]) {
            $criar('noticia', $noticias->id, $t, 'pt-noticia-' . $u, ['resumo' => $t . '.', 'corpo' => '<p>' . $t . '. Acompanhe as próximas novidades por aqui.</p>'], 'publicado',
                ['data_publicacao' => gmdate('Y-m-d', time() - $atras * 86400) . ' 09:00:00'], $this->imagem('imagem_destaque', $t, $cor, 'pt-noticia-' . $u . '.png', 960, 540, $u));
        }
        $pts = $criar('pasta', $pt->id, 'Serviços do portal', 'pt-servicos', [], 'publicado', ['peso' => 30, 'no_menu' => true, 'descricao' => 'O que oferecemos']);
        $criar('pagina', $pts->id, 'Biblioteca', 'pt-servico-biblioteca', $pagina('Empréstimo gratuito.', '<p>Mais de cinco mil títulos.</p>'), 'publicado', ['peso' => 10, 'no_menu' => true]);
        $criar('pagina', $pts->id, 'Oficinas', 'pt-servico-oficinas', $pagina('Leitura e escrita.', '<p>Turmas para crianças e jovens.</p>'), 'publicado', ['peso' => 20, 'no_menu' => true]);
        $ptd = $criar('pasta', $pt->id, 'Destaques do portal', 'pt-destaques', [], 'publicado', ['peso' => 40, 'descricao' => 'Em evidência']);
        foreach ([['oficinas', 'Oficinas de leitura', [34, 112, 87]], ['biblioteca', 'Biblioteca comunitária', [52, 95, 140]]] as $i => [$u, $t, $cor]) {
            $criar('destaque', $ptd->id, $t, 'pt-destaque-' . $u, ['texto' => 'Conheça.', 'nova_aba' => false], 'publicado', ['peso' => ($i + 1) * 10],
                $this->imagem('imagem', $t, $cor, 'pt-destaque-' . $u . '.png', 1200, 500, $u));
        }
        $criar('pagina', $pt->id, 'Contato do portal', 'pt-contato', $pagina('Fale com a gente.', '<p>Veja o <a href="/contato">formulário de contato</a>.</p>'), 'publicado', ['peso' => 50, 'no_menu' => true]);

        // Atalhos na página inicial (molde home da pele básica) para as duas versões.
        $criar('link', $pastaDeLinks, 'Versão onepage do site', 'link-onepage', ['destino_interno' => $op->id], 'publicado', ['peso' => 40, 'descricao' => 'Mesma organização numa página só']);
        $criar('link', $pastaDeLinks, 'Versão portal do site', 'link-portal', ['destino_interno' => $pt->id], 'publicado', ['peso' => 50, 'descricao' => 'Mesma organização como portal']);
    }

    private function slug(string $texto): string
    {
        $ascii = (string) iconv('UTF-8', 'ASCII//TRANSLIT', $texto);

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
    }

    /** Logo e imagem de abertura (hero) do site, geradas, para as peles onepage e portal. Só se ainda não houver. */
    private function logoEHero(): void
    {
        $gravadas = $this->servicos->configs->gravadas($this->site->id);
        $novas = [];
        foreach (['logo' => ['Logo', [34, 112, 87], 400, 120], 'imagem_hero' => ['Imagem de abertura', [52, 95, 140], 1200, 500]] as $chave => [$t, $cor, $l, $a]) {
            if (isset($gravadas[$chave])) {
                continue;
            }
            $o = $this->m->garantirObjeto($this->site, 'imagem', $this->raiz, $t, 'demo-' . str_replace('_', '-', $chave), ['legenda' => $t, 'texto_alternativo' => $t], 'publicado', ['peso' => 999], $this->imagem('imagem', $t, $cor, 'demo-' . $chave . '.png', $l, $a, $chave));
            $arquivo = $o->dados['imagem'] ?? null;
            if (is_int($arquivo) || (is_string($arquivo) && ctype_digit($arquivo))) {
                $novas[$chave] = (int) $arquivo;
            }
        }
        if ($novas !== []) {
            $this->servicos->configs->definir($this->site->id, $novas);
        }

    }

    /**
     * Fluxo de revisão (fase 6): duas contas, `autor` e `editor`, com acesso só à seção Institucional, e dois envios de exemplo
     * (um pendente na fila do editor, um devolvido com motivo). Idempotente: conta que já existe fica como está e os exemplos só
     * mudam de estado enquanto ainda não têm histórico de revisão.
     */
    private function fluxoDeRevisao(Objeto $area): void
    {
        $contas = [];
        foreach (['autor' => ['Autora de exemplo', Perfil::Autor], 'editor' => ['Editor de exemplo', Perfil::Editor]] as $login => [$nome, $perfil]) {
            $conta = $this->servicos->usuarios->porLogin($login);
            if ($conta === null) {
                $conta = $this->servicos->usuarios->criar($login, $login . '@exemplo.org', $nome, self::SENHA_DEMO, false, 'demo');
                $this->servicos->usuarios->conceder($conta->id, $area->id, $perfil, 'demo');
            }
            $contas[$login] = $conta;
        }
        /** @var array{autor: Usuario, editor: Usuario} $contas */
        $exemplos = [
            ['envio-pendente-calendario-de-visitas', 'Calendário de visitas (aguardando revisão)', 'Texto enviado pela autora de exemplo e aguardando o editor.'],
            ['envio-devolvido-nota-de-transparencia', 'Nota de transparência (devolvida)', 'Texto devolvido pelo editor com um pedido de ajuste.'],
        ];
        foreach ($exemplos as $i => [$url, $titulo, $resumo]) {
            $o = $this->m->garantirObjeto($this->site, 'pagina', $area->id, $titulo, $url, ['resumo' => $resumo, 'corpo' => '<p>' . $resumo . '</p>'], 'privado', ['criado_por' => $contas['autor']->id, 'peso' => 900 + $i]);
            if ($o->status !== 'privado' || $this->servicos->revisao->historico($o->id, 1) !== []) {
                continue;
            }
            $this->servicos->revisao->enviar($contas['autor'], $o->id);
            if ($i === 1) {
                $this->servicos->revisao->devolver($contas['editor'], $o->id, 'Faltou citar a fonte dos números e revisar o último parágrafo. Ajuste e reenvie.');
            }
        }
    }

    private function idDe(string $url): int
    {
        $o = $this->servicos->objetos->porUrl($this->site->id, $url);

        return $o === null ? 0 : $o->id;
    }

    private function boasVindas(): string
    {
        return '<h2>Bem-vindo ao Instituto Exemplo</h2>'
            . '<p>Somos uma organização <strong>fictícia</strong>, criada para demonstrar o PBL4. Aqui você encontra as <a href="/noticias">notícias</a>, a <a href="/agenda">agenda de eventos</a>, as <a href="/perguntas-frequentes">perguntas frequentes</a> e os <a href="/documentos">documentos</a> de transparência.</p>'
            . '<h3>Nossos programas</h3><ul><li>Oficinas de leitura e escrita</li><li>Biblioteca comunitária</li><li>Formação de educadores</li></ul>'
            . '<h3>Horário de atendimento</h3><table class="table"><thead><tr><th scope="col">Dia</th><th scope="col">Horário</th><th scope="col">Atividade</th></tr></thead><tbody>'
            . '<tr><td>Segunda a sexta</td><td>9h às 17h</td><td>Biblioteca e secretaria</td></tr><tr><td>Sábado</td><td>9h às 13h</td><td>Oficinas e biblioteca</td></tr></tbody></table>'
            . '<p>Quer ajudar? Veja como ser <a href="/voluntariado">voluntário</a> ou <a href="/contato">fale conosco</a>.</p>';
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

    /** @param array{0: int, 1: int, 2: int} $cor */
    private function png(string $texto, array $cor, int $largura, int $altura): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('A extensão GD é necessária para gerar as imagens do demo');
        }
        $ascii = preg_replace('/[^\x20-\x7e]/', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $texto)) ?? '';
        $largura = max(1, $largura);
        $altura = max(1, $altura);
        $r = max(0, min(255, $cor[0]));
        $g = max(0, min(255, $cor[1]));
        $b = max(0, min(255, $cor[2]));
        $im = imagecreatetruecolor($largura, $altura);
        $fundo = imagecolorallocate($im, $r, $g, $b);
        $claro = imagecolorallocate($im, min(255, $r + 60), min(255, $g + 60), min(255, $b + 60));
        $branco = imagecolorallocate($im, 255, 255, 255);
        if ($fundo === false || $claro === false || $branco === false) {
            throw new \RuntimeException('Falha ao alocar cores');
        }
        imagefill($im, 0, 0, $fundo);
        imagefilledellipse($im, (int) ($largura * 0.85), (int) ($altura * 0.2), (int) ($altura * 0.9), (int) ($altura * 0.9), $claro);
        // Texto pequeno (fonte 5 = 9x15 px) ampliado para ficar legível.
        $pequena = imagecreatetruecolor(max(1, strlen($ascii) * 9), 15);
        $f2 = imagecolorallocate($pequena, $r, $g, $b);
        $b2 = imagecolorallocate($pequena, 255, 255, 255);
        if ($f2 === false || $b2 === false) {
            throw new \RuntimeException('Falha ao alocar cores');
        }
        imagefill($pequena, 0, 0, $f2);
        imagestring($pequena, 5, 0, 0, $ascii, $b2);
        $escala = max(1, min((int) floor(($largura * 0.8) / imagesx($pequena)), 4));
        imagecopyresized($im, $pequena, (int) ($largura * 0.08), (int) ($altura * 0.55), 0, 0, imagesx($pequena) * $escala, 15 * $escala, imagesx($pequena), 15);
        imagestring($im, 3, 10, $altura - 20, 'Instituto Exemplo (imagem de demonstracao)', $branco);
        ob_start();
        imagepng($im);

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

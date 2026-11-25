<?php

declare(strict_types=1);

namespace Pbl\Demo;

/**
 * Conteúdo do site de exemplo do PBL4: a apresentação do próprio Publicare 4 (ADR 0021, adendo). Só dados e texto, sem tocar no
 * banco: o `DemoPopularComando` e o instalador orquestram. Cada afirmação sobre o produto tem fonte e verificação em
 * {@see afirmacoes()}; o teste `ConteudoPublicareTest` confere que a frase está no texto, que a fonte existe e que o recurso
 * citado existe de verdade (comando de `bin/pbl`, classe, trecho de código ou de documento).
 *
 * Regra do dono (2026-11-24): o exemplo não tem dado que envelheça: nada de data, número de versão, contagem, nota com data ou estado transitório; o que muda aponta para o endereço permanente (documentação, instalador, releases). Além disso: nada de número de uso, desempenho ou comparação; nada de recurso que o estado do projeto marca como futuro, a não ser
 * que o texto diga que é futuro. O HTML usa só as marcações que `HtmlSeguro` aceita (sem `class`, `id`, `style`).
 */
final class ConteudoPublicare
{
    public const NOME_DO_SITE = 'Publicare 4';
    public const REPOSITORIO = 'https://github.com/publicare/publicare4';

    /** Slogan: a promessa em uma frase. */
    public const PROMESSA = 'Plataforma livre para montar sites e pequenos sistemas na internet sem programar, em hospedagem comum.';

    /**
     * Página inicial. O texto é o do site oficial; só a demonstração (`demo-popular`) acrescenta o parágrafo que avisa que o site é de demonstração.
     *
     * @return array{resumo: string, corpo: string, seo_titulo: string, seo_descricao: string}
     */
    public static function inicio(bool $demonstracao = false): array
    {
        $aviso = $demonstracao
            ? '<p>Este site é a <strong>demonstração</strong> que acompanha o programa: foi montado com ele. Contas e e-mails de demonstração são fictícios.</p>'
            : '';

        return [
            'resumo' => self::PROMESSA,
            'corpo' => '<h2>Um site feito com o próprio Publicare</h2>'
                . '<p>O Publicare é um programa <strong>livre</strong> para montar sites e pequenos sistemas na internet. Nasceu no Brasil em 2003, para portais e intranets, e foi reconstruído como o <strong>Publicare 4</strong> (também chamado PBL4).</p>'
                . '<p>Este site foi montado com o próprio programa e apresenta, com conteúdo real, o que ele faz.</p>' . $aviso
                . '<p>Comece por <a href="/o-que-e">o que é o Publicare 4</a>, veja <a href="/o-que-ele-faz">o que ele faz</a> ou vá direto às <a href="/perguntas-frequentes">perguntas frequentes</a>.</p>',
            'seo_titulo' => 'Publicare 4: sites e pequenos sistemas, sem programar',
            'seo_descricao' => self::PROMESSA,
        ];
    }

    /** Documento do repositório que explica cada pilar (usado na instalação leve, que não traz as páginas completas). */
    public const DOCUMENTO_DO_PILAR = [
        'o-que-ele-faz' => 'docs/usuario/estado-do-projeto.md',
        'como-facilita' => 'docs/linguagem-pbl4.md',
        'o-que-inova' => 'docs/tecnica/seguranca.md',
        'instalacao-e-requisitos' => 'docs/usuario/instalar.md',
    ];

    /**
     * Página inicial curta da instalação limpa (`bin/pbl instalar`): a promessa e o caminho para a demonstração completa.
     *
     * @return array{resumo: string, corpo: string}
     */
    public static function inicioDaInstalacao(): array
    {
        return [
            'resumo' => self::PROMESSA,
            'corpo' => '<p>Este site foi instalado com o Publicare 4 e já está funcionando. Edite este conteúdo pelo painel de administração.</p>'
                . '<p>Para ver o Publicare 4 apresentando a si mesmo, com páginas, perguntas frequentes e agenda, rode <code>php bin/pbl demo-popular</code>.</p>',
        ];
    }

    /**
     * "Veja também" na página inicial: links externos para a documentação do repositório (endereços permanentes).
     *
     * @return list<array{url: string, titulo: string, descricao: string, destino: string, peso: int}>
     */
    public static function atalhosUteis(): array
    {
        $docs = self::REPOSITORIO . '/blob/main/';

        return [
            ['url' => 'link-atualizar', 'titulo' => 'Como atualizar', 'descricao' => 'Atualização assinada, com cópia do banco e volta atrás: o passo a passo.', 'destino' => $docs . 'docs/usuario/atualizar.md', 'peso' => 50],
            ['url' => 'link-documentacao', 'titulo' => 'Documentação do usuário', 'descricao' => 'Guias para quem edita e para quem administra o site.', 'destino' => self::REPOSITORIO . '/tree/main/docs/usuario', 'peso' => 60],
            ['url' => 'link-linguagem', 'titulo' => 'Linguagem de moldes', 'descricao' => 'Como escrever peles e moldes .pbl, em português.', 'destino' => $docs . 'docs/linguagem-pbl4.md', 'peso' => 70],
            ['url' => 'link-seguranca', 'titulo' => 'Segurança', 'descricao' => 'Como relatar uma falha de segurança, em privado.', 'destino' => $docs . 'SECURITY.md', 'peso' => 100],
        ];
    }

    /**
     * Os quatro pilares da página inicial (cartões com ícone). `destino` é o endereço de uma página do próprio site.
     *
     * @return list<array{url: string, titulo: string, descricao: string, destino: string}>
     */
    public static function pilares(): array
    {
        return [
            ['url' => 'pilar-arvore', 'titulo' => 'Conteúdo em árvore', 'descricao' => 'Páginas, notícias e eventos organizados em seções, com tipos de conteúdo definidos por você, sem programar.', 'destino' => 'o-que-ele-faz'],
            ['url' => 'pilar-moldes', 'titulo' => 'Peles e moldes', 'descricao' => 'Trocar a pele muda o visual sem mexer no conteúdo. Os moldes usam uma linguagem própria e segura.', 'destino' => 'como-facilita'],
            ['url' => 'pilar-seguro', 'titulo' => 'Seguro por padrão', 'descricao' => 'Moldes sem PHP cru, texto escapado, HTML limpo ao salvar e login protegido contra tentativas em massa.', 'destino' => 'o-que-inova'],
            ['url' => 'pilar-simples', 'titulo' => 'Simples de instalar e atualizar', 'descricao' => 'Roda em hospedagem comum, com SQLite. A atualização é assinada, guarda cópia do banco e volta atrás se falhar.', 'destino' => 'instalacao-e-requisitos'],
        ];
    }

    /**
     * Páginas de primeiro nível, na ordem do menu.
     *
     * @return array<string, array{titulo: string, rotulo: string, peso: int, resumo: string, corpo: string, descricao: string}>
     */
    public static function paginas(): array
    {
        return [
            'o-que-e' => ['titulo' => 'O que é o Publicare 4', 'rotulo' => 'O que é', 'peso' => 10, 'descricao' => 'Um programa livre, brasileiro, para sites e pequenos sistemas', 'resumo' => 'Um programa livre, nascido no Brasil, para montar sites e pequenos sistemas sem programar.', 'corpo' => self::oQueE()],
            'o-que-ele-faz' => ['titulo' => 'O que ele faz', 'rotulo' => 'O que faz', 'peso' => 20, 'descricao' => 'Os recursos do programa', 'resumo' => 'Os recursos do programa, do conteúdo à atualização.', 'corpo' => self::oQueEleFaz()],
            'como-facilita' => ['titulo' => 'Como facilita', 'rotulo' => 'Como facilita', 'peso' => 30, 'descricao' => 'Para quem edita, administra e desenvolve moldes', 'resumo' => 'O que muda no dia a dia de quem edita, de quem administra e de quem cria moldes.', 'corpo' => self::comoFacilita()],
            'o-que-inova' => ['titulo' => 'O que inova', 'rotulo' => 'O que inova', 'peso' => 40, 'descricao' => 'As escolhas de projeto que diferenciam o Publicare 4', 'resumo' => 'As escolhas de projeto que diferenciam o Publicare 4, todas verificáveis no código e nas decisões registradas.', 'corpo' => self::oQueInova()],
            'instalacao-e-requisitos' => ['titulo' => 'Instalação e requisitos', 'rotulo' => 'Instalação', 'peso' => 60, 'descricao' => 'O que o servidor precisa ter e como instalar', 'resumo' => 'O que o servidor precisa ter e os caminhos para instalar.', 'corpo' => self::instalacao()],
            'comunidade' => ['titulo' => 'Comunidade e contribuição', 'rotulo' => 'Comunidade', 'peso' => 70, 'descricao' => 'Como participar do projeto', 'resumo' => 'Você não precisa programar para ajudar.', 'corpo' => self::comunidade()],
            'como-acompanhar' => ['titulo' => 'Como acompanhar o projeto', 'rotulo' => 'Acompanhar', 'peso' => 80, 'descricao' => 'Onde ver versões, mudanças e o plano do projeto', 'resumo' => 'Onde ver as versões, o que mudou em cada uma e o plano do projeto.', 'corpo' => self::acompanhar()],
        ];
    }

    private static function oQueE(): string
    {
        return '<h2>Em uma frase</h2>'
            . '<p>O Publicare 4 é uma plataforma livre para sites, dados e fluxos, sucessora do Publicare 3, pensada para quem monta portais e pequenos sistemas sem programar, em hospedagem comum, com moldes de aparência seguros e atualização simples.</p>'
            . '<h2>Para que serve</h2>'
            . '<ul><li><strong>Sites institucionais e portais:</strong> páginas, menus, notícias e documentos.</li>'
            . '<li><strong>Pequenos sistemas do dia a dia, montados por configuração:</strong> existem a agenda de eventos, as perguntas frequentes, os destaques da página inicial, os blocos reutilizáveis e o formulário público simples.</li>'
            . '<li><strong>Vários sites no mesmo programa:</strong> um mesmo Publicare serve mais de um endereço, como um site em português e outro em inglês.</li></ul>'
            . '<h2>Como ele pensa</h2>'
            . '<ol><li>Você define <strong>tipos de conteúdo</strong> (notícia, evento, documento) dizendo quais campos cada um tem.</li>'
            . '<li>Você cria <strong>conteúdos</strong> desses tipos e os organiza em <strong>árvore</strong>: uma seção com páginas dentro.</li>'
            . '<li>A <strong>aparência</strong> vem de uma <strong>pele</strong>. Trocar a pele muda o visual sem mexer no conteúdo.</li>'
            . '<li>Ao <strong>publicar</strong>, o Publicare prepara as páginas prontas e as entrega aos visitantes.</li>'
            . '<li>Quem edita tem <strong>papéis</strong>: quem lê, quem escreve, quem aprova e quem administra.</li></ol>'
            . '<h2>Quem mantém</h2>'
            . '<p>É um projeto aberto, mantido por voluntários, com código público em <a href="' . self::REPOSITORIO . '">github.com/publicare/publicare4</a>. O Publicare 4 usa a licença Apache-2.0. O código do Publicare 3 e o anterior continuam sob a licença MIT, e os créditos históricos estão no arquivo NOTICE.</p>';
    }

    private static function oQueEleFaz(): string
    {
        return '<h2>Conteúdo</h2>'
            . '<ul><li>Conteúdo em árvore de objetos: cada página, notícia ou evento tem um lugar numa seção.</li>'
            . '<li>Tipos de conteúdo criados pelo navegador pelo administrador: o administrador cria o tipo, adiciona campos e define onde ele pode ficar. Um campo com dados nunca é apagado nem muda de tipo: pode ser arquivado.</li>'
            . '<li>Mover, copiar, recortar e colar conteúdo, com lixeira que permite restaurar.</li>'
            . '<li>Tags nos conteúdos, página de cada tag e ligação de um conteúdo a outro.</li></ul>'
            . '<h2>Edição</h2>'
            . '<ul><li>A edição de um conteúdo é dividida em abas: Conteúdo, Publicação e Avançado.</li>'
            . '<li>Editor de texto rico nos campos de texto formatado; o HTML é limpo ao salvar.</li>'
            . '<li>Barra de edição nas páginas do site para quem está logado: editar, publicar, despublicar, mover e excluir.</li></ul>'
            . '<h2>Estados, revisão e histórico</h2>'
            . '<ul><li>Todo conteúdo novo começa fora do ar, como rascunho, e passa a publicado quando alguém publica.</li>'
            . '<li>Publicação agendada, com data para entrar e para sair do ar.</li>'
            . '<li>Fluxo de revisão: o autor envia o texto, o editor aprova e publica ou devolve com o motivo.</li>'
            . '<li>Histórico de versões de cada conteúdo, com restaurar e proteção contra edição simultânea.</li></ul>'
            . '<h2>Aparência</h2>'
            . '<ul><li>Peles e moldes escritos na linguagem de moldes do PBL4, arquivos com a extensão .pbl.</li>'
            . '<li>Editor de moldes no navegador, só para administradores, com conferência da sintaxe antes de salvar, histórico de cada molde, rascunho e pré-visualização.</li>'
            . '<li>Peles prontas, entre elas a publicare (a deste site) e a básica. É possível escolher uma pele e um molde só para um conteúdo e tudo abaixo dele.</li></ul>'
            . '<h2>Sites, domínios e endereços</h2>'
            . '<ul><li>Vários sites e vários domínios no mesmo programa: um domínio principal e outros que mostram o mesmo conteúdo ou levam ao principal.</li>'
            . '<li>Mudar o endereço de uma página sem quebrar o antigo: o endereço antigo passa a levar ao novo.</li>'
            . '<li>Redirecionamento de endereços antigos de outros sistemas.</li></ul>'
            . '<h2>Busca, formulários e arquivos</h2>'
            . '<ul><li>Busca que ignora maiúsculas e acentos, aceita várias palavras e começo de palavra e ordena por relevância.</li>'
            . '<li>Formulário público simples: cada envio chega como conteúdo privado para a equipe, com proteção contra robôs e limite de envios.</li>'
            . '<li>Envio de arquivos e imagens pelo navegador, com o tipo conferido pelo conteúdo do arquivo. A imagem tem um campo de texto alternativo para leitores de tela.</li>'
            . '<li>Feed RSS, sitemap.xml e robots.txt automáticos.</li></ul>'
            . '<h2>Operação</h2>'
            . '<ul><li>Cópias de segurança agendadas, com baixar e restaurar, pela tela ou pela linha de comando.</li>'
            . '<li>Atualização assinada, com cópia do banco antes e volta atrás automática se falhar.</li>'
            . '<li>Cron central: uma linha de cron por minuto cuida da publicação por data e das limpezas.</li>'
            . '<li>Linha de comando em português: <code>bin/pbl</code>.</li></ul>'
            . '<h2>Contas e acesso</h2>'
            . '<ul><li>Papéis por seção do site (leitor, autor e editor) e administrador geral, com registro de quem fez o quê.</li>'
            . '<li>Ações sensíveis do painel pedem a senha de novo.</li>'
            . '<li>Recuperação de acesso por e-mail (esqueci a senha e esqueci o usuário) e convite de usuário por link.</li></ul>'
            . '<h2>Migração</h2>'
            . '<ul><li>Importador de um site feito no Publicare 3: conteúdo, arquivos, contas e tags. As senhas precisam ser redefinidas.</li>'
            . '<li>Conversor de moldes do Publicare 3 para o novo, com relatório do que precisa de revisão manual.</li></ul>';
    }

    private static function comoFacilita(): string
    {
        return '<h2>Para quem edita</h2>'
            . '<ul><li>Depois de gravar, o painel mostra a página renderizada com uma faixa dizendo o que aconteceu.</li>'
            . '<li>Se outra pessoa salvou o mesmo conteúdo enquanto você mexia, aparece um aviso e nada é sobrescrito sem você decidir.</li>'
            . '<li>Colar do Word ou do Google Docs funciona: o editor limpa o que vem de lá.</li>'
            . '<li>Você pode voltar a uma versão antiga: isso cria uma versão nova, e nada se perde.</li>'
            . '<li>A ajuda do painel fica atrás de um botão &quot;?&quot;, acessível por teclado e leitor de tela.</li></ul>'
            . '<h2>Para quem administra</h2>'
            . '<ul><li>Instalação pelo navegador, com um assistente que confere os requisitos do servidor.</li>'
            . '<li>Funciona em servidores Apache e nginx, e há um guia para hospedagens com painel Hestia ou VestaCP.</li>'
            . '<li>Cópias de segurança e atualização pelo painel, sem abrir terminal.</li>'
            . '<li>Contas, papéis e domínios pelo navegador, com a tela de tarefas agendadas mostrando se o cron está rodando.</li>'
            . '<li>Se o acesso se perder, a pessoa que cuida do servidor resolve pela linha de comando (<code>bin/pbl conta-senha</code> e <code>bin/pbl conta-destravar</code>).</li></ul>'
            . '<h2>Para quem desenvolve moldes</h2>'
            . '<ul><li>Uma linguagem própria, em português, com listas, menus, busca e herança de layout (<code>estende</code> e <code>bloco</code>).</li>'
            . '<li>Moldes que não executam PHP cru, então um erro de molde não abre brecha no servidor.</li>'
            . '<li>Conferência de sintaxe antes de salvar, histórico de cada molde e pré-visualização do rascunho sem afetar o que está no ar.</li>'
            . '<li>Conversor de moldes do Publicare 3 (<code>bin/pbl converter-moldes</code>) com relatório.</li>'
            . '<li>A pele deste site, a <strong>publicare</strong>, traz moldes, CSS próprio, tema claro e escuro, sem JavaScript.</li></ul>'
            . '<p>A documentação da linguagem está em <a href="' . self::REPOSITORIO . '/blob/main/docs/linguagem-pbl4.md">docs/linguagem-pbl4.md</a>.</p>';
    }

    private static function oQueInova(): string
    {
        return '<p>Estas são as escolhas de projeto que diferenciam o Publicare 4. Cada uma pode ser conferida no código ou nas decisões registradas (ADRs) do repositório. Não fazemos comparações com outros sistemas.</p>'
            . '<h2>Moldes de baixo código, em linguagem segura</h2>'
            . '<p>Os moldes são arquivos .pbl, escritos numa linguagem de moldes própria que o Publicare compila. Ela não aceita PHP cru e não dá acesso a arquivos nem a funções do PHP. O texto exibido é escapado por padrão.</p>'
            . '<h2>Publicação em cache estático</h2>'
            . '<p>Ao publicar, o Publicare grava as páginas prontas e as entrega a partir desse cache, sem montar a página de novo a cada visita. Publicar um site grande acontece em lotes, com barra de progresso e retomada.</p>'
            . '<h2>Atualização assinada e com volta atrás</h2>'
            . '<p>O atualizador só aceita pacotes com a assinatura digital da equipe do projeto. Antes de trocar o código, guarda uma cópia do banco, e se algo der errado volta sozinho para a versão anterior. Uma atualização concluída também pode ser desfeita.</p>'
            . '<h2>Sem CDN e sem script embutido</h2>'
            . '<p>Scripts, estilos e fontes das páginas vêm do próprio site. Nada é carregado de terceiros e nada fica embutido no HTML, o que permite uma política de segurança de conteúdo restritiva. A pele deste site nem usa JavaScript.</p>'
            . '<h2>Acessibilidade desde o desenho</h2>'
            . '<p>A pele deste site tem link para pular ao conteúdo, foco visível, perguntas frequentes que funcionam pelo teclado, respeito à preferência por menos movimento, tema claro e escuro e contraste conferido por teste automático. Imagens têm um campo de texto alternativo. Isso não substitui uma auditoria externa de acessibilidade.</p>'
            . '<h2>SQLite, simples de operar</h2>'
            . '<p>O banco padrão é um arquivo SQLite: não há servidor de banco para instalar nem cuidar, e a cópia de segurança copia esse arquivo.</p>'
            . '<h2>Nada do que você ajustou é sobrescrito</h2>'
            . '<p>Ao ligar um modelo opcional ou atualizar o programa, o Publicare copia os arquivos de pele que faltam e só troca um arquivo se ele ainda for, byte a byte, uma versão distribuída antes. O que você personalizou fica como está e vira um aviso.</p>'
            . '<h2>Endereços que não quebram</h2>'
            . '<p>Mudar o endereço de uma página mantém o antigo funcionando, com redirecionamento permanente.</p>'
            . '<h2>Português de ponta a ponta</h2>'
            . '<p>Comandos, mensagens e documentação são em português.</p>';
    }

    private static function instalacao(): string
    {
        $guia = self::REPOSITORIO . '/blob/main/docs/usuario/instalar.md';

        return '<h2>Requisitos do servidor</h2>'
            . '<p>Os requisitos atuais (versão do PHP e extensões) estão no <a href="' . $guia . '">guia de instalação</a> e são conferidos pelo assistente de instalação antes de qualquer coisa ser criada.</p>'
            . '<ul><li>A pasta <code>dados/</code> precisa ser gravável pelo PHP: é onde ficam o banco, o cache, as peles e os arquivos enviados.</li>'
            . '<li>Servidor Apache ou nginx, ambos testados. Não é preciso criar banco de dados: o padrão é um arquivo SQLite.</li></ul>'
            . '<h2>Como instalar</h2>'
            . '<ol><li><strong>Pelo navegador:</strong> um assistente confere os requisitos, cria a conta de administrador e o site.</li>'
            . '<li><strong>Pela linha de comando:</strong> <code>php bin/pbl instalar --nome=&quot;Meu site&quot;</code> cria o banco e um site com poucas páginas iniciais.</li>'
            . '<li><strong>Depois de instalar:</strong> publique o site com <code>php bin/pbl publicar</code>.</li></ol>'
            . '<p>O guia passo a passo está em <a href="' . $guia . '">docs/usuario/instalar.md</a>. Em hospedagem com painel Hestia ou VestaCP, siga a seção sobre <code>open_basedir</code> desse guia.</p>'
            . '<h2>Depois de instalar</h2>'
            . '<ul><li>Crie no painel da hospedagem uma tarefa de cron a cada minuto: <code>php bin/pbl cron</code>. Sem ela, nada agendado acontece.</li>'
            . '<li>Ligue a cópia de segurança agendada e baixe uma cópia de vez em quando: uma cópia no mesmo servidor não protege se o servidor for perdido.</li>'
            . '<li>Para atualizar, veja <a href="' . self::REPOSITORIO . '/blob/main/docs/usuario/atualizar.md">docs/usuario/atualizar.md</a>.</li></ul>';
    }

    private static function comunidade(): string
    {
        return '<h2>Se você usa, ou usaria, o Publicare</h2>'
            . '<ul><li><strong>Conte o que você precisa.</strong> Abra uma proposta no GitHub dizendo qual problema quer resolver. Descrever a situação ajuda mais do que descrever a solução.</li>'
            . '<li><strong>Teste e relate.</strong> Se algo não funcionar, abra um problema com os passos para repetir. Capturas de tela ajudam.</li>'
            . '<li><strong>Tem um site no Publicare antigo?</strong> Conte como ele é. Isso orienta o importador. Não publique dados de terceiros.</li></ul>'
            . '<h2>Se você escreve ou ensina</h2>'
            . '<p>Melhore a documentação: erro de português, passo que faltou, explicação que ficou difícil. Pode abrir o pull request direto.</p>'
            . '<h2>Se você programa</h2>'
            . '<p>Leia o <a href="' . self::REPOSITORIO . '/blob/main/CONTRIBUTING.md">guia de colaboração</a>. Antes de abrir um pull request, rode <code>make qa</code>: são os testes e a análise estática que a pipeline também roda. Todo pull request atualiza o CHANGELOG.</p>'
            . '<h2>Se você achou uma falha de segurança</h2>'
            . '<p>Não abra uma issue pública. Use o relato privado do GitHub, descrito na <a href="' . self::REPOSITORIO . '/blob/main/SECURITY.md">política de segurança</a>.</p>'
            . '<h2>Conduta</h2>'
            . '<p>Ao participar, você aceita o <a href="' . self::REPOSITORIO . '/blob/main/CODE_OF_CONDUCT.md">Código de Conduta</a>.</p>';
    }

    private static function acompanhar(): string
    {
        $r = self::REPOSITORIO;

        return '<h2>Onde está cada coisa</h2>'
            . '<p>Esta página não guarda números de versão nem datas, para não ficar velha. O que muda fica nestes endereços, que são sempre os atuais:</p>'
            . '<ul><li><strong>Versões publicadas:</strong> a página de <a href="' . $r . '/releases">releases no GitHub</a>.</li>'
            . '<li><strong>O que mudou em cada versão:</strong> o <a href="' . $r . '/blob/main/CHANGELOG.md">CHANGELOG</a>.</li>'
            . '<li><strong>O plano do projeto, por fases:</strong> <a href="' . $r . '/blob/main/docs/plano.md">docs/plano.md</a>.</li>'
            . '<li><strong>O que já existe e o que falta:</strong> <a href="' . $r . '/blob/main/docs/usuario/estado-do-projeto.md">docs/usuario/estado-do-projeto.md</a>, mantido junto com o código.</li>'
            . '<li><strong>Propostas e problemas:</strong> as <a href="' . $r . '/issues">issues do GitHub</a>.</li></ul>'
            . '<h2>Canais beta e estável</h2>'
            . '<p>As versões de pré-lançamento saem no canal beta e como pré-lançamento no GitHub. As versões sem sufixo saem no canal estável. Para escolher o canal e atualizar, veja <a href="' . $r . '/blob/main/docs/usuario/atualizar.md">docs/usuario/atualizar.md</a> e <a href="' . $r . '/blob/main/docs/releases.md">docs/releases.md</a>.</p>'
            . '<h2>Como participar</h2>'
            . '<p>Veja <a href="/comunidade">Comunidade e contribuição</a>.</p>';
    }

    /**
     * Perguntas frequentes (12), com categoria. A resposta é HTML permitido por `HtmlSeguro`.
     *
     * @return list<array{0: string, 1: string, 2: string}> [pergunta, resposta, categoria]
     */
    public static function perguntas(): array
    {
        return [
            ['O que é o Publicare 4?', '<p>Uma plataforma livre para montar sites e pequenos sistemas sem programar, em hospedagem comum. É a reconstrução do Publicare, que nasceu no Brasil em 2003. <a href="/o-que-e">Saiba mais</a>.</p>', 'Primeiros passos'],
            ['Preciso saber programar?', '<p>Para editar conteúdo, não. Criar páginas, tipos de conteúdo, menus e formulários é feito por telas. Para mudar a aparência além da pele pronta, é preciso ajustar moldes, o que exige um pouco de conhecimento técnico. Instalar e manter o servidor pede algum conhecimento técnico de hospedagem: veja <a href="/instalacao-e-requisitos">Instalação e requisitos</a>.</p>', 'Primeiros passos'],
            ['É grátis? Qual é a licença?', '<p>Sim. O Publicare 4 é software livre, com licença Apache-2.0: você pode usar, estudar, modificar e distribuir sem pagar. Você só paga o que for seu, como hospedagem e domínio. A marca &quot;Publicare&quot; não é licenciada.</p>', 'Primeiros passos'],
            ['Quais são os requisitos para instalar?', '<p>Um servidor com PHP e a pasta <code>dados/</code> gravável. Não é preciso criar banco de dados. A versão do PHP e as extensões exigidas estão sempre no guia de instalação e são conferidas pelo assistente. Veja <a href="/instalacao-e-requisitos">Instalação e requisitos</a>.</p>', 'Instalação e operação'],
            ['Como instalo?', '<p>Pelo navegador, com um assistente que confere o servidor, ou pela linha de comando com <code>php bin/pbl instalar</code>. O guia está em <a href="/instalacao-e-requisitos">Instalação e requisitos</a>.</p>', 'Instalação e operação'],
            ['Como atualizo?', '<p>Pelo painel, na tela Atualização (só administrador, com a senha de novo), ou por <code>php bin/pbl atualizar</code>. O Publicare só aceita pacotes assinados pela equipe do projeto, guarda uma cópia do banco antes e volta sozinho à versão anterior se a atualização falhar. O conteúdo, as contas e as peles ficam na pasta <code>dados/</code>, que a atualização não toca.</p>', 'Instalação e operação'],
            ['Como faço cópia de segurança?', '<p>Pela tela Cópias de segurança do painel, que também agenda cópias automáticas, ou por <code>php bin/pbl backup criar</code>. Baixe uma cópia de vez em quando e guarde fora do servidor: a cópia no mesmo servidor não protege se o servidor for perdido.</p>', 'Instalação e operação'],
            ['Posso migrar do Publicare 3?', '<p>Há dois caminhos. O importador (<code>php bin/pbl importar-pbl3</code>) leva conteúdo, arquivos, contas e tags; as senhas precisam ser redefinidas. O conversor (<code>php bin/pbl converter-moldes</code>) traduz os moldes e gera um relatório do que precisa de revisão manual, porque moldes antigos costumam misturar código PHP, que o novo não aceita.</p>', 'Instalação e operação'],
            ['Quantos sites cabem em uma instalação?', '<p>Um mesmo programa serve vários sites, cada um com seus domínios, e um site pode ter mais de um domínio. O projeto não publica um limite nem números de desempenho.</p>', 'Instalação e operação'],
            ['O Publicare 4 é acessível?', '<p>A pele deste site foi feita para ser: link para pular ao conteúdo, foco visível, uso por teclado, contraste conferido por teste automático, tema claro e escuro, respeito à preferência por menos movimento e campo de texto alternativo nas imagens. O painel tem ajuda acessível por teclado e leitor de tela. Isso não substitui uma auditoria externa de acessibilidade.</p>', 'Segurança e acessibilidade'],
            ['O Publicare 4 é seguro?', '<p>As proteções de base são: moldes sem PHP cru, saída escapada, HTML limpo ao salvar, senhas guardadas de forma que nem a equipe consegue ler, sessões com validade, espera crescente depois de senhas erradas e formulários protegidos contra pedidos forjados. Isso não substitui a revisão de quem instala. Para relatar uma falha, siga a <a href="/comunidade">política de segurança</a>.</p>', 'Segurança e acessibilidade'],
            ['Como posso contribuir? E o que são os canais beta e estável?', '<p>Veja <a href="/comunidade">Comunidade e contribuição</a>: dá para propor, testar, escrever documentação ou programar. Sobre os canais: as versões de pré-lançamento saem no canal beta; as versões sem sufixo saem no canal estável. Veja <a href="/como-acompanhar">como acompanhar o projeto</a>.</p>', 'Projeto'],
        ];
    }

    /**
     * Guias atemporais, no tipo `noticia` e sem data de publicação (nada que envelheça): conceitos do produto.
     *
     * @return list<array{url: string, titulo: string, resumo: string, corpo: string, tags: string}>
     */
    public static function guias(): array
    {
        return [
            ['url' => 'guia-revisao', 'titulo' => 'Como funciona a revisão', 'tags' => 'Revisão, Edição',
                'resumo' => 'Quem escreve envia o texto; quem edita aprova e publica, ou devolve com o motivo.',
                'corpo' => '<p>Quem tem o papel de autor escreve o conteúdo e o envia para revisão. Quem tem o papel de editor aprova e publica, ou devolve com o motivo, para o autor corrigir.</p><p>Cada versão fica no histórico do conteúdo, e é possível voltar a uma versão antiga: isso cria uma versão nova, e nada se perde.</p>'],
            ['url' => 'guia-pele', 'titulo' => 'O que é uma pele', 'tags' => 'Peles, Moldes',
                'resumo' => 'A pele é a aparência do site. Trocá-la muda o visual sem mexer no conteúdo.',
                'corpo' => '<p>Uma pele é um conjunto de moldes, arquivos <code>.pbl</code>, escritos na linguagem de moldes do PBL4. Ela define o cabeçalho, o rodapé e o desenho de cada tipo de conteúdo.</p><p>É possível escolher uma pele e um molde só para um conteúdo e tudo abaixo dele. A documentação da linguagem está em <a href="' . self::REPOSITORIO . '/blob/main/docs/linguagem-pbl4.md">docs/linguagem-pbl4.md</a>.</p>'],
            ['url' => 'guia-publicacao', 'titulo' => 'Do rascunho ao ar', 'tags' => 'Publicação',
                'resumo' => 'Todo conteúdo novo começa fora do ar. Ao publicar, as páginas prontas são gravadas e entregues.',
                'corpo' => '<p>Todo conteúdo novo começa fora do ar, como rascunho, e passa a publicado quando alguém publica. A publicação também pode ser agendada, com data para entrar e para sair do ar.</p><p>Ao publicar, o Publicare grava as páginas prontas e as entrega a partir desse cache.</p>'],
            ['url' => 'guia-tipos', 'titulo' => 'O que é um tipo de conteúdo', 'tags' => 'Tipos, Conteúdo',
                'resumo' => 'Um tipo diz quais campos um conteúdo tem e onde ele pode ficar na árvore.',
                'corpo' => '<p>Notícia, evento e documento são tipos de conteúdo. O administrador cria um tipo pelo navegador, adiciona campos e define onde ele pode ficar. Um campo que já tem dados é arquivado em vez de apagado.</p>'],
        ];
    }

    /**
     * As afirmações do texto sobre o produto, cada uma com a fonte e a forma de conferir que o recurso existe.
     * `texto` aparece literalmente no conteúdo; a verificação é uma entre:
     * `comando` (subcomando de bin/pbl), `classe` (nome completo), `arquivo` (caminho no repositório) ou
     * `contem` ([arquivo, trecho]: o arquivo tem o trecho).
     *
     * @return list<array{texto: string, fonte: string, verificacao: array<string, string|list<string>>}>
     */
    public static function afirmacoes(): array
    {
        return [
            ['texto' => 'licença Apache-2.0', 'fonte' => 'LICENSE', 'verificacao' => ['contem' => ['LICENSE', 'Apache License']]],
            ['texto' => 'o Publicare 3 e o anterior continuam sob a licença MIT', 'fonte' => 'README.md', 'verificacao' => ['contem' => ['README.md', 'continuam MIT']]],
            ['texto' => 'Nasceu no Brasil em 2003', 'fonte' => 'docs/usuario/o-que-e.md', 'verificacao' => ['contem' => ['docs/usuario/o-que-e.md', 'nasceu no Brasil em 2003']]],
            ['texto' => 'Servidor Apache ou nginx, ambos testados', 'fonte' => 'docs/usuario/perguntas-frequentes.md', 'verificacao' => ['contem' => ['docs/usuario/perguntas-frequentes.md', 'Apache** (a maioria das hospedagens compartilhadas) e **nginx**, ambos testados']]],
            ['texto' => 'Conteúdo em árvore de objetos', 'fonte' => 'docs/usuario/o-que-e.md', 'verificacao' => ['classe' => 'Pbl\\Conteudo\\ObjetoRepositorio']],
            ['texto' => 'Tipos de conteúdo criados pelo navegador', 'fonte' => 'docs/usuario/estado-do-projeto.md', 'verificacao' => ['contem' => ['app/src/Admin/Pagina.php', '/_pbl/classes']]],
            ['texto' => 'Um campo com dados nunca é apagado nem muda de tipo', 'fonte' => 'docs/usuario/perguntas-frequentes.md', 'verificacao' => ['contem' => ['docs/usuario/perguntas-frequentes.md', 'não deixa apagar nem trocar o tipo']]],
            ['texto' => 'lixeira que permite restaurar', 'fonte' => 'docs/usuario/estado-do-projeto.md', 'verificacao' => ['arquivo' => 'app/src/Admin/TelasDeLixeira.php']],
            ['texto' => 'Tags nos conteúdos', 'fonte' => 'docs/adr/0024-tags-nos-objetos.md', 'verificacao' => ['arquivo' => 'docs/adr/0024-tags-nos-objetos.md']],
            ['texto' => 'A edição de um conteúdo é dividida em abas: Conteúdo, Publicação e Avançado', 'fonte' => 'docs/usuario/painel.md', 'verificacao' => ['contem' => ['docs/usuario/painel.md', 'A edição tem três abas']]],
            ['texto' => 'Editor de texto rico', 'fonte' => 'docs/adr/0020-editor-de-texto-rico.md', 'verificacao' => ['arquivo' => 'docs/adr/0020-editor-de-texto-rico.md']],
            ['texto' => 'HTML é limpo ao salvar', 'fonte' => 'app/src/Conteudo/HtmlSeguro.php', 'verificacao' => ['classe' => 'Pbl\\Conteudo\\HtmlSeguro']],
            ['texto' => 'Barra de edição nas páginas do site', 'fonte' => 'docs/usuario/barra-de-edicao.md', 'verificacao' => ['arquivo' => 'docs/usuario/barra-de-edicao.md']],
            ['texto' => 'Todo conteúdo novo começa fora do ar', 'fonte' => 'docs/usuario/perguntas-frequentes.md', 'verificacao' => ['contem' => ['docs/usuario/perguntas-frequentes.md', 'o conteúdo novo começa fora do ar']]],
            ['texto' => 'Publicação agendada', 'fonte' => 'docs/usuario/perguntas-frequentes.md', 'verificacao' => ['comando' => 'agendados']],
            ['texto' => 'Fluxo de revisão', 'fonte' => 'docs/usuario/estado-do-projeto.md', 'verificacao' => ['classe' => 'Pbl\\Conteudo\\FluxoDeRevisao']],
            ['texto' => 'Histórico de versões de cada conteúdo', 'fonte' => 'docs/usuario/estado-do-projeto.md', 'verificacao' => ['contem' => ['docs/usuario/estado-do-projeto.md', 'Histórico de versões de cada conteúdo, com restaurar e proteção contra edição simultânea']]],
            ['texto' => 'linguagem de moldes do PBL4', 'fonte' => 'docs/linguagem-pbl4.md', 'verificacao' => ['arquivo' => 'docs/linguagem-pbl4.md']],
            ['texto' => 'Editor de moldes no navegador', 'fonte' => 'docs/usuario/estado-do-projeto.md', 'verificacao' => ['contem' => ['docs/usuario/estado-do-projeto.md', 'editar o texto dos moldes de uma pele, com conferência da sintaxe antes de salvar']]],
            ['texto' => 'Peles prontas, entre elas a publicare', 'fonte' => 'app/recursos/peles', 'verificacao' => ['arquivo' => 'app/recursos/peles/publicare/padrao.pbl']],
            ['texto' => 'Vários sites e vários domínios no mesmo programa', 'fonte' => 'docs/usuario/estado-do-projeto.md', 'verificacao' => ['classe' => 'Pbl\\Conteudo\\DominioRepositorio']],
            ['texto' => 'Mudar o endereço de uma página sem quebrar o antigo', 'fonte' => 'docs/usuario/estado-do-projeto.md', 'verificacao' => ['classe' => 'Pbl\\Conteudo\\RedirecionamentoRepositorio']],
            ['texto' => 'Busca que ignora maiúsculas e acentos', 'fonte' => 'docs/linguagem-pbl4.md', 'verificacao' => ['classe' => 'Pbl\\Busca\\BuscaSql']],
            ['texto' => 'Formulário público simples', 'fonte' => 'docs/adr/0013-formularios-publicos.md', 'verificacao' => ['classe' => 'Pbl\\Formularios\\FormularioHtml']],
            ['texto' => 'texto alternativo para leitores de tela', 'fonte' => 'app/src/Conteudo/ClassesPadrao.php', 'verificacao' => ['contem' => ['app/src/Conteudo/ClassesPadrao.php', 'Texto alternativo (para leitores de tela)']]],
            ['texto' => 'Feed RSS, sitemap.xml e robots.txt automáticos', 'fonte' => 'docs/usuario/estado-do-projeto.md', 'verificacao' => ['classe' => 'Pbl\\Publicacao\\SitemapERobots']],
            ['texto' => 'Cópias de segurança agendadas', 'fonte' => 'docs/usuario/copias-de-seguranca.md', 'verificacao' => ['comando' => 'backup']],
            ['texto' => 'Atualização assinada, com cópia do banco antes e volta atrás', 'fonte' => 'docs/usuario/atualizar.md', 'verificacao' => ['comando' => 'atualizar']],
            ['texto' => 'Uma atualização concluída também pode ser desfeita', 'fonte' => 'docs/usuario/estado-do-projeto.md', 'verificacao' => ['comando' => 'reverter']],
            ['texto' => 'Cron central', 'fonte' => 'docs/adr/0023-cron-central-e-republicacao-em-lotes.md', 'verificacao' => ['comando' => 'cron']],
            ['texto' => 'Linha de comando em português', 'fonte' => 'docs/tecnica/cli.md', 'verificacao' => ['arquivo' => 'bin/pbl']],
            ['texto' => 'Papéis por seção do site (leitor, autor e editor)', 'fonte' => 'docs/usuario/perguntas-frequentes.md', 'verificacao' => ['classe' => 'Pbl\\Acesso\\Perfil']],
            ['texto' => 'Recuperação de acesso por e-mail', 'fonte' => 'docs/usuario/recuperar-acesso.md', 'verificacao' => ['classe' => 'Pbl\\Acesso\\RecuperacaoDeAcesso']],
            ['texto' => 'convite de usuário por link', 'fonte' => 'CHANGELOG.md', 'verificacao' => ['classe' => 'Pbl\\Acesso\\ConviteDeUsuario']],
            ['texto' => 'Importador de um site feito no Publicare 3', 'fonte' => 'docs/tecnica/migrar-do-pbl3.md', 'verificacao' => ['comando' => 'importar-pbl3']],
            ['texto' => 'Conversor de moldes do Publicare 3', 'fonte' => 'docs/tecnica/migrar-do-pbl3.md', 'verificacao' => ['comando' => 'converter-moldes']],
            ['texto' => 'bin/pbl conta-senha', 'fonte' => 'docs/usuario/perguntas-frequentes.md', 'verificacao' => ['comando' => 'conta-senha']],
            ['texto' => 'bin/pbl conta-destravar', 'fonte' => 'docs/usuario/perguntas-frequentes.md', 'verificacao' => ['comando' => 'conta-destravar']],
            ['texto' => 'php bin/pbl publicar', 'fonte' => 'docs/tecnica/cli.md', 'verificacao' => ['comando' => 'publicar']],
            ['texto' => 'php bin/pbl instalar', 'fonte' => 'docs/tecnica/cli.md', 'verificacao' => ['comando' => 'instalar']],
            ['texto' => 'php bin/pbl cron', 'fonte' => 'docs/tecnica/cron.md', 'verificacao' => ['comando' => 'cron']],
            ['texto' => 'não aceita PHP cru', 'fonte' => 'SECURITY.md', 'verificacao' => ['contem' => ['SECURITY.md', 'sem PHP cru']]],
            ['texto' => 'Publicar um site grande acontece em lotes, com barra de progresso e retomada', 'fonte' => 'docs/usuario/estado-do-projeto.md', 'verificacao' => ['contem' => ['docs/usuario/estado-do-projeto.md', 'em lotes, retomável']]],
            ['texto' => 'atualizador só aceita pacotes com a assinatura digital', 'fonte' => 'docs/usuario/atualizar.md', 'verificacao' => ['classe' => 'Pbl\\Atualizacao\\Assinatura']],
            ['texto' => 'política de segurança de conteúdo restritiva', 'fonte' => 'docs/adr/0022-pele-e-molde-por-objeto.md', 'verificacao' => ['contem' => ['app/src/Http/CabecalhosDeSeguranca.php', 'Content-Security-Policy']]],
            ['texto' => 'só troca um arquivo se ele ainda for, byte a byte, uma versão distribuída antes', 'fonte' => 'app/src/Instalacao/PelesDistribuidas.php', 'verificacao' => ['contem' => ['app/src/Instalacao/PelesDistribuidas.php', 'byte a byte']]],
            ['texto' => 'Português de ponta a ponta', 'fonte' => 'docs/usuario/o-que-e.md', 'verificacao' => ['contem' => ['docs/usuario/o-que-e.md', 'Português de ponta a ponta']]],
            ['texto' => 'canal beta', 'fonte' => 'docs/releases.md', 'verificacao' => ['contem' => ['docs/releases.md', 'Tags com `-`']]],
            ['texto' => 'Todo pull request atualiza o CHANGELOG', 'fonte' => 'AGENTS.md', 'verificacao' => ['contem' => ['AGENTS.md', 'Todo PR atualiza o `CHANGELOG.md`']]],
            ['texto' => 'make qa', 'fonte' => 'CONTRIBUTING.md', 'verificacao' => ['contem' => ['CONTRIBUTING.md', 'make qa']]],
            ['texto' => 'espera crescente depois de senhas erradas', 'fonte' => 'docs/usuario/perguntas-frequentes.md', 'verificacao' => ['classe' => 'Pbl\\Acesso\\LimiteDeTentativas']],
            ['texto' => 'contraste conferido por teste automático', 'fonte' => 'tests/PublicarePeleTest.php', 'verificacao' => ['arquivo' => 'tests/PublicarePeleTest.php']],
            ['texto' => 'Código de Conduta', 'fonte' => 'CODE_OF_CONDUCT.md', 'verificacao' => ['arquivo' => 'CODE_OF_CONDUCT.md']],
            ['texto' => 'relato privado do GitHub', 'fonte' => 'SECURITY.md', 'verificacao' => ['contem' => ['SECURITY.md', 'security/advisories/new']]],
        ];
    }
}

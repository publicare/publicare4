# Estado do projeto

Última atualização: **novembro de 2026** (fluxo de revisão: autor envia, editor aprova ou devolve com motivo; editor de texto rico nos campos de texto formatado; licença Apache-2.0 e custódia da chave de assinatura decididas; painel mais legível no celular: menu hambúrguer, campos com destaque, campos de tipo em painéis recolhíveis e tabelas em cartões; voltar atrás de uma atualização concluída e aviso de versão nova na página inicial; tela de atualização no painel; contas, domínios, redirecionamentos e peles na administração; integridade do conteúdo, HTML limpo, varredura do HTML gravado antes dessa limpeza, publicação agendada, mover pela árvore e formulários públicos simples; modo elevado nas ações sensíveis do painel e lista de contas paginada/com busca; histórico, rascunho/pré-visualização e realce de leitura no editor de peles). Versão atual: **em desenvolvimento** (a primeira versão pública prevista é a `0.1.0`).

## Legenda

✅ funciona hoje · 🔨 em construção · 🗓️ planejado · 💡 ideia para depois

## Resumo honesto

O Publicare 4 está nos **primeiros passos**. Hoje ele serve a quem **desenvolve** e a quem quer **acompanhar ou testar**; ainda **não** serve para colocar um site no ar sem ajuda técnica. Já dá para instalar e entrar pelo navegador, ver os sites, andar pelas páginas, corrigir o título e a descrição de uma página e, se você é administrador, criar e ajustar os **tipos de conteúdo** (quais campos uma notícia ou um evento tem). Criar, mover, publicar ou apagar páginas, preencher os campos de um conteúdo e cuidar das contas ainda se faz por linha de comando.

## O que já funciona

| Recurso | Estado |
|---|---|
| Instalar (por linha de comando) com um site de exemplo | ✅ |
| **Cron central**: uma linha de cron por minuto (`bin/pbl cron`) cuida da publicação por data, da republicação pendente, das limpezas e da checagem de atualização; tela **Tarefas agendadas** (última/próxima execução, resultado, "Executar agora"), aviso se o cron parou e pseudo-cron opcional para hospedagem sem cron | ✅ ([guia](../tecnica/cron.md)) |
| **Republicar com barra de progresso** ("42 de 92 páginas"), em lotes, retomável e sem republicações simultâneas; também no `bin/pbl publicar` | ✅ |
| Criar conteúdos em árvore | ✅ (por código; sem tela ainda) |
| Tipos de conteúdo pelo navegador (só administrador): criar, descrever, dizer que tipos podem ficar dentro, adicionar campos, mudar rótulo, ordem e obrigatório, arquivar campo e apagar tipo sem uso, sem nunca apagar dado guardado | ✅ (e a exigência de "obrigatório" e de "tipos que podem ficar dentro" vale ao salvar qualquer conteúdo, pelo navegador ou pela linha de comando) |
| Tela **Conteúdo**: navegar pela árvore por níveis, filtrar, buscar e ordenar, e publicar, despublicar, enviar para revisão ou mandar para a lixeira, um a um ou em lote; depois de gravar, o painel mostra a página renderizada com uma faixa dizendo o que aconteceu (mover em lote e reordenar por setas ficam para depois) | ✅ |
| **Tags** nos conteúdos: campo na edição, links nas notícias, página de cada tag (`/tag/assunto`) e nuvem de tags para os moldes; o conversor do PBL3 as aproveita (ainda não entram na busca por texto) | ✅ |
| Fluxo de revisão: o autor envia o texto para revisão, o editor da seção (ou o administrador) aprova e publica ou devolve com o motivo; telas **Pendências** e **Meus envios**, contador no menu e histórico de cada passo (sem aviso por e-mail ainda) | ✅ |
| Site novo já nasce com exemplos (Sobre, Notícias com uma notícia, Contato com formulário) e os tipos básicos: pasta, página, formulário, arquivo, imagem, link e notícia. Os seis primeiros são **fixos** (não se apagam) | ✅ |
| Link para outra página do site (acompanha o endereço dela) ou para um endereço externo, com a opção de abrir em nova aba | ✅ |
| Título, descrição e imagem para buscadores e redes sociais (opcionais) em páginas, pastas e notícias | ✅ (o endereço completo e o `sitemap.xml` ainda não) |
| Menu principal do site (escolher quais páginas entram, o nome no menu e a ordem), com submenus (funciona sem JavaScript; com ele ganha botão de submenu) | ✅, com aviso no painel quando o menu é cortado ou a republicação está pendente, e botão "Republicar agora"; `sitemap.xml` e `robots.txt` automáticos |
| Configurações do site pelo navegador, em abas (Identidade, Busca e compartilhamento, Contato, Rodapé e métricas, Comportamento; cada aba grava só o seu grupo), com envio direto de logo, ícone e imagem padrão em PNG, JPG ou WebP (nome, idioma, textos de SEO, imagem e ícone, contato, redes sociais, rodapé, busca ligada ou não, itens por página, profundidade do menu, permitir ou não buscadores), que as páginas usam ao serem republicadas | ✅ (a pele básica ainda não mostra contato, redes sociais nem analytics) |
| Ajuda do painel atrás de um botão "?" (campo, bloco e título da tela), acessível por teclado e leitor de tela, e blocos secundários recolhidos | ✅ (SVG e `.ico` ainda não são aceitos como ícone; "Menu e publicação" e "Tarefas agendadas" entram no mesmo padrão quando a outra frente terminar) |
| Modelos opcionais prontos (agenda de eventos, perguntas frequentes, destaques da página inicial e blocos reutilizáveis), que se ligam na instalação, pelo painel ou por comando, e um site de demonstração ("Instituto Exemplo") para ver tudo funcionando | ✅ ([guia](modelos-e-demo.md)) |
| Visual da pele básica e da página inicial: cabeçalho com marca, busca e menu alinhados, destaque, cartões de atalho (por exemplo, para outras versões do site), notícias com miniatura e data, tema claro/escuro e leitura boa no celular; com JavaScript (opcional, próprio do site): menu em hambúrguer, carrossel dos destaques, perguntas frequentes em sanfona, links rápidos recolhíveis, "voltar ao topo" e efeitos leves. Sem JavaScript tudo continua legível | ✅ (sem teste em navegador real) |
| Busca no site, listas de notícias com páginas e página de "não encontrado" com o visual do site | ✅ |
| Linguagem de moldes para montar páginas (listas, menus, herança de layout) | ✅ |
| Publicar as páginas e entregá-las prontas, com cache | ✅ |
| Vários sites e domínios no mesmo programa | ✅ |
| Conversor de moldes do Publicare antigo (PBL3) para o novo, com relatório | ✅ |
| Proteções de segurança básicas nos moldes e nas URLs | ✅ |
| Histórico de versões de cada conteúdo, com restaurar e proteção contra edição simultânea | ✅ |
| Vários domínios para o mesmo site (um principal e os outros mostrando o mesmo conteúdo ou levando ao principal), também pelo navegador: adicionar, trocar o principal, virar alias ou redirecionador e remover | ✅ |
| Redirecionar endereços antigos que não são páginas atuais (de sistemas que acabaram, com `.php` ou maiúsculas) | ✅ (pelo navegador e por linha de comando) |
| Mudar o endereço de uma página sem quebrar o antigo (o antigo passa a levar ao novo) | ✅ |
| Mover uma página (com tudo que está dentro dela) na árvore | ✅ |
| Contas da equipe com senha segura e papéis por seção do site (leitor, autor, editor) e administrador geral, com registro de quem fez o quê (por linha de comando e, para o administrador, pelo navegador: criar conta, editar, desativar, redefinir senha e conceder papéis; lista com busca e paginada) | ✅ |
| Ações sensíveis do painel (contas, domínios, apagar tipo de conteúdo, moldes, pele ativa, atualizar/reverter) pedem a senha de novo, com uma janela de alguns minutos em que as próximas não pedem outra vez | ✅ |
| Instalar pelo navegador (assistente com requisitos, conta de administrador e site de exemplo), com proteção para ninguém instalar no seu lugar | ✅ |
| Instalar em hospedagem com painel Hestia/VestaCP (`open_basedir`), com guia e avisos claros no assistente (falta de SQLite, pasta fora do alcance do PHP) | ✅ |
| Entrar e sair pelo navegador, com sessão segura e proteção contra tentativas em massa e contra pedidos forjados | ✅ |
| Administração pelo navegador: ver os sites que você pode acessar e andar pelas páginas de cada um (inclusive as que ainda não estão no ar) | ✅ |
| Corrigir o título e a descrição de uma página pelo navegador, com aviso se outra pessoa mudou a página ao mesmo tempo; a página no ar é atualizada na hora | ✅ |
| Pelo navegador: criar páginas e outros conteúdos, editar todos os campos, publicar ou tirar do ar (com datas), mover, mandar para a lixeira e tirar de lá, ver o histórico e voltar a uma versão antiga; ordenar e filtrar a lista de conteúdo | ✅ |
| Pelo navegador (só administrador): editar o texto dos moldes de uma pele, com conferência da sintaxe antes de salvar, criar e apagar moldes e escolher a pele do site; as páginas são republicadas em seguida | ✅ |
| Histórico de cada molde (quem salvou, criou ou apagou, e quando), diferença entre duas versões e "voltar a esta versão" (com a mesma conferência de sintaxe de uma edição comum) | ✅ (até 200 versões guardadas por molde) |
| Salvar um molde como rascunho (sem publicar) e pré-visualizar uma página do site com o rascunho, sem afetar o que está no ar | ✅ (a pré-visualização é só para quem administra o site) |
| Ver o conteúdo de um molde com as cores da sintaxe (histórico, diferença e visualização) | ✅ (só leitura; a caixa de edição continua texto simples) |
| Campos de texto com HTML são limpos ao salvar (sem scripts, estilos, formulários nem eventos; só marcações comuns, links e imagens seguros) | ✅ |
| Editor de texto rico nos campos de texto formatado: títulos, tamanho, negrito/itálico/sublinhado, listas, alinhamento, links, tabelas, imagens por endereço, desfazer/refazer, limpar formatação, código-fonte e colar do Word, Google Docs ou de uma página (o lixo do Word é limpo; o servidor limpa de novo ao salvar). Sem JavaScript, o campo segue como caixa de HTML | ✅ (ainda sem teste em navegador real; imagens só por endereço) |
| Varredura do HTML gravado antes dessa limpeza existir, ou importado sem passar pela gravação normal (`bin/pbl sanitizar-html`, com simulação) | ✅ |
| Publicar numa data futura e tirar do ar numa data de validade: a página mostra "agendado" e o comando `bin/pbl agendados` (para o cron do servidor) põe no ar e tira na hora certa | ✅ (precisa agendar o comando no servidor) |
| Mover um conteúdo escolhendo o novo lugar andando pela árvore, sem digitar número | ✅ |
| Busca que ignora maiúsculas e acentos (`presidencia` acha `Presidência`) | ✅ |
| Busca com várias palavras (todas), começo de palavra (`presid` acha `Presidência`), plural e singular (`ações`/`ação`) e ordem por relevância (título antes de descrição e texto), no título, na descrição e no texto | ✅ |
| Busca que corrige erros de digitação (`Fernandha` sugere `Fernanda`, "você quis dizer…") | ✅ (opcional por site, desligada por padrão — liga no painel, em cada site; nunca sugere termo de conteúdo privado ou fora do ar) |
| Ligar conteúdos entre si (uma notícia aponta para seu autor) e, nos moldes, mostrar, filtrar e ordenar pelo conteúdo apontado | ✅ |
| **Formulário público simples** (inscrição, contato, cadastro): você escolhe o tipo de conteúdo, quais campos mostrar e onde guardar; cada envio chega **privado**, a equipe vê em "Envios recebidos" e aprova publicando. Com proteção contra robôs (campo escondido), limite de envios por pessoa, conferência de origem e conteúdo limpo | ✅ (o formulário é um conteúdo comum, sem editor visual; veja o [ADR 0013](../adr/0013-formularios-publicos.md)) |
| **Pele e molde por conteúdo**: escolha, na edição, uma pele e um molde só para aquele conteúdo e tudo abaixo dele (herdam); menu e migalhas partem dali. Peles prontas: básica (com molde de página inicial; JavaScript opcional), **onepage** (uma página com seções) e **portal** (várias páginas com notícias); as duas últimas usam Bootstrap hospedado no próprio site | ✅ |
| **Barra de edição nas páginas do site** para quem está logado (editar, publicar, despublicar, mover, excluir) e botão **Gravar e publicar** na edição | ✅ |
| Consultas com até três condições, comparações (maior, menor, contém) e carga rápida de listas | ✅ |
| **Upload de arquivo e imagem**: um tipo de conteúdo pode ter um campo de arquivo (documento) ou de imagem, com os tipos aceitos e o tamanho máximo configuráveis por campo; você envia pelo navegador, ao criar ou editar o conteúdo (na criação, o envio e a criação são uma coisa só: se o arquivo falhar, nada é criado), e o PBL4 confere o tipo de verdade (não só pelo nome), recodifica a imagem quando possível e só mostra o arquivo em páginas publicadas. Os moldes mostram o link, o nome, o tamanho, o tipo e a miniatura do arquivo | ✅ (veja o [ADR 0014](../adr/0014-upload-de-arquivos.md)) |
| **Feed RSS** de cada seção (`/<seção>/feed.xml`) e do site (`/feed.xml`), atualizado ao publicar, só com o que já está no ar; liga e desliga e escolhe quantos itens nas configurações do site | ✅ (só RSS; Atom e saídas JSON/XML ainda não) |

## O que vem a seguir

| Recurso | Estado |
|---|---|
| Busca para portais muito grandes (centenas de milhares de páginas) com serviço de busca próprio | 🗓️ |
| Atualização automática com cópia de segurança e volta atrás | 🔨 (funciona por linha de comando e pelo painel — tela **Atualização**, só administrador, pede a senha de novo —, com assinatura, cópia do banco, volta atrás automática se a atualização falhar, aviso de versão nova na página inicial (lendo o cache; sem chamada de rede ao abrir a página) e `bin/pbl verificar-atualizacao` para o cron atualizar esse cache; `bin/pbl reverter` (e o botão correspondente) desfaz uma atualização **já concluída**, devolvendo código e banco de antes dela, com as mesmas conferências de segurança; o suporte a duas chaves de produção (uso e recuperação), revogação e canal de teste já existe; falta o dono gerar as chaves de produção e publicar a primeira versão, então ainda não há o que baixar). Licença: Apache-2.0 |
| Editor de peles completo (hoje tem histórico, rascunho/pré-visualização e realce só leitura; faltam arquivos de CSS e imagens, importar/exportar pele, pele filha) | 🔨 |
| Galeria de peles prontas para instalar | 🗓️ |
| Formulários com aprovação em etapas, aviso por e-mail do fluxo de revisão, anexos, captcha, prazos, vagas e participante identificado; editor visual de formulários | 🗓️ |
| Importar um site feito no Publicare antigo | 🗓️ |
| Inscrição paga e outros pagamentos | 💡 |
| Peles avançadas e comunidade de autores | 💡 |

O plano completo, por fases, está em [docs/plano.md](../plano.md). O que mudou em cada versão fica no [CHANGELOG](../../CHANGELOG.md).

## Posso usar para um site de verdade?

**Ainda não** sem apoio técnico. Se você tem um site feito no Publicare antigo e quer saber o que acontece com ele, veja as [perguntas frequentes](perguntas-frequentes.md#e-o-meu-site-feito-no-publicare-antigo).

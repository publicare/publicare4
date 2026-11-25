# Publicare para quem usa

Este é o **manual de uso** do Publicare 4: para quem quer criar e cuidar de um site (ou de um pequeno sistema na internet) sem precisar programar. Quem desenvolve vai para a [documentação técnica](../tecnica/README.md).

**Fonte única:** estes arquivos são a única fonte do manual. A cada release, o comando `bin/pbl manual-gerar` converte esta pasta no manual publicado (veja [como o manual é gerado](../tecnica/manual.md)). Para mudar o manual, edite o arquivo daqui; quem muda uma tela do painel muda também o guia dela. O inventário do que existe no produto contra o que o manual cobre está em [INVENTARIO.md](INVENTARIO.md).

## Por público (trilhas)

O manual é seccionado em quatro trilhas; a página inicial, [Quem é você?](quem-e-voce.md), leva a cada uma. Uma página útil a mais de um público aparece em todas as trilhas dele, sem texto duplicado.

| Trilha | Para quem | Guias |
|---|---|---|
| [Editor](editor.md) | quem escreve e edita | [O que é o Publicare](o-que-e.md) · [Primeiros passos](primeiros-passos.md) · [Usar o painel](painel.md) · [Escrever e publicar](escrever-e-publicar.md) · [Revisão e histórico](revisao-e-historico.md) · [Arquivos e imagens](arquivos-e-imagens.md) · [Busca](busca.md) · [Barra de edição](barra-de-edicao.md) · [Recuperar o acesso](recuperar-acesso.md) · [Acessibilidade](acessibilidade.md) · [Perguntas frequentes](perguntas-frequentes.md) · [Glossário](glossario.md) |
| [Administrador](administrador.md) | quem administra o site | [Como instalar](instalar.md) · [Primeiros passos](primeiros-passos.md) · [Usar o painel](painel.md) · [Usuários e permissões](usuarios-e-permissoes.md) · [Recuperar o acesso](recuperar-acesso.md) · [Privacidade](privacidade.md) · [Tipos de conteúdo](tipos-de-conteudo.md) · [Peles e modelos](peles-e-moldes.md) · [Domínios e vários sites](dominios-e-varios-sites.md) · [Modelos e demo](modelos-e-demo.md) · [E-mail do site](email-do-site.md) · [Cópias de segurança](copias-de-seguranca.md) · [Atualizar](atualizar.md) · [Tarefas agendadas](tarefas-agendadas.md) · [Solução de problemas](solucao-de-problemas.md) · [Busca](busca.md) · [Agentes e scripts](agentes-e-scripts.md) · [Acessibilidade](acessibilidade.md) |
| [Desenvolvedor](desenvolvedor.md) | peles, moldes, tipos, cargas, linha de comando | [Tipos de conteúdo](tipos-de-conteudo.md) · [Peles e modelos](peles-e-moldes.md) · [Modelos e demo](modelos-e-demo.md) · [Barra de edição](barra-de-edicao.md) · [Agentes e scripts](agentes-e-scripts.md) · [Solução de problemas](solucao-de-problemas.md) · [Busca](busca.md) · **Linguagem PBL4:** [visão geral](pbl4-visao-geral.md) · [conceitos](pbl4-conceitos.md) · [valores e macros](pbl4-valores-e-macros.md) · [imprimir](pbl4-imprimir.md) · [variáveis e condições](pbl4-variaveis-e-condicoes.md) · [listas e objetos](pbl4-listas-e-objetos.md) · [incluir e menu](pbl4-incluir-e-menu.md) · [migrando do PBL3](pbl4-migrando-do-pbl3.md) |
| [Contribuidor](contribuidor.md) | arquitetura, decisões, releases, participação | [Como participar](como-participar.md) · [Estado do projeto](estado-do-projeto.md) · [Arquitetura, decisões e releases](arquitetura-e-releases.md) · [O que é o Publicare](o-que-e.md) · [Perguntas frequentes](perguntas-frequentes.md) · [Glossário](glossario.md) |

O índice de cada trilha **é gerado**: o gerador junta, no fim da página de entrada, as páginas que declaram aquele público no `publico:` do front matter. Esta tabela é só um mapa para quem lê o repositório; um teste confere que toda página está aqui.

## Por capítulo

Os capítulos agrupam as páginas por assunto e dão o endereço de cada uma (`/<capítulo>/<página>/`). A lista fica em [capitulos.md](capitulos.md).

## Como escrever um guia

- Todo guia começa com **front matter** (`titulo`, `resumo`, `capitulo`, `ordem`, `publico`) e o capítulo precisa existir em [capitulos.md](capitulos.md). `publico` é uma lista, separada por vírgula, de `editor`, `administrador`, `desenvolvedor` e `contribuidor`; nenhuma página fica sem público.
- Escreva **por tarefa**, em linguagem simples, com os rótulos exatos da tela.
- **Nada que envelheça:** sem datas, sem números de versão e sem requisitos exatos; para isso, aponte para o instalador e para o CHANGELOG.
- **Imagens só por captura automática:** `![descrição](captura:id)`, com o `id` declarado em [capturas.json](capturas.json). Nunca arquivo de imagem fixo.
- Links entre guias são relativos (`recuperar-acesso.md#ancora`).
- Detalhes do formato e dos testes: [manual.md](../tecnica/manual.md).

## Usar o painel no celular

O painel (`/_pbl/`) funciona no celular sem JavaScript: a barra de cima mostra Início, Tipos de conteúdo, Contas e Atualização (a seção em que você está fica destacada) e o trilho "Início > ..." logo abaixo leva de volta. No Início, cada site tem seus botões (Conteúdo, Redirecionamentos, Peles, Domínios). Nos formulários, todo campo tem rótulo visível; botões de salvar ocupam a largura da tela. Se a barra ainda aparecer cortada depois de uma atualização, recarregue a página (o navegador pode estar com o CSS antigo guardado).

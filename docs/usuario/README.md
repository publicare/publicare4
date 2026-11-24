# Publicare para quem usa

Este é o **manual de uso** do Publicare 4: para quem quer criar e cuidar de um site (ou de um pequeno sistema na internet) sem precisar programar. Quem desenvolve vai para a [documentação técnica](../tecnica/README.md).

**Fonte única:** estes arquivos são a única fonte do manual. A cada release, o comando `bin/pbl manual-gerar` converte esta pasta no manual publicado (veja [como o manual é gerado](../tecnica/manual.md)). Para mudar o manual, edite o arquivo daqui; quem muda uma tela do painel muda também o guia dela. O inventário do que existe no produto contra o que o manual cobre está em [INVENTARIO.md](INVENTARIO.md).

## Por capítulo

| Capítulo | Guias |
|---|---|
| **Começar** | [O que é o Publicare](o-que-e.md) · [Como instalar](instalar.md) · [Primeiros passos](primeiros-passos.md) · [Usar o painel](painel.md) |
| **Escrever e publicar** | [Escrever e publicar](escrever-e-publicar.md) · [Revisão e histórico](revisao-e-historico.md) · [Tipos de conteúdo](tipos-de-conteudo.md) · [Arquivos e imagens](arquivos-e-imagens.md) · [Busca](busca.md) · [Barra de edição](barra-de-edicao.md) |
| **O seu site** | [Peles e moldes](peles-e-moldes.md) · [Domínios e vários sites](dominios-e-varios-sites.md) · [Modelos e demo](modelos-e-demo.md) · [E-mail do site](email-do-site.md) |
| **Pessoas e acesso** | [Usuários e permissões](usuarios-e-permissoes.md) · [Recuperar o acesso](recuperar-acesso.md) · [Privacidade](privacidade.md) |
| **Manter o site** | [Cópias de segurança](copias-de-seguranca.md) · [Atualizar e voltar atrás](atualizar.md) · [Tarefas agendadas](tarefas-agendadas.md) · [Solução de problemas](solucao-de-problemas.md) |
| **Consulta** | [Acessibilidade](acessibilidade.md) · [Agentes e scripts](agentes-e-scripts.md) · [Perguntas frequentes](perguntas-frequentes.md) · [Glossário](glossario.md) |

Fora do manual (texto para quem contribui): [Como participar](como-participar.md) e [Estado do projeto](estado-do-projeto.md).

## Como escrever um guia

- Todo guia começa com **front matter** (`titulo`, `resumo`, `capitulo`, `ordem`) e o capítulo precisa existir em [capitulos.md](capitulos.md).
- Escreva **por tarefa**, em linguagem simples, com os rótulos exatos da tela.
- **Nada que envelheça:** sem datas, sem números de versão e sem requisitos exatos; para isso, aponte para o instalador e para o CHANGELOG.
- **Imagens só por captura automática:** `![descrição](captura:id)`, com o `id` declarado em [capturas.json](capturas.json). Nunca arquivo de imagem fixo.
- Links entre guias são relativos (`recuperar-acesso.md#ancora`).
- Detalhes do formato e dos testes: [manual.md](../tecnica/manual.md).

## Usar o painel no celular

O painel (`/_pbl/`) funciona no celular sem JavaScript: a barra de cima mostra Início, Tipos de conteúdo, Contas e Atualização (a seção em que você está fica destacada) e o trilho "Início > ..." logo abaixo leva de volta. No Início, cada site tem seus botões (Conteúdo, Redirecionamentos, Peles, Domínios). Nos formulários, todo campo tem rótulo visível; botões de salvar ocupam a largura da tela. Se a barra ainda aparecer cortada depois de uma atualização, recarregue a página (o navegador pode estar com o CSS antigo guardado).

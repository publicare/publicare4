---
titulo: Busca do site
resumo: Como funciona a busca para os visitantes, como ligar ou desligar nas configurações, o que entra na busca e como ativar as sugestões de grafia.
capitulo: conteudo
ordem: 50
publico: editor, administrador, desenvolvedor
---

# Busca do site

O PBL4 traz uma busca para os visitantes do site. Ela fica no endereço `/busca` e não depende de nenhum serviço de fora: tudo roda no seu próprio servidor.

![Configurações do site, em abas](captura:painel-configuracoes)

## Como o visitante usa

1. O visitante abre `/busca` no seu site.
2. Digita uma ou mais palavras e envia. O endereço fica como `/busca?q=palavra`.
3. Vê a lista de resultados, com o mais relevante primeiro e paginação quando há muitos.

A página `/busca` é gerada na hora, a cada pedido, e não fica guardada como as outras páginas do site. Por isso ela mostra sempre o conteúdo mais recente. Quem monta o visual é o modelo de página **busca** da pele em uso; uma pele sem esse modelo não tem busca (veja [Peles e modelos](peles-e-moldes.md)).

## O que a busca entende

- Não diferencia maiúsculas de minúsculas nem acentos: "educacao" encontra "Educação".
- Procura por palavras, e o plural e o singular casam entre si.
- Ignora palavras muito comuns ("de", "para", "com") e palavras de uma letra só.
- Usa até 8 palavras da consulta, e só os primeiros 200 caracteres do que foi digitado.
- Título, descrição e texto pesam diferente: uma palavra no título sobe o conteúdo na lista mais do que uma palavra no meio do texto, e título igual à consulta fica no topo.

## O que entra na busca

Entram o **título**, a **descrição** e o conteúdo dos campos de **texto** e de **texto formatado** de cada tipo, sem as marcações de formatação. O índice é atualizado a cada vez que um conteúdo é criado ou alterado.

Só aparece para o visitante o que está no ar:

- O conteúdo precisa estar com o estado **Publicado**.
- A data de publicação não pode estar no futuro, e a validade não pode ter vencido.
- Conteúdo privado, enviado para revisão, devolvido ou na lixeira nunca aparece.

O texto dentro de PDFs e de outros arquivos enviados não é lido. Para um arquivo ser achado, escreva um bom título e uma descrição ao criá-lo (veja [Arquivos e imagens](arquivos-e-imagens.md)).

Para tirar uma página dos buscadores externos, como o Google, use o interruptor **Aparecer bem nas buscas e nas redes sociais** ao editar e marque a opção de não indexar. Isso não muda a busca do próprio site.

## Ligar ou desligar a busca

1. Abra **Conteúdo**. Em cada site há o recolhível **Mais ações do site**; abra-o e clique em **Configurações**. Só quem administra o site vê esse item.
2. Abra a aba **Comportamento**.
3. Marque ou desmarque **Busca ligada** e salve.

A busca já vem ligada. Desligada, o endereço `/busca` responde "não encontrado", como se não existisse. As configurações voltam a valer ao salvar, e o site é republicado.

## Sugestões de grafia

Quando a busca não acha nada, ela pode sugerir "você quis dizer...", com uma grafia parecida. As sugestões usam só palavras de conteúdo publicado, nunca de conteúdo privado.

Esse recurso vem **desligado**, porque deixa a busca um pouco mais lenta. Para ligar:

1. Abra o endereço `/_pbl/site/1/busca` (troque o 1 pelo número do seu site, se houver mais de um).
2. Marque **Sugerir grafia parecida quando a busca não encontra nada**.
3. Clique em **Salvar**.

Essa tela não aparece nos menus do painel; ela é aberta pelo endereço. A sugestão só é mostrada se o modelo de página **busca** da pele a exibir.

## Se a busca não acha o que deveria

- Confira se o conteúdo está **Publicado** e dentro das datas.
- Confira se **Busca ligada** está marcada.
- Veja se a palavra está no título, na descrição ou em um campo de texto, e não só dentro de um arquivo.
- Se a pele em uso não tem o modelo **busca**, `/busca` responde "não encontrado" mesmo com a busca ligada.

## Veja também

- [Escrever e publicar conteúdo](escrever-e-publicar.md)
- [Peles e modelos](peles-e-moldes.md)
- [Solução de problemas](solucao-de-problemas.md)
- [Usar o painel](painel.md)

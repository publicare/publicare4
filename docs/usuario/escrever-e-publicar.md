---
titulo: Escrever e publicar conteúdo
resumo: Como criar, editar, publicar, tirar do ar, agendar, mover e apagar conteúdo, e o que fazer com a lixeira e os conteúdos vencidos.
capitulo: conteudo
ordem: 10
publico: editor
---

# Escrever e publicar conteúdo

Tudo o que existe no site (páginas, notícias, pastas, imagens, arquivos, links) é um **conteúdo**. Este guia mostra o dia a dia: criar, editar, publicar e organizar.

![Lista de conteúdo do site, com ícone, título, estado e botões de cada item](captura:painel-conteudo)

## Criar um conteúdo

1. Abra **Conteúdo** e entre na pasta onde ele deve ficar.
2. No conteúdo onde quer criar, abra **Mais ações** e escolha **Criar conteúdo aqui dentro**.
3. Escolha o tipo e clique em **Continuar**. Só aparecem os tipos que aquele lugar aceita (veja [Tipos de conteúdo](tipos-de-conteudo.md)).
4. Preencha o **Título** e os campos e clique em **Criar**.

Todo conteúdo novo nasce **fora do ar** (privado).

## Editar e salvar

1. Abra o conteúdo e clique em **Editar**.
2. A tela tem três abas. **Conteúdo** traz título, descrição e os campos do tipo. **Publicação** traz estado, datas, endereço, tags e menu. **Avançado** traz a posição nas listas e a aparência própria.
3. O que é opcional fica atrás de um interruptor, como **Agendar a publicação e a validade**. Desligado, vale o padrão do site.
4. Clique em **Salvar**, na barra do rodapé. Quem pode publicar também vê **Salvar e publicar**.

Se outra pessoa alterou o mesmo conteúdo enquanto você editava, o painel não grava por cima: mostra o que está salvo e deixa as suas escolhas no formulário para você decidir.

## Publicar e tirar do ar

1. Na página do conteúdo, clique em **Publicar**. Se ele já estiver no ar, o botão se chama **Tirar do ar**.
2. Em **Estado**, escolha uma destas opções: **Privado (fora do ar)**, **Enviado para revisão (fora do ar)**, **Devolvido ao autor (fora do ar)** ou **Publicado**.
3. Clique em **Salvar**.

Só o estado **Publicado**, dentro das datas, aparece no site. Na lista de conteúdo, cada item também tem o botão **Publicar** ou **Tirar do ar**. Para vários itens de uma vez, marque-os e use **Publicar** ou **Despublicar** na ação em lote.

Quem tem papel de autor não publica: envia para revisão (veja [Revisão e histórico de versões](revisao-e-historico.md)).

## Agendar a publicação e a validade

1. Abra **Editar** e vá à aba **Publicação**.
2. Ligue o interruptor **Agendar a publicação e a validade**.
3. Preencha **Publicar a partir de** (vazio significa assim que publicado) e **Tirar do ar a partir de** (vazio significa sem prazo). O horário é o de Brasília.
4. Deixe o **Estado** em **Publicado** e clique em **Salvar**.

A data de saída precisa ser depois da data de publicação. A lista mostra o item como **Agendado** até a hora chegar. Quem faz a troca na hora certa é o cron do servidor, o comando `bin/pbl agendados`; sem o cron ligado, nada agendado acontece. Confira em **Mais**, **Tarefas agendadas** e veja [Tarefas agendadas](tarefas-agendadas.md).

## Conteúdos vencidos

Quando a data de validade passa, o conteúdo sai do ar sozinho. A tela **Conteúdos vencidos** (endereço `/_pbl/vencidos`) lista o que venceu, com a data de **Venceu em**. Cada item tem **Editar** e **Prorrogar ou despublicar**. Na lista de Conteúdo, o filtro de estado também tem a opção **Expirado**.

## Mover

1. Abra o conteúdo, vá em **Mais ações** e escolha **Mover**.
2. Navegue até o destino e clique em **Mover para cá**.

Tudo o que está dentro vai junto e o endereço não muda. O painel recusa mover para dentro de si mesmo ou para um tipo que não aceita aquele conteúdo. A página inicial não pode ser movida.

Para copiar, use **Copiar para a pilha** (ou **Recortar para a pilha**) em **Mais ações** e, no destino, **Colar aqui**.

## Apagar e a lixeira

1. Na lista ou na página do conteúdo, escolha **Apagar**.
2. Confira a tela e clique em **Mandar para a lixeira**.

O conteúdo sai do ar na hora, mas nada é apagado de vez. A página inicial não pode ir para a lixeira, e um conteúdo que tem outros dentro precisa ser esvaziado antes.

Para ver a lixeira, abra **Conteúdo** e filtre o estado por **Na lixeira**, ou use a tela **Lixeira** (`/_pbl/lixeira`).

- **Tirar da lixeira** devolve o conteúdo fora do ar (privado), com o histórico inteiro.
- **Apagar de vez** remove o item para sempre, junto com versões, endereços antigos e tags. **Não dá para desfazer.**
- **Esvaziar a lixeira** apaga de vez tudo o que você pode apagar, depois de uma tela de confirmação.

## Veja também

- [Revisão e histórico de versões](revisao-e-historico.md)
- [Tipos de conteúdo](tipos-de-conteudo.md)
- [Arquivos e imagens](arquivos-e-imagens.md)
- [Usar o painel](painel.md)

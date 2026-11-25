---
titulo: Acessibilidade
resumo: O que o painel e as peles do Publicare já fazem por quem usa teclado, leitor de tela ou tela pequena, e o que cabe a quem escreve o conteúdo.
capitulo: consulta
ordem: 10
publico: editor, administrador, desenvolvedor
---

# Acessibilidade

Um site acessível pode ser usado por todas as pessoas: quem usa só o teclado, quem ouve a página com um leitor de tela, quem enxerga pouco, quem tem a conexão lenta ou o JavaScript desligado. A acessibilidade vem de duas partes: o que o produto entrega e o que você escreve.

> O projeto usa as diretrizes WCAG, nível AA, como meta de qualidade. Não há laudo nem certificação de conformidade: a meta orienta o trabalho, mas não é uma garantia. Se o seu site tem obrigação legal de acessibilidade, peça uma avaliação própria depois de montar o conteúdo.

## O que o painel entrega

- **Link para pular ao conteúdo.** Ao apertar a tecla Tab logo no começo de uma tela do painel, aparece "Pular para o conteúdo", que leva direto ao título da página, sem passar pelo menu.
- **Navegação por teclado.** Os botões, links, abas e campos são elementos comuns do navegador, alcançáveis com Tab e acionados com Enter ou Espaço. O foco aparece destacado. O botão **?** ao lado de um rótulo abre a ajuda com Enter ou Espaço, e a tecla Esc fecha.
- **Rótulos nos campos.** Cada campo tem um rótulo ligado a ele. A ajuda e as mensagens de erro são ligadas ao campo, para que o leitor de tela as leia junto.
- **Erros e avisos anunciados.** Erros e confirmações têm papel próprio para leitores de tela (alerta e status), e o erro vem com a causa e o que fazer.
- **Tabelas com legenda.** As tabelas do painel têm descrição e cabeçalhos de coluna. Em telas estreitas, as linhas viram cartões.
- **Abas como links.** As abas (Código, Histórico, Pré-visualizar; Dados, Papéis e acessos, Segurança) são links comuns, e a aba atual é marcada para o leitor de tela.
- **Funciona sem JavaScript.** Os fluxos do painel terminam sem JavaScript. Ele só melhora a experiência (por exemplo, barras de progresso e o aviso de alterações não salvas).
- **Idioma e esquema de cores.** As páginas declaram o português do Brasil. O painel acompanha o modo claro ou escuro do aparelho.
- **Telas pequenas.** O painel funciona no celular, com o menu atrás do botão **Menu** e alvos de toque grandes.

## O que as peles entregam

As peles são a aparência do seu site público (veja [Peles e modelos de página](peles-e-moldes.md)).

- A pele `basica` declara o idioma do site em cada página, funciona sem JavaScript, respeita a preferência por movimento reduzido e acompanha o modo claro ou escuro. O estilo dela foi feito para manter o contraste entre texto e fundo dentro da meta AA.
- O menu do celular tem nome acessível ("Abrir o menu") e informa se está aberto ou fechado. A paginação das listas é identificada como navegação.
- As peles `portal` e `onepage` usam um pouco de JavaScript para o menu e o carrossel de destaques.
- As peles incluídas não trazem um link para pular o menu. Se isso for importante para o seu público, peça a um desenvolvedor para acrescentá-lo no modelo de página.
- As imagens de destaque que as peles mostram em listas, notícias e faixas de abertura são tratadas como decorativas, sem texto alternativo. Por isso, **nunca deixe uma informação importante só dentro dessa imagem**: escreva-a também no texto.

Se você trocar as cores da pele ou fizer uma pele própria, confira o contraste de novo.

## O que quem escreve deve fazer

1. **Texto alternativo nas imagens.** Ao enviar uma imagem, preencha o **Texto alternativo (para leitores de tela)** com uma frase curta que diga o que a imagem mostra ou comunica. Se ela é só enfeite, deixe em branco. Veja [Arquivos e imagens](arquivos-e-imagens.md).
2. **Títulos em ordem.** O título da página já é o título principal. Dentro do texto, use os estilos de título do editor, se houver, em sequência (do mais importante para o menos), sem pular níveis e sem usar negrito para simular título.
3. **Links com texto claro.** Escreva "Baixe o relatório de atividades", e não "clique aqui" nem o endereço comprido. O texto do link deve fazer sentido sozinho.
4. **Tabelas com cabeçalho.** Use tabela só para dados, e, se o editor oferecer a opção, marque a primeira linha como cabeçalho. Não use tabela para organizar o desenho da página.
5. **Não dependa só de cor.** Em vez de "os itens em vermelho", escreva "os itens marcados com asterisco".
6. **Texto simples.** Frases curtas, parágrafos curtos, listas de verdade (com marcadores ou números) em vez de linhas separadas à mão.
7. **Arquivos para baixar.** Diga no texto do link o tipo e o assunto, por exemplo "Edital completo (PDF)".
8. **Vídeos e áudios.** Quando existirem, ofereça uma transcrição ou legenda. O Publicare não gera isso sozinho.

## Dicas para quem usa leitor de tela no painel

- Cada tela tem um título principal e títulos de seção em ordem; use a navegação por títulos do seu leitor para ir direto à parte que interessa.
- Os botões de ação em tabelas têm nome que inclui o item (por exemplo, o nome do domínio ou do modelo), para você saber a qual linha o botão pertence.
- As mensagens de sucesso e de erro aparecem no alto da tela e são anunciadas; as de erro dizem a causa e o que fazer.
- Ações delicadas, como trocar o domínio principal ou apagar um modelo, pedem a senha de novo. O campo de senha tem rótulo, como os demais.

## Lista de conferência antes de publicar

1. Todas as imagens com informação têm texto alternativo.
2. Os títulos seguem uma ordem lógica.
3. Os links dizem para onde levam.
4. As tabelas têm linha de cabeçalho e não passam de dados.
5. Nada importante depende só de cor, de imagem ou de som.
6. O texto está claro para quem lê pela primeira vez.

## Como conferir

- Navegue pelo site só com o teclado: dá para chegar a tudo e ver onde está o foco?
- Aumente o zoom do navegador para o dobro: o texto continua legível e sem sumir?
- Desligue as imagens ou o JavaScript: o conteúdo essencial continua lá?
- Se puder, ouça uma página com o leitor de tela do seu sistema.

## Veja também

- [Arquivos e imagens](arquivos-e-imagens.md)
- [Escrever e publicar](escrever-e-publicar.md)
- [Peles e modelos de página](peles-e-moldes.md)
- [Usar o painel](painel.md)

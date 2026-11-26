---
titulo: Peles e modelos de página
resumo: Troque a aparência do site com uma pele, veja e edite os modelos de página e volte a uma versão anterior quando algo der errado.
capitulo: site
ordem: 10
publico: administrador, desenvolvedor
---

# Peles e modelos de página

A **pele** é a aparência do site: cores, tipo de letra, menu, rodapé. Cada pele é feita de vários **modelos de página** (no código aparecem como "moldes", arquivos que terminam em `.pbl`). Um modelo diz como um tipo de página é desenhado: um para a página comum, outro para notícia, outro para a lista de uma pasta, e assim por diante.

Trocar de pele muda o visual do site inteiro sem mexer em nenhum texto seu. Só administradores veem essas telas.

![Tela Peles e modelos de página do site, com a lista de peles e a situação de cada uma](captura:painel-peles)

## Peles que vêm com o produto

O Publicare traz três peles prontas:

| Pele | Para quê |
|---|---|
| `basica` | A pele padrão: site comum, com notícias, agenda, perguntas frequentes e busca. É a mais completa. |
| `portal` | Visual de portal: a página inicial tem faixa com carrossel de destaques, as últimas notícias e o conteúdo; as demais são páginas internas. |
| `onepage` | Site de uma página só: o menu leva às seções da mesma página. |

Nenhuma carrega arquivos de servidores de terceiros. A `basica` funciona sem JavaScript; `portal` e `onepage` usam um pouco para o menu e o carrossel.

## Trocar de pele

1. No menu, abra **Estrutura** e clique em **Peles e modelos de página**.
2. A tabela mostra cada pele, quantos modelos ela tem e qual está ativa (selo **Ativa neste site**). Os botões da linha são ícones: passe o mouse para ver o nome.
3. Na linha da pele que você quer, digite a sua senha e clique no botão **Usar neste site**.
4. A tela volta avisando que a pele foi ativada. O site é republicado em seguida; em sites grandes isso leva alguns instantes e você pode acompanhar em **Menu e publicação**.

Uma pele só pode ser ativada se tiver o modelo `padrao`, que todo site precisa. Se faltar, a tela recusa e explica.

## Ver os modelos de uma pele

1. Na lista de peles, clique em **Ver arquivos** na linha da pele.
2. Cada linha é um modelo (por exemplo `noticia.pbl`). Se existe um rascunho não publicado, aparece o selo **rascunho**.
3. Os botões de cada linha são **Editar**, **Histórico** e **Apagar**.
4. Para criar um modelo novo, clique em **Novo modelo de página** e dê um nome com letras minúsculas sem acento, números e `_`.

> Importante: editar um modelo muda todos os sites que usam aquela pele.

## Editar um modelo

A tela do modelo tem três abas: **Código**, **Histórico** e **Pré-visualizar**.

1. Na aba **Código**, altere o texto na caixa grande.
2. Digite a sua senha e clique em **Validar e salvar**. O sistema confere o modelo antes de gravar; se houver erro, mostra a mensagem, o número da linha e o trecho com problema, e nada é salvo.
3. Para guardar sem publicar, clique em **Salvar rascunho**. O site continua como estava. Depois use **Publicar rascunho** ou **Descartar rascunho**.
4. Se outra pessoa alterou o mesmo modelo enquanto você editava, o sistema não grava e avisa. O seu texto fica na tela para você copiar.

Ao salvar, o site é republicado com o modelo novo. Escrever modelos é trabalho para quem conhece HTML; para trocar de pele você não precisa disso.

### Pré-visualizar antes de publicar

1. Abra a aba **Pré-visualizar**.
2. Escolha uma página publicada do site que use esse modelo e clique em **Pré-visualizar**.
3. A página aparece com o rascunho aplicado. Nada é publicado e a prévia não entra em buscadores.

Se não houver nenhuma página publicada com aquele modelo, a tela avisa e não há o que mostrar.

## Voltar a uma versão do modelo

Cada vez que um modelo é criado, salvo ou apagado, a versão anterior fica guardada. O sistema guarda um número limitado de versões por modelo; as mais antigas somem quando o limite passa.

1. Abra o modelo e clique na aba **Histórico**.
2. A tabela mostra versão, data, quem fez e o que foi feito. Clique em **Ver** para ler uma versão com o texto colorido (somente leitura).
3. Para comparar duas versões, escolha **De** e **Para** e clique em **Ver diferença**.
4. Na versão desejada, digite a sua senha e clique em **Voltar a esta versão**. Ela é gravada como uma versão nova, depois de passar pela mesma conferência de uma edição comum. A versão que já está em uso mostra o selo **atual**.

## Apagar um modelo

Na lista de arquivos, clique em **Apagar**. Não dá para apagar o `padrao` nem um modelo citado por outro; a tela diz quais o citam. O histórico guarda a versão apagada.

## Quando chamar um desenvolvedor

Peça ajuda a quem entende de HTML e CSS quando você quiser:

- uma pele nova ou o visual de outro site refeito do zero (as peles ficam na pasta `dados/peles/` do servidor);
- mudar cores, fontes ou o desenho do menu (o estilo fica no arquivo `pele.css` da pele);
- ajustar um modelo que a validação recusa e a mensagem não ajuda.

Para trocar texto, imagens e menus do dia a dia, você não precisa mexer em modelos.

## Veja também

- [Usar o painel](painel.md)
- [Modelos opcionais e demonstração](modelos-e-demo.md)
- [Escrever e publicar](escrever-e-publicar.md)
- [Solução de problemas](solucao-de-problemas.md)

---
titulo: Modelos opcionais e site de demonstração
resumo: Modelos opcionais (agenda, perguntas frequentes, destaques) e o site de demonstração.
capitulo: site
ordem: 30
publico: administrador, desenvolvedor
---

# Modelos opcionais e site de demonstração

Alguns tipos de conteúdo são comuns a quase todo site, mas nem todo site precisa deles. Por isso eles vêm como **modelos opcionais**, que você liga quando quiser.

| Modelo | O que dá | Onde aparece |
|---|---|---|
| **Agenda de eventos** (`evento`) | Eventos com data e hora de início e fim, local, imagem e link de inscrição | Página "Agenda", com *Próximos eventos* e *Eventos passados* |
| **Perguntas frequentes** (`faq`) | A pergunta é o título; a resposta é texto formatado; categoria opcional | Página "Perguntas frequentes", em sanfona, agrupada por categoria |
| **Destaques da página inicial** (`destaque`) | Imagem, título, texto curto e link (interno ou externo) | Faixa com até 3 destaques no alto da página inicial |
| **Blocos reutilizáveis** (`bloco`) | Trechos de HTML usados em várias páginas | O bloco `bloco-rodape` vira o rodapé; o `bloco-aviso`, uma faixa de aviso no topo |

Ligar um modelo cria os tipos de conteúdo, alguns exemplos (que você edita ou apaga) e os moldes da pele básica. Ligar de novo não duplica nada nem desfaz o que você mudou.

## Como ligar

- **Na instalação**: no instalador do navegador, marque as caixas; na linha de comando, `bin/pbl instalar --modelos=evento,faq` (ou `--modelos=todos`).
- **Pelo painel** (só administradores): menu *Tipos de conteúdo* > *Modelos* (`/_pbl/modelos`), botão **Ativar**. Serve também para instalações antigas, que não ganham tipos novos sozinhas. Depois de ativar, republique o site.
- **Pela linha de comando**: `bin/pbl modelos listar` e `bin/pbl modelos ativar evento --site=1`.

Se o seu site usa uma pele que não é a básica, copie para ela os moldes de `app/recursos/peles/basica/` (`agenda`, `evento`, `perguntas`, `faq`, `destaque`, `bloco`, `faixa_destaques`, `bloco_aviso`, `bloco_rodape`) e inclua `faixa_destaques` e `bloco_aviso` onde quiser. Se você personalizou os moldes `topo`, `padrao`, `item` ou `estilo` da pele básica, o PBL4 não os sobrescreve: ele avisa e você copia os trechos novos à mão.

## Dicas

- **Eventos**: "Início" e "Fim" usam `aaaa-mm-dd hh:mm`. A divisão entre próximos e passados é feita quando o site é publicado; ao passar a data, a página muda na próxima publicação.
- **Perguntas**: a ordem vem do campo de ordem (peso) do conteúdo; use a mesma grafia na categoria para agrupar.
- **Destaques**: preencha o destino interno **ou** o externo, não os dois.

## Site de demonstração

> A demonstração é **só para desenvolvimento e testes**: cria contas com senha pública. Para o site oficial do Publicare use `publicare-oficial-carregar` ([procedimento do operador](../tecnica/site-oficial.md)).

Para ver tudo funcionando (e para testar o visual), o PBL4 traz um site de exemplo que **apresenta o próprio Publicare 4**, escrito com o programa:

```bash
make pbl ARGS="instalar --nome='Publicare 4'"
make pbl ARGS="demo-popular"                 # ativa os 4 modelos, cria o conteúdo e publica
make pbl ARGS="demo-popular --site=2"        # outro site
```

Ele cria a página inicial (promessa, quatro pilares e chamadas), as páginas **O que é**, **O que faz**, **Como facilita**, **O que inova**, **Instalação**, **Comunidade** e **Como acompanhar o projeto**, as perguntas frequentes (modelo `faq`), a faixa de destaques (modelo `destaque`), blocos de rodapé e aviso (modelo `bloco`), guias atemporais no tipo `noticia`, sem data (conceitos como a revisão e a pele; mais um rascunho), documentos e imagens com texto alternativo. Cada afirmação sobre o produto vem de um documento do repositório, e um teste confere que o recurso citado existe. Também cria as contas `autor` e `editor` (e-mails `@exemplo.invalid`, fictícios; senha de demonstração fixa, mostrada no fim do comando e **só para demo**: nunca use em site de verdade), cada uma com a seção Comunidade, e dois envios de exemplo: um **em revisão** e um **devolvido com motivo**, para testar o fluxo de revisão. Pode ser repetido sem duplicar nada. **Só para desenvolvimento e demonstração**: se o site já tiver mais de 30 conteúdos, o comando recusa; `--forcar` ignora a trava, e `--sem-publicar` só cria o conteúdo.

### Instalação limpa x demonstração completa

- **`bin/pbl instalar`** continua **leve**: usa a pele `publicare` (a pele padrão dos sites novos), cria uma página inicial curta com a promessa do Publicare e os pilares, mais "Sobre", "Notícias" e "Contato", e **não liga** nenhum modelo opcional.
- **`bin/pbl demo-popular`** faz a apresentação completa descrita acima, sobre a pele `publicare`. A prévia (`previa/gerar-pacote.php`) usa a demonstração completa.
- A pele `basica` não mudou e continua instalada. Sites que já a usam seguem com ela; só os sites novos começam na `publicare`. Os moldes das duas têm os mesmos nomes e campos, então dá para trocar de pele sem perder conteúdo.

## Peles por conteúdo e as duas versões do demo

Na edição de um conteúdo (cartão **Publicação**, só para quem pode publicar) há os campos **Pele** e **Aparência (molde)**. "Herdar" (o padrão) usa a do conteúdo-pai ou, no topo, a do site; escolhendo uma, tudo abaixo dele passa a usá-la e as páginas são republicadas. O molde, se existir na pele efetiva, vale para aquele conteúdo; a página inicial do site usa o molde `home` quando a pele o tem.

Na página inicial da pele básica, os **links publicados direto sob a página inicial** viram cartões grandes logo abaixo da apresentação (o bloco só aparece se houver algum); os demais links ficam em "Links rápidos". No demo, eles são os atalhos úteis da página inicial (atualizar, documentação, linguagem de moldes, segurança). As cores e medidas da pele básica ficam em variáveis no começo de `assets/pele.css` (da pele, em `dados/peles/basica/`).

O `demo-popular` cria **um site, uma árvore de objetos**: não há versões paralelas do conteúdo. Trocar a pele do site (`publicare`, `basica`, `onepage`, `portal`) não cria nem apaga objetos, e um teste confere isso. A pele `onepage` com o mesmo conteúdo da árvore virá depois. As peles `basica`, `onepage` e `portal` usam só JavaScript do próprio site (o do Bootstrap, hospedado aqui, mais um arquivo pequeno de cada pele), como melhoria; a `publicare` não usa JavaScript.

Na pele `publicare`, a página inicial mostra só os destaques da pasta `destaques` e só os guias da pasta `noticias` (seção "Conceitos e guias"), então outros destaques e notícias do site não vazam para a home. Os guias do exemplo não têm imagem (a galeria mostra o envio de imagens com texto alternativo).

**Regra: o exemplo não tem dado que envelheça.** Nada de notícia datada, número de versão, ano, contagem de recursos ou estado transitório ("em construção", "ainda não existe"). O que muda aponta para o endereço permanente: a página **Como acompanhar o projeto** leva às releases e ao CHANGELOG no GitHub, e os requisitos do servidor ficam no guia de instalação e no assistente, que são a fonte única. Por isso o demo não cria agenda de eventos (todo evento tem data); o modelo `evento` continua disponível pelos modelos opcionais.

## Pele oficial: SEO, compartilhamento e logotipo

A `publicare` é a pele oficial distribuída com o instalador. Desde a instalação (limpa ou com demonstração) cada página sai com `<title>`, `description`, `canonical`, `og:*` (incluindo `og:site_name`, `og:locale` e imagem com texto alternativo), `twitter:*` (cartão grande), JSON-LD básico (`WebPage`, ou `Article` para notícias), favicon SVG, `robots.txt` e `sitemap.xml` (estes dois, da plataforma). Os valores vêm da configuração do site e do conteúdo; os campos `seo_titulo`, `seo_descricao` e `seo_imagem` de cada conteúdo, e `seo_nao_indexar`, têm prioridade. Sem imagem própria, o compartilhamento usa `assets/og-publicare.png` (1200x630, original do projeto). O logotipo (`assets/marca.svg`) é um P em forma de peça de quebra-cabeça, em laranja; sites com logotipo próprio nas configurações usam o deles. Limite conhecido: o JSON-LD usa o texto já escapado para HTML; um título com barra invertida pode deixar o bloco inválido (buscadores o ignoram).

## Comandos da demonstração

- `bin/pbl demo-popular`: cria o conteúdo de demonstração (veja acima).
- `bin/pbl demo-resetar`: só em instalação de demonstração; recria conteúdo, contas e arquivos de exemplo, depois de guardar uma cópia.
- `bin/pbl demo-senha`: só em instalação de demonstração e só no terminal; mostra a senha atual do administrador da demo.

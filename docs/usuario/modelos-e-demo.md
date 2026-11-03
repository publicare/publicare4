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

Para ver tudo funcionando (e para testar o visual), o PBL4 traz um site fictício, o **Instituto Exemplo**:

```bash
make pbl ARGS="instalar --nome='Instituto Exemplo'"
make pbl ARGS="demo-popular"                 # ativa os 4 modelos, cria o conteúdo e publica
make pbl ARGS="demo-popular --site=2"        # outro site
```

Ele cria página inicial com texto formatado, 3 destaques, a seção Institucional (com subpáginas), 8 notícias (uma agendada para o futuro e um rascunho), agenda, perguntas frequentes, documentos, galeria de fotos e links úteis. Pode ser repetido sem duplicar nada. **Só para desenvolvimento e demonstração**: se o site já tiver mais de 30 conteúdos, o comando recusa; `--forcar` ignora a trava, e `--sem-publicar` só cria o conteúdo.

## Peles por conteúdo e as duas versões do demo

Na edição de um conteúdo (cartão **Publicação**, só para quem pode publicar) há os campos **Pele** e **Aparência (molde)**. "Herdar" (o padrão) usa a do conteúdo-pai ou, no topo, a do site; escolhendo uma, tudo abaixo dele passa a usá-la e as páginas são republicadas. O molde, se existir na pele efetiva, vale para aquele conteúdo; a página inicial do site usa o molde `home` quando a pele o tem.

O `demo-popular` cria duas versões do mesmo site, no ar ao mesmo tempo: **/onepage** (pele `onepage`: uma página com seções vindas dos filhos) e **/portal** (pele `portal`: várias páginas, carrossel e notícias). O endereço de cada conteúdo é único no site, por isso os do demo levam prefixo (`op-`, `pt-`). A pele básica continua sem JavaScript; `onepage` e `portal` usam o do Bootstrap, hospedado no próprio site.

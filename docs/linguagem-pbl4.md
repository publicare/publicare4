# Linguagem PBL4 (v1)

Referência da linguagem de moldes. O PBL4 mantém o espírito do PBL3 (tags `<@…@>`, vocabulário em português, `#campo`, `$variavel`, `%MACRO`) com gramática formal, escape por padrão e execução em sandbox. Design: [fluxo 2](fluxos/02-compilacao-pbl.md).

## Princípios

- **Três estágios**: Lexer → Parser (árvore) → Gerador (PHP). O PHP gerado vai para `dados/cache/pbl/<hash>.php` e é reaproveitado; o hash cobre o molde e toda a cadeia de layouts.
- **Nada do molde vira código**: o gerador só emite literais (`var_export`) e chamadas ao `Runtime`. PHP cru (`<?`, `?>`) é rejeitado. O molde não alcança superglobais, arquivos nem funções do PHP.
- **Escape por padrão**: `eco` escapa HTML; `ecocru` é a saída sem escape, escolhida de forma explícita.
- **Erros são exceções com linha** (e arquivo, quando o erro está num layout ou parte incluída). Nada de `exit`.
- **Limites**: `repetir` e `filhos` têm teto de 10.000 iterações (a conta não estoura nos limites dos inteiros); `incluir` aninha no máximo 8 níveis; herança no máximo 5.
- **Campos do sistema vencem**: `#id`, `#site`, `#pai`, `#titulo` etc. vêm do objeto e nunca de uma propriedade da classe com o mesmo nome (classes criadas antes da reserva).

## Gramática (EBNF)

```ebnf
molde      = { texto | tag } ;
tag        = "<@" , comando , "@>" ;         (* termina no primeiro "@>" fora de aspas *)
comando    = "=" , expr
           | "eco" , expr | "ecocru" , expr | "ecourl" , expr
           | "eco_limite" , params | "formatadata" , params
           | "var" , variavel , "=" , expr
           | "se" , condicao | "senao" | "/se"
           | "repetir" , variavel , "=" , "[" , expr , "," , expr , "]" | "/repetir"
           | colecao , [ params ] | "/" , colecao
           | "semfilhos" | "/semfilhos" | "naolocalizado" | "/naolocalizado"
           | "usarobjeto" , params | "/usarobjeto"
           | "temfilho" , [ params ] | "/temfilho"
           | "formulario" , [ params ]
           | "arquivo" , params | "miniatura" , params
           | "estende" , string
           | "bloco" , nome | "/bloco"
           | "incluir" , params ;
colecao    = "filhos" | "aleatorio" | "localizar" | "localizaraleatorio" ;
condicao   = [ "[" ] , expr , [ comparador , expr ] , [ "]" ] ;
comparador = "==" | "=" | "!=" | "<" | ">" | "<=" | ">=" ;
params     = nome , "=" , expr , { nome , "=" , expr } ;
expr       = termo , { operador , termo } ;   (* * e / > + e - > . *)
operador   = "." | "+" | "-" | "*" | "/" ;
termo      = string | numero | campo | variavel | macro | "(" , expr , ")" | "-" , termo ;
campo      = "#" , nome ;      variavel = "$" , nome ;      macro = "%" , NOME ;
string     = '"' , {...} , '"' | "'" , {...} , "'" ;   (* escapes: \\ \" \' *)
```

## Valores e semântica

| Elemento | Significado |
|---|---|
| `#ref.campo` | campo do objeto **apontado** por uma propriedade `ref` (`#autor.titulo`, `#autor.cargo`). Um só nível. Só enxerga objetos do mesmo site, publicados e vigentes; sem autor, vem vazio. O ponto precisa estar colado ao nome: `#a . "x"` e `#a."x"` continuam sendo concatenação. |
| `#campo` | campo do objeto corrente: `#id`, `#titulo`, `#url` (`/` + URL amigável), `#url_amigavel`, `#classe`, `#classe_id`, `#site`, `#pai`, `#peso`, `#descricao`, `#data_publicacao`, `#data_validade`, `#status`, `#apagado`, `#temfilhos` (1 se tem filhos visíveis, senão 0) e os campos da classe (esses nomes são reservados). Dentro de `filhos`, o objeto corrente é o filho. Campo inexistente é vazio. |
| `$variavel` | variável do molde, criada por `var` ou `repetir`; compartilhada com os moldes incluídos; vazia se não definida. Não toca em variáveis do PHP. |
| `%MACRO` | `DIA`, `MES`, `ANO`, `DATA` (dd/mm/aaaa); em laço de coleção: `INDICE` (1..n) e `FIM`/`QUANTIDADE` (total); `SUGESTAO` (ver [Busca](#busca-busca)). Macro desconhecida é erro de sintaxe. |
| `.` | concatenação (texto). `+ - * /` aritmética; texto não numérico vale 0; divisão por zero dá vazio. |
| data | `formatadata` aceita `aaaa-mm-dd[ hh:mm:ss]` e `dd/mm/aaaa[ hh:mm:ss]`; data impossível (`2026-02-31`) dá vazio |
| comparação | se os dois lados são numéricos compara como número, senão como texto (diferencia maiúsculas). |
| verdadeiro | tudo, menos `""`, `"0"`, `0`, vazio e ausente. |

## Comandos

| Comando | Efeito |
|---|---|
| `<@eco expr@>` / `<@= expr@>` | imprime com escape HTML |
| `<@ecocru expr@>` | imprime sem escape (só HTML confiável) |
| `<@ecourl expr@>` | imprime um endereço para `href`: só `http(s)://…` ou caminho iniciado em `/`; qualquer outro esquema (`javascript:`, `data:`, `vbscript:`, com maiúsculas, espaço ou tab) vira vazio |
| `<@eco_limite texto=… [limite=…] [inicio=…]@>` | recorta o texto (caracteres) e escapa |
| `<@formatadata valor=… formato="d/m/Y"@>` | formata data (`aaaa-mm-dd[ hh:mm:ss]` ou `dd/mm/aaaa[ hh:mm:ss]`); inválida dá vazio |
| `<@var $x = expr@>` | define variável |
| `<@se cond@>…<@senao@>…<@/se@>` | condicional |
| `<@repetir $i=[a,b]@>…<@/repetir@>` | laço numérico inclusivo |
| `<@filhos [classes="a,b"] [ordem="peso, -data_publicacao"] [inicio=n] [limite=n] [tag="a"] [expandir="autor"]@>…<@/filhos@>` | laço sobre filhos **publicados e vigentes** (fora de `data_publicacao`/`data_validade` não aparecem) |
| `<@aleatorio …@>…<@/aleatorio@>` | como `filhos`, em ordem aleatória: sorteia entre **todos** os candidatos e só então aplica `inicio`/`limite` (`limite=1` escolhe um item qualquer) |
| `<@localizar [classes=…] [pai=n] [niveis=n] [campo=… op=… valor=…] [campo2=…] [campo3=…] [busca=…] [tag="a,b"] [ordem=…] [inicio=n] [limite=n] [expandir=…]@>…<@/localizar@>` | laço sobre objetos **publicados e vigentes do mesmo site**. `pai` limita à subárvore (sem o próprio pai) e `niveis` à profundidade (`niveis=1` são os filhos diretos). Até **três condições** (todas precisam valer): `campo`/`op`/`valor`, `campo2`/`op2`/`valor2`, `campo3`/`op3`/`valor3`. `campo` é uma coluna do objeto, uma propriedade **indexável** ou `ref.campo` (`autor.cargo`); `op` é `=` (padrão), `!=`, `<`, `>`, `<=`, `>=` ou `contem`. `busca` é a busca de texto por palavras (ver [Busca](#busca-busca) abaixo). O operador `contem` faz o mesmo no título e nas propriedades de texto indexáveis (inclusive `autor.titulo`); em outras colunas, como `descricao`, diferencia maiúsculas. Consulta mal formada devolve lista vazia |
| `<@localizaraleatorio …@>…<@/localizaraleatorio@>` | como `localizar`, em ordem aleatória |
| `<@tags@>` | imprime os links das tags do objeto corrente (`<ul class="pbl-tags">`, cada um para `/tag/<slug>`; nome escapado); vazio se não há tags ([ADR 0024](adr/0024-tags-nos-objetos.md)) |
| `<@nuvem [limite=n]@>` | nuvem de tags do site: `<ul class="pbl-nuvem">` com `class="peso-1"` a `peso-5"` por uso, em ordem alfabética (padrão 30, máximo 100); conta só objetos publicados e vigentes. Mudou as tags, o site é republicado |
| `<@semfilhos@>…<@/semfilhos@>` / `<@naolocalizado@>…<@/naolocalizado@>` | executa se o último laço de coleção não achou nada (são sinônimos) |
| `<@usarobjeto id=n \| url="…" \| titulo="…"@>…<@/usarobjeto@>` | troca o objeto corrente dentro do bloco; objeto inexistente, de outro site ou não publicado: o bloco não roda |
| `<@temfilho [id=n]@>…<@/temfilho@>` | executa se o objeto (corrente ou `id=`) tem filhos publicados e vigentes; o `id=` precisa ser do mesmo site |
| `<@formulario [id=n]@>` | imprime o **formulário público** de envio do conteúdo `id` (padrão: o objeto corrente, que então precisa ser do tipo `formulario`). Sem corpo. O HTML sai escapado, com token e campo-armadilha, sem JavaScript; o envio vai para `/_pbl/enviar/{id}` ([ADR 0013](adr/0013-formularios-publicos.md)). Formulário de outro site, fora do ar, fechado ou mal configurado não imprime nada. O HTML é gerado ao publicar a página: se mudar o formulário, republique |
| `<@arquivo valor=… info="url\|nome\|tamanho\|tipo"@>` | imprime (sempre escapado) um dado de uma propriedade `arquivo`/`imagem`: `url` (link de entrega, `/_pbl/arquivo/<id>`), `nome` (nome original do arquivo), `tamanho` (legível: `340 KB`, `2,1 MB`) ou `tipo` (extensão: `pdf`, `jpg`...). `valor` é o id guardado na propriedade (`#propriedade`); vazio se o arquivo não existe neste site, o objeto dono não está publicado e vigente, ou `info` é desconhecido — ver [fluxo 7](fluxos/07-upload-blobs.md) |
| `<@miniatura valor=… tamanho=n@>` | imprime (sempre escapada) a URL da miniatura de uma propriedade `imagem` (`/_pbl/arquivo/<id>/miniatura/<tamanho>`); `tamanho` só de `64`, `150`, `400` ou `800` (a mesma whitelist da entrega). Vazio se não é imagem, o tamanho não é da lista, ou o arquivo não está visível |
| `%SITE.config.chave` (em expressões) | valor da **configuração do site** do objeto corrente ([ADR 0017](adr/0017-configuracoes-do-site.md)). Chaves: `nome`, `slogan`, `idioma`, `descricao`, `imagem_padrao`, `titulo_padrao`, `titulo_sufixo`, `icone`, `email_contato`, `telefone`, `endereco`, `redes_sociais`, `analytics`, `rodape`, `busca_ligada`, `itens_por_pagina`, `menu_profundidade`, `robots_indexar`. Chave fora da lista é erro do molde. Booleano vale `1`/`0`, lista vem em linhas; sem valor gravado, o padrão (vazio, `pt-BR`, `1`, `10`, `0`). `eco` escapa; só `rodape` (já limpo) cabe em `ecocru`. `imagem_padrao` e `icone` são ids de arquivo: guarde em `<@var $x = …@>` e use `<@arquivo valor=$x info="url"@>` |
| `<@menu [profundidade=n] [inicio="Início"]@>` | imprime o **menu principal** do site (`<nav><ul>` aninhado, sem JavaScript): os objetos marcados `no_menu`, na ordem do `peso`, até `profundidade` níveis (padrão 3, máximo 5), com `class="ativo"`/`aria-current` no item da página e `class="ancestral"` nos de cima. `inicio=` acrescenta o item `/` no começo. Lê o snapshot do site, **sem consultar o banco**; rótulos e URLs saem escapados. Sem corpo |
| `<@estende "layout"@>` + `<@bloco nome@>…<@/bloco@>` | herança de layout |
| `<@incluir arquivo="parte"@>` | inclui outro molde da mesma pele |
| `<@-- texto --@>` | comentário: não gera saída e pode ter aspas e símbolos; vai até o primeiro `--@>` |

### Menu, busca e paginação (fase 5l)

- Variáveis do molde: `$q` (termo da busca, até 200 caracteres), `$q_url` (o mesmo, já codificado para URL), `$pagina` (1 por padrão; `?pagina=N` nas listagens). Variável indefinida vale vazio.
- Propriedades do objeto corrente: `#no_menu`, `#rotulo_menu` (vazio = usa o título), `#caminho` (`/1/5/9/`, ids dos ancestrais), `#raiz` (id da página inicial do site), `#site_nome`, `#feed_url` (feed RSS da seção, vazio se não há), `#feed_site_url` (feed do site) e `#feed_titulo`.
- Paginação sem total: `<@filhos inicio=($pagina-1)*10 limite=11@>`, mostrar os 10 primeiros e tratar o 11º como "há próxima página". `%INDICE` começa em 1.
- Moldes especiais da pele: `busca` (servido em `/busca?q=…`, se não existir a página `busca`) e `erro404` (resposta 404 das páginas públicas, com fallback ao texto simples).
- Corte: o menu respeita profundidade máxima e 50 itens por nível. Para sites grandes, use menu até 3 níveis mais uma página de mapa do site com `<@localizar@>`/`<@filhos@>` paginados.

### Página de tag (fase 5r)

`/tag/<slug>` usa o molde `tag` da pele (variáveis: `$tag_nome`, `$tag_slug`, `$pagina`; a lista vem de `localizar tag=$tag_slug`). Não existe molde `tag` na pele ou a tag não existe: 404.

### Herança de layout

O primeiro comando do molde filho é `estende`. Tudo o mais fica dentro de `bloco`; o layout define `bloco` com conteúdo padrão, e o filho sobrescreve. Cadeias de vários níveis funcionam (filho → meio → base). Conteúdo fora de bloco, bloco repetido (inclusive um bloco dentro de outro com o mesmo nome) e ciclo são erros.

```
layout "base":   <h1><@bloco titulo@>Padrão<@/bloco@></h1><main><@bloco corpo@><@/bloco@></main>
molde:           <@estende "base"@><@bloco corpo@>Olá <@eco #nome@><@/bloco@>
```

Nomes de layout e de `incluir` são caminhos relativos à pasta da pele (`a-z 0-9 _` e `/`), sem `..` nem extensão; o arquivo é `<nome>.pbl`.

### Ordenação (`ordem`)

Lista separada por vírgulas, com `-` (decrescente) ou `+` na frente, ou `asc`/`desc` atrás: `peso, -data_publicacao`, `titulo desc`. Aceita as colunas `id`, `titulo`, `peso`, `descricao`, `data_publicacao`, `data_validade`, `criado_em`, `atualizado_em`, `status`, qualquer **propriedade indexável** da classe e o campo de um **objeto referenciado** (`autor.titulo`, `-autor.cargo`), até 4 critérios. Sem `ordem`, vale `peso` e depois `id`, como no PBL3 (com `busca`, vale `relevancia`, depois `peso`). `relevancia` também é aceita (ver [Busca](#busca-busca)). **Valores nulos ficam sempre por último**, em qualquer direção e em qualquer banco. Nada da `ordem` chega ao SQL como texto livre. Ordenar pelo objeto apontado é uma consulta só, sem tabela temporária (ver [fluxo 9](fluxos/09-armazenamento-consulta.md)).

### Busca (`busca`)

`<@localizar busca="presid relatório"@>…<@/localizar@>` procura no **título**, na **descrição** e no texto das propriedades `texto` e `html` (sem as marcações), entre os objetos publicados e vigentes do site. Regras:

- **Todas as palavras** precisam aparecer, em qualquer ordem e em qualquer campo (`presidencia relatorio` acha "Relatório da Presidência").
- **Começo de palavra**: `presid` acha `Presidência` e `Presidente`; um pedaço do meio da palavra não acha.
- **Plural e singular**: `ações` acha `ação` e vice-versa; `casas`, `casa`. Só plural: não trata feminino nem verbos.
- **Maiúsculas e acentos** não importam. Pontuação, `%`, `_` e aspas separam palavras e nunca são curingas.
- Palavras vazias (`de`, `da`, `para`...) e letras soltas são ignoradas; uma busca só com elas não acha nada. Busca em branco não filtra.
- Limites: até 8 palavras (as demais são ignoradas), 200 caracteres no total, 40 por palavra.
- Combina com `classes`, `pai`, `niveis`, `campo`/`op`/`valor`, `inicio` e `limite`.
- **Relevância**: sem `ordem`, os melhores resultados vêm primeiro (título 3, descrição 2, texto 1; palavra inteira e início do título pesam mais). Em `ordem`, escreva `relevancia` (sozinha ou com outros critérios, como `relevancia, -data_publicacao`); sem `busca` ela é ignorada. Uma propriedade chamada `relevancia` fica inacessível em `ordem`.
- **Sugestão de grafia parecida** (`%SUGESTAO`, nível C do [ADR 0010](adr/0010-busca.md)): quando `busca=` não acha nada, `%SUGESTAO` (dentro de `<@naolocalizado@>`, igual a como `semfilhos` lê o resultado do laço) traz um "você quis dizer…" calculado em cima do vocabulário já indexado — nada de serviço externo nem tabela nova. Cada palavra digitada que não é começo de nenhum termo visível (publicado e vigente; nunca de conteúdo privado, fora do ar ou na lixeira) vira o candidato mais parecido por distância de edição (uma letra de diferença em palavras curtas, duas nas longas); se alguma palavra não tem candidato confiável, `%SUGESTAO` fica vazia — nenhuma sugestão é melhor que uma errada. **Opcional por site, desligada por padrão**: liga em `/_pbl/site/{id}/busca`, no painel. Fora de `naolocalizado`/depois de um `localizar busca=…` sem resultado, `%SUGESTAO` também é vazia. Exemplo:
  ```
  <@localizar busca=$q@>…<@/localizar@>
  <@naolocalizado@>
    <@se %SUGESTAO != ""@>Nada encontrado. Você quis dizer <a href="?q=<@eco %SUGESTAO@>"><@eco %SUGESTAO@></a>?<@/se@>
  <@/naolocalizado@>
  ```

### Tipos de propriedade

| Tipo | Valor aceito | Indexável |
|---|---|---|
| `texto`, `html` | texto | opcional |
| `numero` | inteiro ou decimal | opcional |
| `data` | `aaaa-mm-dd`, `aaaa-mm-dd hh:mm:ss` (data impossível é recusada) | opcional |
| `booleano` | verdadeiro/falso (guardado como 1/0) | opcional |
| `ref` | id de outro objeto **do mesmo site** (e da classe indicada, se a propriedade restringe) | sempre |
| `arquivo`, `imagem` | id de um arquivo enviado (upload pela tela de conteúdo, criar ou editar; nunca pelo molde); `imagem` só jpg/png/gif/webp, `arquivo` documentos (pdf, txt, csv...). A classe pode restringir `tipos` (subconjunto da whitelist da categoria) e `tamanho_max_mb`, na tela de tipos de conteúdo. Comandos `arquivo`/`miniatura` (acima) leem o valor; ver [fluxo 7](fluxos/07-upload-blobs.md) | nunca |

`expandir="autor"` em `filhos`/`localizar` carrega os objetos apontados da lista inteira numa consulta só (até 4 propriedades). Sem ele, `#autor.titulo` funciona do mesmo jeito, buscando o objeto sob demanda uma vez por página.

### Arquivos (`arquivo`/`miniatura`)

`#propriedade` guarda só o **id** do arquivo; `arquivo`/`miniatura` leem esse id e devolvem o dado já escapado. Os dois só
imprimem algo se o arquivo existe neste site e o **objeto dono** (fixado no upload, não o objeto corrente) está publicado e
vigente agora — exatamente a mesma regra do link `/_pbl/arquivo/<id>` ([ADR 0014](adr/0014-upload-de-arquivos.md)); fora
disso, vazio (nunca um link quebrado ou um erro).

```
<@se #foto != ""@>
  <a href="<@arquivo valor=#foto info="url"@>">
    <img src="<@miniatura valor=#foto tamanho=150@>" alt="<@eco #titulo@>">
  </a>
<@/se@>

<@filhos classes="documento"@>
  <a href="<@arquivo valor=#anexo info="url"@>"><@eco #titulo@></a>
  (<@arquivo valor=#anexo info="tipo"@>, <@arquivo valor=#anexo info="tamanho"@>)
<@/filhos@>
```

`miniatura` só funciona para propriedade `imagem` e só com `tamanho` de `64`, `150`, `400` ou `800` (qualquer outro valor
é vazio, nunca gera um arquivo novo); sem o GD instalado no servidor, a miniatura também fica vazia (mostre o próprio
`arquivo info="url"` como alternativa).

## Conversor de moldes PBL3 → PBL4

```bash
make pbl ARGS="converter-moldes /caminho/do/site/html dados/peles/minha-pele"
make pbl ARGS="converter-moldes /caminho/do/site/html dados/peles/minha-pele --seguro"
```

Lê `.php`, `.pbl`, `.html`, `.htm` que tenham tags `<@…@>` (e os moldes sem tags que algum molde inclua), grava um `.pbl` por arquivo e um `RELATORIO.md`. Nunca escreve fora da pasta de destino. O resultado é sempre revalidado pelo parser do PBL4.

| O que o conversor faz | Como |
|---|---|
| Parâmetros `nome=[valor]` | os colchetes saem; `nome=` de laços é descartado |
| `eco` e `=` (sem escape no PBL3) | `ecocru`, para a saída não mudar; com `--seguro` viram `eco` (escapam HTML). `ecoe` vira `eco` |
| `cortaTexto(x, n)` | `eco_limite texto=x limite=n` |
| `#cod_objeto`, `#cod_pai`, `#cod_classe` | `#id`, `#pai`, `#classe_id`; `#cod_status` vira `#status` com o nome do estado |
| `niveis=[n]` | `niveis=n+1` (no PBL3, 0 já eram os filhos diretos); `pai=[-1]` e `niveis=[-1]` somem |
| `condicao=["a=1 and b>2"]` | até três comparações (`=`, `!=`/`<>`, `<`, `>`, `<=`, `>=`) ligadas por `and`: `campo="a" valor="1" campo2="b" op2=">" valor2="2"`; os campos precisam ser **indexáveis** (vai para o relatório). `or`, parênteses e `like` ficam para revisão |
| `cod_objeto=[x]` em `usarobjeto`/`temfilho` | `id=x` |
| `incluir arquivo=['/html/include/x.php']` | `incluir arquivo="include/x"` e o arquivo é convertido junto |
| `$_page->config["portal"]["url"]` | removido (links relativos à raiz do site) |
| PHP cru (`<?php … ?>`) | removido e substituído por `<@-- REVISAR … --@>` |
| comando sem equivalente, expressão com PHP, `condicao` complexa, `like`/`ilike` | comentário `<@-- REVISAR … --@>` com o original; entra no relatório |
| arquivo em ISO-8859-1 | convertido para UTF-8 |

Pontos a saber: ids fixos nos moldes (`pai=[1]`) só continuam certos se o importador (fase 9) **preservar os ids** do PBL3; o relatório lista moldes incluídos que não existem na origem.

## Do PBL3 para o PBL4

A tabela orienta o conversor de moldes (fase 2c). Estado: ✅ nesta versão (o conversor aplica a regra), ⏳ depende de fases seguintes, ❌ não será portado.

| PBL3 | PBL4 | |
|---|---|---|
| `eco` (sem escape) | `ecocru` (preserva a saída) ou `eco` (modo seguro do conversor) | ✅ |
| `ecoe` (`htmlentities`) | `eco` | ✅ |
| `=`, `eco_limite`, `formatadata`, `var`, `se/senao`, `repetir` | mesmos nomes; fechamento `\x` ou `/x` vira `/x` | ✅ |
| `filhos`, `semfilhos`, `aleatorio`, `naolocalizado` | mesmos nomes (`nome=` é ignorado) | ✅ |
| `incluir arquivo=…` | `incluir arquivo=…` (nome de molde da pele, não caminho do servidor) | ✅ |
| `#cod_objeto`, `#cod_pai`, `#cod_classe`, `#cod_status`, `#url_amigavel`, `#peso`, `#descricao`, datas | mapeados (ver conversor) | ✅ |
| `#temfilhos` | `#temfilhos` | ✅ |
| `#tags` | `<@tags@>` (links); `localizar tags=[…]` vira `tag=…` | ✅ |
| `localizar`, `localizaraleatorio` | `localizar`/`localizaraleatorio` com o subconjunto acima; `condicao`, `like` e `ilike` em SQL livre **não** existem: o conversor os marca para revisão e a fase 1 traz filtros equivalentes | ✅ (parcial) |
| `usarobjeto titulo=…`/`cod_objeto=…`, `temfilho cod_objeto=…` | `usarobjeto titulo=…`/`id=…`, `temfilho id=…` | ✅ |
| `usarblob nome=["x"]@>…<@/usarblob@>` | sem bloco: os comandos abaixo recebem `valor=#x` direto (o conversor troca `#x` pelo nome literal de `nome=`; `nome=` dinâmico vai para revisão) | ✅ |
| `srcblob`, `linkblob`, `downloadblob` (dentro de `usarblob`) | `<@arquivo valor=#x info="url"@>` (os três eram o mesmo link no PBL3; no PBL4 inline/anexo é decidido pelo servidor, não pelo molde) | ✅ |
| `tamanhoblob` | `<@arquivo valor=#x info="tamanho"@>` | ✅ |
| `tipoblob` | `<@arquivo valor=#x info="tipo"@>` | ✅ |
| `srcthumb` | `<@miniatura valor=#x tamanho=150@>` (o PBL3 não tinha tamanho configurável aqui; o conversor fixa 150 e avisa que 64/400/800 também existem) | ✅ |
| `iconeblob`, `iconeclasse` | ícone por tipo de arquivo ou por classe não existe no PBL4 | ❌ |
| `config` com nome fixo de uma chave do catálogo | `<@eco %SITE.config.chave@>` (aviso informativo; nome calculado ou chave fora do catálogo vai para revisão) | ✅ |
| macros `ROOT`, `PERFIL`, `CONFIG`, `USUARIO` | usuário/perfil depois da fase 3; sem equivalente claro ainda | ⏳ |
| `executar` (inclui script PHP e chama função) | **módulo** com interface estável (ADR 0005); o conversor aponta cada uso | ❌ |
| `protegido`, `incluimenu` | área protegida e menu do admin: fase 3 e 5 | ❌ |
| `$_GET`, `$GLOBALS` e PHP cru nos moldes | sem equivalente (sandbox) | ❌ |

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
           | "eco" , expr | "ecocru" , expr
           | "eco_limite" , params | "formatadata" , params
           | "var" , variavel , "=" , expr
           | "se" , condicao | "senao" | "/se"
           | "repetir" , variavel , "=" , "[" , expr , "," , expr , "]" | "/repetir"
           | colecao , [ params ] | "/" , colecao
           | "semfilhos" | "/semfilhos" | "naolocalizado" | "/naolocalizado"
           | "usarobjeto" , params | "/usarobjeto"
           | "temfilho" , [ params ] | "/temfilho"
           | "formulario" , [ params ]
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
| `<@eco_limite texto=… [limite=…] [inicio=…]@>` | recorta o texto (caracteres) e escapa |
| `<@formatadata valor=… formato="d/m/Y"@>` | formata data (`aaaa-mm-dd[ hh:mm:ss]` ou `dd/mm/aaaa[ hh:mm:ss]`); inválida dá vazio |
| `<@var $x = expr@>` | define variável |
| `<@se cond@>…<@senao@>…<@/se@>` | condicional |
| `<@repetir $i=[a,b]@>…<@/repetir@>` | laço numérico inclusivo |
| `<@filhos [classes="a,b"] [ordem="peso, -data_publicacao"] [inicio=n] [limite=n] [expandir="autor"]@>…<@/filhos@>` | laço sobre filhos **publicados e vigentes** (fora de `data_publicacao`/`data_validade` não aparecem) |
| `<@aleatorio …@>…<@/aleatorio@>` | como `filhos`, em ordem aleatória: sorteia entre **todos** os candidatos e só então aplica `inicio`/`limite` (`limite=1` escolhe um item qualquer) |
| `<@localizar [classes=…] [pai=n] [niveis=n] [campo=… op=… valor=…] [campo2=…] [campo3=…] [busca=…] [ordem=…] [inicio=n] [limite=n] [expandir=…]@>…<@/localizar@>` | laço sobre objetos **publicados e vigentes do mesmo site**. `pai` limita à subárvore (sem o próprio pai) e `niveis` à profundidade (`niveis=1` são os filhos diretos). Até **três condições** (todas precisam valer): `campo`/`op`/`valor`, `campo2`/`op2`/`valor2`, `campo3`/`op3`/`valor3`. `campo` é uma coluna do objeto, uma propriedade **indexável** ou `ref.campo` (`autor.cargo`); `op` é `=` (padrão), `!=`, `<`, `>`, `<=`, `>=` ou `contem`. `busca` é a busca de texto por palavras (ver [Busca](#busca-busca) abaixo). O operador `contem` faz o mesmo no título e nas propriedades de texto indexáveis (inclusive `autor.titulo`); em outras colunas, como `descricao`, diferencia maiúsculas. Consulta mal formada devolve lista vazia |
| `<@localizaraleatorio …@>…<@/localizaraleatorio@>` | como `localizar`, em ordem aleatória |
| `<@semfilhos@>…<@/semfilhos@>` / `<@naolocalizado@>…<@/naolocalizado@>` | executa se o último laço de coleção não achou nada (são sinônimos) |
| `<@usarobjeto id=n \| url="…" \| titulo="…"@>…<@/usarobjeto@>` | troca o objeto corrente dentro do bloco; objeto inexistente, de outro site ou não publicado: o bloco não roda |
| `<@temfilho [id=n]@>…<@/temfilho@>` | executa se o objeto (corrente ou `id=`) tem filhos publicados e vigentes; o `id=` precisa ser do mesmo site |
| `<@formulario [id=n]@>` | imprime o **formulário público** de envio do conteúdo `id` (padrão: o objeto corrente, que então precisa ser do tipo `formulario`). Sem corpo. O HTML sai escapado, com token e campo-armadilha, sem JavaScript; o envio vai para `/_pbl/enviar/{id}` ([ADR 0013](adr/0013-formularios-publicos.md)). Formulário de outro site, fora do ar, fechado ou mal configurado não imprime nada. O HTML é gerado ao publicar a página: se mudar o formulário, republique |
| `<@estende "layout"@>` + `<@bloco nome@>…<@/bloco@>` | herança de layout |
| `<@incluir arquivo="parte"@>` | inclui outro molde da mesma pele |
| `<@-- texto --@>` | comentário: não gera saída e pode ter aspas e símbolos; vai até o primeiro `--@>` |

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

`expandir="autor"` em `filhos`/`localizar` carrega os objetos apontados da lista inteira numa consulta só (até 4 propriedades). Sem ele, `#autor.titulo` funciona do mesmo jeito, buscando o objeto sob demanda uma vez por página.

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
| comando sem equivalente, expressão com PHP, `condicao` complexa, `like`/`ilike`/`tags` | comentário `<@-- REVISAR … --@>` com o original; entra no relatório |
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
| `#tags` | ainda não existe no contexto | ⏳ |
| `localizar`, `localizaraleatorio` | `localizar`/`localizaraleatorio` com o subconjunto acima; `condicao`, `like`, `ilike` e `tags` em SQL livre **não** existem: o conversor os marca para revisão e a fase 1 traz filtros equivalentes | ✅ (parcial) |
| `usarobjeto titulo=…`/`cod_objeto=…`, `temfilho cod_objeto=…` | `usarobjeto titulo=…`/`id=…`, `temfilho id=…` | ✅ |
| `usarblob`, `linkblob`, `downloadblob`, `tamanhoblob`, `iconeblob`, `tipoblob`, `srcblob`, `srcthumb`, `iconeclasse` | comandos de arquivo/imagem: entram com o upload e a entrega de blobs (fluxo 7) | ⏳ |
| `config`, macros `ROOT`, `PERFIL`, `CONFIG`, `USUARIO` | só valores públicos e seguros do site (configuração de site); usuário/perfil depois da fase 3 | ⏳ |
| `executar` (inclui script PHP e chama função) | **módulo** com interface estável (ADR 0005); o conversor aponta cada uso | ❌ |
| `protegido`, `incluimenu` | área protegida e menu do admin: fase 3 e 5 | ❌ |
| `$_GET`, `$GLOBALS` e PHP cru nos moldes | sem equivalente (sandbox) | ❌ |

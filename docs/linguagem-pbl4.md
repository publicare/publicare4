# Linguagem PBL4 (v1)

Referência da linguagem de moldes. O PBL4 mantém o espírito do PBL3 (tags `<@…@>`, vocabulário em português, `#campo`, `$variavel`, `%MACRO`) com gramática formal, escape por padrão e execução em sandbox. Design: [fluxo 2](fluxos/02-compilacao-pbl.md).

## Princípios

- **Três estágios**: Lexer → Parser (árvore) → Gerador (PHP). O PHP gerado vai para `dados/cache/pbl/<hash>.php` e é reaproveitado; o hash cobre o molde e toda a cadeia de layouts.
- **Nada do molde vira código**: o gerador só emite literais (`var_export`) e chamadas ao `Runtime`. PHP cru (`<?`, `?>`) é rejeitado. O molde não alcança superglobais, arquivos nem funções do PHP.
- **Escape por padrão**: `eco` escapa HTML; `ecocru` é a saída sem escape, escolhida de forma explícita.
- **Erros são exceções com linha** (e arquivo, quando o erro está num layout ou parte incluída). Nada de `exit`.
- **Limites**: `repetir` e `filhos` têm teto de 10.000 iterações; `incluir` aninha no máximo 8 níveis; herança no máximo 5.

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
| `#campo` | campo do objeto corrente: `#id`, `#titulo`, `#url` (`/` + URL amigável), `#url_amigavel`, `#classe`, `#classe_id`, `#site`, `#pai`, `#peso`, `#descricao`, `#data_publicacao`, `#data_validade`, `#status`, `#apagado` e os campos da classe (esses nomes são reservados). Dentro de `filhos`, o objeto corrente é o filho. Campo inexistente é vazio. |
| `$variavel` | variável do molde, criada por `var` ou `repetir`; compartilhada com os moldes incluídos; vazia se não definida. Não toca em variáveis do PHP. |
| `%MACRO` | `DIA`, `MES`, `ANO`, `DATA` (dd/mm/aaaa); em laço de coleção: `INDICE` (1..n) e `FIM`/`QUANTIDADE` (total). Macro desconhecida é erro de sintaxe. |
| `.` | concatenação (texto). `+ - * /` aritmética; texto não numérico vale 0; divisão por zero dá vazio. |
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
| `<@filhos [classes="a,b"] [ordem="peso, -data_publicacao"] [inicio=n] [limite=n]@>…<@/filhos@>` | laço sobre filhos **publicados e vigentes** (fora de `data_publicacao`/`data_validade` não aparecem) |
| `<@aleatorio …@>…<@/aleatorio@>` | como `filhos`, em ordem aleatória |
| `<@localizar [classes=…] [pai=n] [niveis=n] [campo=… valor=…] [busca=…] [ordem=…] [inicio=n] [limite=n]@>…<@/localizar@>` | laço sobre objetos **publicados do mesmo site**: `pai` limita à subárvore (excluindo o próprio pai) e `niveis` à profundidade; `campo`+`valor` é igualdade em propriedade **indexável** da classe; `busca` procura o texto no título (sem diferenciar maiúsculas; `%` e `_` são literais). Subconjunto seguro; o motor de consulta completo vem na fase 1 |
| `<@localizaraleatorio …@>…<@/localizaraleatorio@>` | como `localizar`, em ordem aleatória |
| `<@semfilhos@>…<@/semfilhos@>` / `<@naolocalizado@>…<@/naolocalizado@>` | executa se o último laço de coleção não achou nada (são sinônimos) |
| `<@usarobjeto id=n \| url="…" \| titulo="…"@>…<@/usarobjeto@>` | troca o objeto corrente dentro do bloco; objeto inexistente, de outro site ou não publicado: o bloco não roda |
| `<@temfilho [id=n]@>…<@/temfilho@>` | executa se o objeto (corrente ou `id=`) tem filhos publicados |
| `<@estende "layout"@>` + `<@bloco nome@>…<@/bloco@>` | herança de layout |
| `<@incluir arquivo="parte"@>` | inclui outro molde da mesma pele |
| `<@-- texto --@>` | comentário: não gera saída e pode ter aspas e símbolos; vai até o primeiro `--@>` |

### Herança de layout

O primeiro comando do molde filho é `estende`. Tudo o mais fica dentro de `bloco`; o layout define `bloco` com conteúdo padrão, e o filho sobrescreve. Cadeias de vários níveis funcionam (filho → meio → base). Conteúdo fora de bloco, bloco repetido e ciclo são erros.

```
layout "base":   <h1><@bloco titulo@>Padrão<@/bloco@></h1><main><@bloco corpo@><@/bloco@></main>
molde:           <@estende "base"@><@bloco corpo@>Olá <@eco #nome@><@/bloco@>
```

Nomes de layout e de `incluir` são caminhos relativos à pasta da pele (`a-z 0-9 _` e `/`), sem `..` nem extensão; o arquivo é `<nome>.pbl`.

### Ordenação (`ordem`)

Lista separada por vírgulas, com `-` (decrescente) ou `+` na frente, ou `asc`/`desc` atrás: `peso, -data_publicacao`, `titulo desc`. Aceita as colunas `id`, `titulo`, `peso`, `descricao`, `data_publicacao`, `data_validade`, `criado_em`, `atualizado_em` e qualquer **propriedade indexável** da classe (até 4 propriedades). Sem `ordem`, vale `peso` e depois `id`, como no PBL3. Nada da `ordem` chega ao SQL como texto livre. Ordenar pelo campo de um objeto referenciado (`obj_ref`) fica para o motor de consulta da fase 1 (ver [fluxo 9](fluxos/09-armazenamento-consulta.md)).

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
| `condicao=["campo=valor"]` | `campo="campo" valor="valor"`; o campo precisa ser **indexável** (vai para o relatório) |
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
| `#temfilhos`, `#tags` | ainda não existem no contexto (`temfilho` cobre o primeiro) | ⏳ |
| `localizar`, `localizaraleatorio` | `localizar`/`localizaraleatorio` com o subconjunto acima; `condicao`, `like`, `ilike` e `tags` em SQL livre **não** existem: o conversor os marca para revisão e a fase 1 traz filtros equivalentes | ✅ (parcial) |
| `usarobjeto titulo=…`/`cod_objeto=…`, `temfilho cod_objeto=…` | `usarobjeto titulo=…`/`id=…`, `temfilho id=…` | ✅ |
| `usarblob`, `linkblob`, `downloadblob`, `tamanhoblob`, `iconeblob`, `tipoblob`, `srcblob`, `srcthumb`, `iconeclasse` | comandos de arquivo/imagem: entram com o upload e a entrega de blobs (fluxo 7) | ⏳ |
| `config`, macros `ROOT`, `PERFIL`, `CONFIG`, `USUARIO` | só valores públicos e seguros do site (configuração de site); usuário/perfil depois da fase 3 | ⏳ |
| `executar` (inclui script PHP e chama função) | **módulo** com interface estável (ADR 0005); o conversor aponta cada uso | ❌ |
| `protegido`, `incluimenu` | área protegida e menu do admin: fase 3 e 5 | ❌ |
| `$_GET`, `$GLOBALS` e PHP cru nos moldes | sem equivalente (sandbox) | ❌ |

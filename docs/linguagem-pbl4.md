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
           | "filhos" , [ params ] | "/filhos" | "semfilhos" | "/semfilhos"
           | "estende" , string
           | "bloco" , nome | "/bloco"
           | "incluir" , params ;
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
| `#campo` | campo do objeto corrente (`#titulo`, `#url`, `#id`, `#classe` e os campos da classe). Dentro de `filhos`, o objeto corrente é o filho. Campo inexistente é vazio. |
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
| `<@filhos [classes="a,b"] [ordem="titulo desc"] [inicio=n] [limite=n]@>…<@/filhos@>` | laço sobre filhos **publicados**; `ordem` aceita `id`, `titulo`, `criado_em`, `atualizado_em` e `desc` |
| `<@semfilhos@>…<@/semfilhos@>` | executa se o último laço de coleção não achou nada |
| `<@estende "layout"@>` + `<@bloco nome@>…<@/bloco@>` | herança de layout |
| `<@incluir arquivo="parte"@>` | inclui outro molde da mesma pele |

### Herança de layout

O primeiro comando do molde filho é `estende`. Tudo o mais fica dentro de `bloco`; o layout define `bloco` com conteúdo padrão, e o filho sobrescreve. Cadeias de vários níveis funcionam (filho → meio → base). Conteúdo fora de bloco, bloco repetido e ciclo são erros.

```
layout "base":   <h1><@bloco titulo@>Padrão<@/bloco@></h1><main><@bloco corpo@><@/bloco@></main>
molde:           <@estende "base"@><@bloco corpo@>Olá <@eco #nome@><@/bloco@>
```

Nomes de layout e de `incluir` são caminhos relativos à pasta da pele (`a-z 0-9 _` e `/`), sem `..` nem extensão; o arquivo é `<nome>.pbl`.

## Do PBL3 para o PBL4

A tabela orienta o conversor de moldes (fase 2c). Estado: ✅ nesta versão, ⏳ próximo PR da fase 2, ❌ não será portado.

| PBL3 | PBL4 | |
|---|---|---|
| `eco` (sem escape) | `ecocru` (preserva a saída) ou `eco` (modo seguro do conversor) | ✅ |
| `ecoe` (`htmlentities`) | `eco` | ✅ |
| `=`, `eco_limite`, `formatadata`, `var`, `se/senao`, `repetir` | mesmos nomes; fechamento `\x` ou `/x` vira `/x` | ✅ |
| `filhos`, `semfilhos` | `filhos`, `semfilhos` (`nome=` é ignorado) | ✅ |
| `incluir arquivo=…` | `incluir arquivo=…` (nome de molde da pele, não caminho do servidor) | ✅ |
| `#cod_objeto`, `#cod_pai` | `#id`, `#pai` | ⏳ |
| `localizar`, `naolocalizado`, `aleatorio`, `localizaraleatorio` | consulta (depende do motor de consulta, fase 1) | ⏳ |
| `usarobjeto`, `temfilho` | idem | ⏳ |
| `usarblob`, `linkblob`, `downloadblob`, `tamanhoblob`, `iconeblob`, `tipoblob`, `srcblob`, `srcthumb`, `iconeclasse` | comandos de arquivo/imagem | ⏳ |
| `config`, macros `ROOT`, `PERFIL`, `CONFIG`, `USUARIO` | só valores públicos e seguros do site; usuário/perfil depois da fase 3 | ⏳ |
| `executar` (inclui script PHP e chama função) | **módulo** com interface estável (ADR 0005); o conversor aponta cada uso | ❌ |
| `protegido`, `incluimenu` | área protegida e menu do admin: fase 3 e 5 | ❌ |
| `$_GET`, `$GLOBALS` e PHP cru nos moldes | sem equivalente (sandbox) | ❌ |

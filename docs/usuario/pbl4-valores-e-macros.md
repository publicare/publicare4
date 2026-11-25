---
titulo: Valores, campos e macros
resumo: Como escrever campos (#), variáveis ($), macros (%) e expressões nos moldes, e o que mudou nas macros do PBL3.
capitulo: linguagem-pbl4
ordem: 30
publico: desenvolvedor
---

# Valores, campos e macros

Dentro das tags, um valor pode ser um texto entre aspas, um número, um campo, uma variável, uma macro ou uma conta com eles.

## Campos: `#nome`

`#titulo` é o título do conteúdo corrente; `#resumo` é o campo `resumo` do tipo dele. Dentro de uma lista (`filhos`, `localizar`), o conteúdo corrente é o item da volta.

Para um campo de **referência** a outro conteúdo, o ponto cola o nome do campo ao do conteúdo apontado: `#autor.titulo`, `#autor.cargo`. É um nível só, e só enxerga conteúdo do mesmo site que esteja publicado e dentro da validade.

```pbl
<@filhos classes="noticia" expandir="autor"@>
  <@eco #titulo@> por <@eco #autor.titulo@>
<@/filhos@>
```

## Variáveis: `$nome`

Criadas por `var` ou `repetir`, valem até o fim do molde e dos moldes incluídos. Variável que não foi definida vale vazio. Veja [variáveis e condições](pbl4-variaveis-e-condicoes.md).

Algumas variáveis já vêm prontas em páginas especiais: `$q` e `$q_url` (termo da busca), `$pagina` (página da listagem, começando em 1) e `$ed` (recorte da busca por edição, usado pela pele do manual).

## Macros: `%NOME`

Valores calculados pelo sistema, sempre em caixa alta:

| Macro | Valor |
|---|---|
| `%DIA`, `%MES`, `%ANO` | dia, mês e ano de hoje |
| `%DATA` | a data de hoje, no formato dia/mês/ano |
| `%INDICE` | a posição do item na volta da lista, começando em 1 |
| `%FIM` e `%QUANTIDADE` | o total de itens da lista |
| `%SUGESTAO` | o "você quis dizer..." da busca, dentro de `naolocalizado` |

Macro desconhecida é erro de sintaxe, e o molde não é gravado. Os valores da configuração do site vêm de `%SITE.config.chave`, por exemplo `<@eco %SITE.config.nome@>`; chave fora do catálogo é erro.

## Expressões

- `.` junta textos (`"Olá, " . #titulo`); `+ - * /` fazem contas. Texto que não é número vale zero, e dividir por zero dá vazio.
- Comparações: `==` (ou `=`), `!=`, `<`, `>`, `<=`, `>=`. Se os dois lados são números, compara como número; senão, como texto, diferenciando maiúsculas.
- São **falsos**: vazio, `""`, `"0"` e `0`. Todo o resto é verdadeiro.

## Migrando do PBL3

| Macro do PBL3 | No PBL4 |
|---|---|
| `%DIA`, `%MES`, `%ANO`, `%DATA`, `%INDICE`, `%FIM`, `%QUANTIDADE` | Iguais. `%FIM` agora é o **total** de itens (igual a `%QUANTIDADE`), e as duas valem em qualquer lista. |
| `%ROOT` (código do objeto raiz) | `#raiz`, campo do conteúdo corrente. |
| `%CONFIG` (configuração do portal) | `%SITE.config.chave`, uma chave por vez, do catálogo da configuração do site. |
| `%COD_OBJETO` | `#id` (dentro da lista, é o do item). |
| `%COD_PAI` | `#pai`. |
| `%TAGS` | O comando `<@tags@>`, que imprime os links das tags do conteúdo. |

Os campos `#cod_objeto`, `#cod_pai`, `#cod_classe` e `#cod_status` dos moldes antigos viram `#id`, `#pai`, `#classe_id` e `#status` quando o [conversor de moldes](pbl4-migrando-do-pbl3.md) trabalha.

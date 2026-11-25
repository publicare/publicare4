---
titulo: Listas e objetos
resumo: Os comandos filhos, aleatorio, localizar, semfilhos, naolocalizado, usarobjeto e temfilho, com filtros, ordem e o que mudou no PBL3.
capitulo: linguagem-pbl4
ordem: 60
publico: desenvolvedor
---

# Listas e objetos

Estes comandos buscam conteúdo e repetem um bloco para cada item. Dentro do bloco, os campos (`#titulo`, `#url`...) são os do **item**, e `%INDICE` e `%QUANTIDADE` dizem a posição e o total. Só entram conteúdos **publicados e dentro da validade**, do mesmo site.

## filhos

Percorre os filhos diretos do conteúdo corrente.

```pbl
<ul>
<@filhos classes="noticia" ordem="-data_publicacao" limite=5@>
  <li><a href="<@ecourl #url@>"><@eco #titulo@></a></li>
<@/filhos@>
</ul>
```

| Parâmetro | Efeito |
|---|---|
| `classes` | nomes de tipos de conteúdo, separados por vírgula (`"noticia,evento"`). Sem ele, todos os tipos. |
| `ordem` | critérios separados por vírgula, com `-` para decrescente: `"peso, -data_publicacao"`. Sem `ordem`, vale `peso` e depois `id`. Valores vazios ficam por último. |
| `inicio`, `limite` | pular itens e limitar a quantidade (paginação). |
| `tag` | só conteúdo com as tags indicadas (`tag="agenda"`). |
| `expandir` | carrega de uma vez os conteúdos apontados por campos de referência (`expandir="autor"`). |

A ordem aceita as colunas `id`, `titulo`, `peso`, `descricao`, `data_publicacao`, `data_validade`, `criado_em`, `atualizado_em`, `status` e os campos **indexáveis** do tipo.

## aleatorio

Igual a `filhos`, em ordem aleatória. O sorteio acontece entre **todos** os filhos, e só depois `inicio` e `limite` são aplicados: `limite=1` escolhe um item qualquer.

```pbl
<@aleatorio classes="destaque" limite=1@>
  <p><@eco #titulo@></p>
<@/aleatorio@>
```

## localizar

Busca conteúdo em **todo o site**, não só nos filhos. Os parâmetros de `filhos` valem aqui, mais:

| Parâmetro | Efeito |
|---|---|
| `pai`, `niveis` | limita a uma subárvore (o próprio pai não entra). `niveis=1` são só os filhos diretos. |
| `campo`, `op`, `valor` | uma condição. `campo` é uma coluna, um campo **indexável** ou `referencia.campo`; `op` é `=`, `!=`, `<`, `>`, `<=`, `>=` ou `contem`. |
| `campo2`, `op2`, `valor2` e `campo3`, `op3`, `valor3` | até **três** condições, todas valendo ao mesmo tempo. |
| `busca` | busca por palavras no título, na descrição e nos textos (todas as palavras, começo de palavra, plural, sem acento). |

```pbl
<@localizar classes="evento" campo="local" op="=" valor="Sala 1" ordem="-data_publicacao" limite=10@>
  <@eco #titulo@>
<@/localizar@>
```

Consulta mal formada devolve lista vazia, sem erro. `localizaraleatorio` faz o mesmo em ordem aleatória.

## semfilhos e naolocalizado

Executam o bloco quando o **último laço de coleção** (`filhos`, `aleatorio`, `localizar`) não achou nada. São sinônimos; escreva logo depois do fechamento da lista.

```pbl
<@filhos classes="noticia"@>
  <p><@eco #titulo@></p>
<@/filhos@>
<@semfilhos@><p>Nenhuma notícia ainda.</p><@/semfilhos@>
```

## usarobjeto

Troca o conteúdo corrente dentro do bloco por outro: informe **um** entre `id`, `url` ou `titulo`. Se o conteúdo não existir, for de outro site ou não estiver publicado, o bloco **não roda**.

```pbl
<@usarobjeto url="bloco-aviso"@>
  <div class="aviso"><@ecocru #corpo@></div>
<@/usarobjeto@>
```

## temfilho

Executa o bloco se o conteúdo corrente (ou o indicado por `id=`, do mesmo site) tem filhos publicados.

```pbl
<@temfilho@><nav>Esta seção tem subpáginas.</nav><@/temfilho@>
```

## Migrando do PBL3

| PBL3 | PBL4 | O que mudou |
|---|---|---|
| `filhos nome=[x] classes=[p] ordem=[o]` | `filhos classes="p" ordem="o"` | Sem colchetes nos valores. `nome=` é aceito mas ignorado. `classes` agora é o **nome exato** do tipo, não um prefixo. |
| `aleatorio` | `aleatorio` | Igual, com `inicio`/`limite`. |
| `localizar ... condicao=["a>1 && b=x"]` | `campo="a" op=">" valor="1" campo2="b" valor2="x"` | `condicao`, `like` e `ilike` **não existem** e dão erro; os filtros acima (até três) os substituem, e os campos precisam ser indexáveis. |
| `localizar tags=[...]` | `localizar tag="..."` | O nome do parâmetro mudou. |
| `localizar pai=[n] niveis=[k]` | `pai=n niveis=k+1` | No PBL3, nível 0 já eram os filhos diretos; no PBL4 são `niveis=1`. |
| `ordem` | `ordem` | Mesmo uso, com `-` para decrescente e mais colunas. |
| `semfilhos`, `naolocalizado` | iguais | Valem para qualquer lista, são sinônimos. |
| `usarobjeto cod_objeto=[n]` | `usarobjeto id=n` | O parâmetro `titulo` continua, e há `url`. |
| `temfilho cod_objeto=[n]` | `temfilho id=n` | Só do mesmo site. |

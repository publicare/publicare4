---
titulo: Conceitos do PBL4
resumo: Tipo de conteúdo, conteúdo, pai e filho, campos do sistema, pele e molde: o modelo por trás da linguagem.
capitulo: linguagem-pbl4
ordem: 20
publico: desenvolvedor
---

# Conceitos do PBL4

A linguagem trabalha sobre um modelo simples. Entender estes conceitos explica quase tudo o que os comandos fazem.

## Tipo de conteúdo

Um **tipo de conteúdo** (no código e nos moldes, a *classe*) descreve **que campos** um conteúdo tem: uma notícia tem resumo, texto e imagem; um evento tem data e local. Ele é um modelo: os conteúdos são criados a partir dele. O administrador cria e altera tipos na tela **Tipos de conteúdo** (veja [Tipos de conteúdo](tipos-de-conteudo.md)). Os tipos valem para todos os sites da instalação.

Cada campo tem um **nome técnico** (é com ele que o molde o chama, como `#resumo`) e um tipo: texto, número, HTML, data, sim ou não, referência a outro conteúdo, imagem ou arquivo. Para filtrar e ordenar por um campo nos comandos de lista, ele precisa ser **indexável**.

## Conteúdo (objeto)

Um **conteúdo** é uma página com campos próprios, herdados do tipo escolhido na hora de criá-lo. Um conteúdo pode conter outros conteúdos.

## Pai e filho

O **pai** é o conteúdo onde outro reside; o **filho** é o que reside dentro dele. É uma relação: uma pasta é pai dos arquivos dentro dela, e um mesmo conteúdo pode ser pai de uns e filho de outro. Uma subpasta é filha da pasta e pai dos seus arquivos. Isso forma a **árvore** do site. No molde, `#pai` dá o id do pai, `#temfilhos` diz se há filhos visíveis e o comando [`filhos`](pbl4-listas-e-objetos.md#filhos) percorre os filhos.

O tipo pode limitar quais tipos são aceitos como filhos (aba **Onde pode ficar** do tipo).

## Campos do sistema

Todo conteúdo, de qualquer tipo, tem estes campos. Os nomes são reservados: um campo de tipo não pode usá-los.

| Campo | O que é |
|---|---|
| `#id` | número do conteúdo |
| `#titulo` | o título |
| `#descricao` | descrição curta, usada para buscadores |
| `#url` | endereço amigável, começando por `/` |
| `#url_amigavel` | o endereço sem a barra inicial |
| `#classe`, `#classe_id` | nome e número do tipo de conteúdo |
| `#site` | número do site |
| `#pai` | número do conteúdo pai |
| `#peso` | número usado para ordenar (menor primeiro) |
| `#data_publicacao` | a partir de quando o conteúdo fica visível |
| `#data_validade` | a partir de quando deixa de ficar visível |
| `#status` | situação (por exemplo, publicado) |
| `#temfilhos` | `1` se há filhos visíveis, senão `0` |

A lista completa, com os campos do menu e do feed, está na [referência](../linguagem-pbl4.md). Campo que não existe vem **vazio**, sem erro.

## Pele e molde

A **pele** é um conjunto de moldes com a aparência do site; o **molde** (*template*) diz onde e como os campos de um conteúdo são mostrados. Cada site tem uma pele ativa, e um conteúdo pode escolher uma pele e um molde próprios, que os filhos herdam. A escolha do molde para cada página está em [A linguagem PBL4](pbl4-visao-geral.md#qual-molde-mostra-cada-pagina).

## Se você vem do PBL3

| No PBL3 | No PBL4 |
|---|---|
| Classe | O mesmo conceito, chamado de **tipo de conteúdo** no painel. |
| Objeto, objeto pai, objeto filho | Iguais, com os mesmos nomes na árvore. |
| Metadados `titulo`, `descricao`, `data_publicacao`, `data_validade`, `peso` | Existem, como campos do sistema (`#titulo` etc.). |
| `cod_classe` | `#classe_id` (e `#classe` traz o nome). |
| `cod_pele` | A pele é escolhida no site e, se quiser, em cada conteúdo (herdada pelos filhos). |
| `prefixo_classe` | Não existe. Os comandos de lista usam o **nome exato** do tipo em `classes=`. |
| Template em PHP, na pasta `html/template` do portal, com `view_basic.php` como padrão | Molde `.pbl` dentro da pele, com `padrao.pbl` como padrão; sem PHP. |
| Misturar PBL e PHP no mesmo arquivo | Não existe: o molde é executado em área isolada. |

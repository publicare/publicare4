---
titulo: A linguagem PBL4
resumo: O que é a linguagem dos moldes, onde ela vive, como o molde certo é escolhido para cada página e o que ela garante.
capitulo: linguagem-pbl4
ordem: 10
publico: desenvolvedor
---

# A linguagem PBL4

A **PBL** é a linguagem dos **moldes** (modelos de página) do Publicare. Ela existe para você montar páginas a partir do conteúdo guardado no banco **sem escrever PHP nem SQL**: o molde é HTML comum com tags especiais, que começam com `<@` e terminam com `@>`.

```pbl
<h1><@eco #titulo@></h1>
<@se #resumo != ""@><p><@eco #resumo@></p><@/se@>
<@filhos classes="noticia" limite=5@>
  <a href="<@ecourl #url@>"><@eco #titulo@></a>
<@/filhos@>
```

Cada tag é um **comando** (`eco`, `se`, `filhos`...). Comandos que abrem um bloco fecham com `<@/nome@>`. Dentro das tags você usa três tipos de valor: `#campo` (um dado do conteúdo), `$variavel` (uma variável do molde) e `%MACRO` (um valor calculado, como a data de hoje). Veja [valores, campos e macros](pbl4-valores-e-macros.md).

## Onde ficam os moldes

Moldes são arquivos `.pbl` dentro de uma **pele**. Quem tem permissão de administrador edita os moldes pelo painel (veja [Peles e modelos de página](peles-e-moldes.md)); o sistema confere a sintaxe antes de gravar e mostra a linha do erro. Os nomes de molde usam minúsculas sem acento, números e `_`.

## Qual molde mostra cada página

Para cada página, o Publicare escolhe o molde nesta ordem:

1. o molde escolhido no próprio conteúdo (ou herdado do conteúdo acima dele), se existir na pele;
2. `home`, se a página é a página inicial do site e a pele tem esse molde;
3. o molde com o **nome do tipo de conteúdo** (um conteúdo do tipo `noticia` usa `noticia.pbl`), se existir;
4. `padrao`, que toda pele precisa ter.

## O que a linguagem garante

- **Escape por padrão.** `eco` escapa o HTML; para imprimir HTML confiável você precisa escolher `ecocru`, de forma explícita.
- **Nada do molde vira código.** PHP cru (`<?`, `?>`) é recusado, e o molde não alcança arquivos, variáveis do servidor nem funções do PHP.
- **Erros com linha.** Um molde com erro não é gravado: a mensagem diz o arquivo e a linha.
- **Limites.** `repetir` e as listas (`filhos`, `localizar`) têm teto de dez mil voltas; `incluir` aninha até oito níveis e a herança de layout, até cinco.
- **Só o que está no ar.** Listas mostram apenas conteúdo publicado e dentro da validade, do mesmo site.
- **Comentário.** `<@-- texto --@>` não gera saída.

## Herança de layout

Um molde pode **estender** outro e preencher só os blocos que mudam. O primeiro comando do molde filho é `estende`; tudo o mais fica dentro de `bloco`.

```pbl
<@estende "layout"@>
<@bloco miolo@>
  <h1><@eco #titulo@></h1>
<@/bloco@>
```

No molde base, `<@bloco miolo@>conteúdo padrão<@/bloco@>` marca o ponto que o filho pode trocar. Conteúdo fora de `bloco`, bloco repetido e ciclo de heranças são erros.

## Próximos passos

- [Conceitos](pbl4-conceitos.md): tipo de conteúdo, conteúdo, pai e filho, pele e molde.
- [Valores, campos e macros](pbl4-valores-e-macros.md).
- Comandos: [imprimir](pbl4-imprimir.md), [variáveis e condições](pbl4-variaveis-e-condicoes.md), [listas e objetos](pbl4-listas-e-objetos.md) e [incluir e menu](pbl4-incluir-e-menu.md).
- Vem do Publicare antigo? Veja [Migrando do PBL3](pbl4-migrando-do-pbl3.md).
- A referência completa, com a gramática formal, fica em [linguagem-pbl4.md](../linguagem-pbl4.md).

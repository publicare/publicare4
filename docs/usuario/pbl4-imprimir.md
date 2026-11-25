---
titulo: Imprimir valores
resumo: Os comandos eco, ecocru, ecourl, eco_limite e formatadata, e como o ecoe e o eco do PBL3 passaram para o PBL4.
capitulo: linguagem-pbl4
ordem: 40
publico: desenvolvedor
---

# Imprimir valores

Estes comandos escrevem um valor no ponto do molde onde aparecem. Todos aceitam texto entre aspas, campos (`#`), variáveis (`$`), macros (`%`) e contas.

## eco

Imprime o valor **escapando o HTML** (`<` vira `&lt;` e assim por diante). É a escolha certa para qualquer texto vindo de campo. A forma curta `<@= expr@>` faz o mesmo.

```pbl
<h1><@eco #titulo@></h1>
<p>Hoje é <@eco %DATA@>.</p>
```

## ecocru

Imprime **sem escape**. Use só para HTML de confiança, como um campo do tipo "Texto formatado (HTML)", que o sistema já limpa ao salvar.

```pbl
<div class="texto"><@ecocru #corpo@></div>
```

## ecourl

Imprime um endereço para usar em `href` ou `src`: só `http://`, `https://` ou um caminho começando por `/`. Qualquer outro esquema (como `javascript:`) vira vazio.

```pbl
<a href="<@ecourl #url@>">Leia mais</a>
```

## eco_limite

Imprime um pedaço do texto, escapado: `texto` é o valor, `limite` é o número de caracteres e `inicio` (opcional) é a posição de partida.

```pbl
<p><@eco_limite texto=#resumo limite=120@>...</p>
```

O corte é **exato em caracteres**: a palavra pode ser cortada ao meio. O texto é tratado como texto puro, então não use em campo HTML (as marcações apareceriam escritas).

## formatadata

Formata uma data. Aceita `aaaa-mm-dd` ou `dd/mm/aaaa`, com hora opcional; data inválida ou impossível dá vazio. O `formato` usa as letras do PHP (`d/m/Y`, `d/m/Y H:i`).

```pbl
<time><@formatadata valor=#data_publicacao formato="d/m/Y"@></time>
```

## Migrando do PBL3

| PBL3 | PBL4 | O que mudou |
|---|---|---|
| `eco` | `ecocru` ou `eco` | No PBL3 o `eco` **não** escapava. No PBL4 o `eco` escapa: para manter a saída antiga use `ecocru`, e para a segurança nova use `eco`. O conversor de moldes escolhe `ecocru`, ou `eco` com a opção segura. |
| `ecoe` | `eco` | O `ecoe` aplicava `htmlentities` do PHP. No PBL4 não existe comando `ecoe` (daria erro); o `eco` já escapa. |
| `eco_limite` | `eco_limite` | Parâmetros `texto` e `limite`, como antes. O PBL3 não cortava palavras ao meio; o PBL4 corta no caractere exato. Há um parâmetro novo, `inicio`. |
| `=` | `=` | Igual (e agora escapa). |
| `cortaTexto(x, n)` em PHP | `eco_limite texto=x limite=n` | Sem PHP nos moldes. |

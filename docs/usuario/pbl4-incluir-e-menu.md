---
titulo: Incluir partes e menu
resumo: Os comandos incluir, estende, bloco e menu; o incluimenu do PBL3 não existe mais e o menu do site tem comando próprio.
capitulo: linguagem-pbl4
ordem: 70
publico: desenvolvedor
---

# Incluir partes e menu

## incluir

Insere outro molde **da mesma pele** e o executa no ponto onde aparece. O nome é relativo à pasta da pele, usa minúsculas, números, `_` e `/`, e **não leva extensão** (o arquivo é `<nome>.pbl`).

```pbl
<@incluir arquivo="cabeca"@>
<main>...</main>
<@incluir arquivo="rodape"@>
```

O molde incluído vê as mesmas variáveis. A inclusão aninha no máximo oito níveis. Para repetir uma parte em todas as páginas, a [herança de layout](pbl4-visao-geral.md#heranca-de-layout) costuma ser melhor que `incluir`.

## estende e bloco

Veja [A linguagem PBL4](pbl4-visao-geral.md#heranca-de-layout): o molde filho começa com `<@estende "layout"@>` e só preenche os `bloco`.

## menu

Imprime o **menu principal** do site, em `<nav><ul>` aninhado, sem JavaScript. Entram os conteúdos marcados para aparecer no menu, na ordem do peso; o item da página atual recebe a marca de página corrente.

```pbl
<@menu profundidade=2 inicio="Início"@>
```

`profundidade` é o número de níveis (padrão 3, máximo 5) e `inicio` acrescenta um item de volta à página inicial. Quem escolhe o que entra no menu é o editor, no painel.

## Migrando do PBL3

| PBL3 | PBL4 | O que mudou |
|---|---|---|
| `incluir arquivo=["/html/include/x.php"]` | `incluir arquivo="include/x"` | Antes, caminho de arquivo a partir da raiz do servidor; agora, **nome de molde da pele**, sem extensão e sem `..`. O conversor converte o arquivo incluído junto. |
| `incluimenu` | **não existe** | Inseria o menu de administração do Publicare para perfis restritos ou acima. No PBL4 a edição fica no painel e na [barra de edição](barra-de-edicao.md); para o menu do **site**, use `menu`. |
| arquivos PHP incluídos | sem equivalente | O molde não executa PHP. O que era feito com `executar` ou script próprio passa a ser módulo ou recurso do produto. |

---
titulo: Variáveis, condições e repetição
resumo: Os comandos var, se, senao e repetir, com a sintaxe do PBL4 e o que mudou em relação ao PBL3.
capitulo: linguagem-pbl4
ordem: 50
publico: desenvolvedor
---

# Variáveis, condições e repetição

## var

Define uma variável do molde. O nome começa com `$`, e o sinal de `=` separa o valor.

```pbl
<@var $titulo = #titulo . " (arquivo)"@>
<h1><@eco $titulo@></h1>
```

A variável vale até o fim do molde e nos moldes incluídos. Ela é só do molde: não toca em variáveis do PHP. Variável não definida vale vazio.

## se e senao

`se` executa o bloco se a condição for verdadeira; `senao` (opcional) marca o bloco do contrário; `/se` fecha. A condição é um valor sozinho ou **uma comparação** (`==`, `=`, `!=`, `<`, `>`, `<=`, `>=`). Os colchetes em volta são opcionais.

```pbl
<@se #resumo != ""@>
  <p><@eco #resumo@></p>
<@senao@>
  <p>Sem resumo.</p>
<@/se@>
```

Um valor sozinho é verdadeiro se não for vazio, `"0"` ou `0`. Para combinar condições, aninhe um `se` dentro de outro. Se os dois lados são números, a comparação é numérica; senão, é de texto, diferenciando maiúsculas.

## repetir

Repete o bloco para cada número de um intervalo, **incluindo as pontas**. A variável recebe o número a cada volta.

```pbl
<ul>
<@repetir $i=[1,3]@>
  <li>Item <@eco $i@></li>
<@/repetir@>
</ul>
```

O limite é de dez mil voltas. Os extremos podem ser contas: `$i=[1, #peso]`.

## Migrando do PBL3

| PBL3 | PBL4 | O que mudou |
|---|---|---|
| `<@var variavel=valor@>` | `<@var $variavel = valor@>` | A variável leva `$`. O PBL3 também gravava variáveis do PHP; o PBL4 não. |
| `<@se [a == b]@>` | `<@se a == b@>` | Os colchetes passam a ser opcionais. `=` e `==` são o mesmo. |
| `<@senao@>` | `<@senao@>` | Igual: fica entre o `se` e o `/se`. |
| `<@repetir i=1,5@>` | `<@repetir $i=[1,5]@>` | Variável com `$` e o intervalo entre colchetes. É o equivalente de um `for`, e o PBL4 limita o número de voltas. |
| Fechamento `<@\se@>` | `<@/se@>` | O fechamento usa `/`. O conversor ajusta sozinho. |

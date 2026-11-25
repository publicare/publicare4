---
titulo: Migrando do PBL3
resumo: O que mudou da linguagem do Publicare antigo para o PBL4, o que deixou de existir e como converter os moldes com o comando converter-moldes.
capitulo: linguagem-pbl4
ordem: 80
publico: desenvolvedor
---

# Migrando do PBL3

O PBL4 mantém o espírito do PBL3: as tags `<@ ... @>`, o vocabulário em português, `#campo`, `$variavel` e `%MACRO`. O que mudou foi a segurança e a precisão: a linguagem agora tem uma gramática formal, escapa o HTML por padrão e roda em área isolada, sem PHP.

## O que mudou, em resumo

- **`eco` escapa.** O que o PBL3 imprimia cru passa a ser `ecocru`, e o `ecoe` desaparece (o `eco` já faz o trabalho dele).
- **Variáveis levam `$`**: `<@var $x = 1@>` e `<@repetir $i=[1,5]@>`.
- **Sem colchetes nos parâmetros**: `limite=5`, `classes="noticia"`.
- **Sem PHP nos moldes**, nem `$_GET`, nem `executar`. Um molde com PHP é recusado.
- **Filtros no lugar de SQL livre**: `condicao`, `like` e `ilike` do `localizar` viram até três filtros (`campo`/`op`/`valor`).
- **`classes` é o nome exato** do tipo de conteúdo, não um prefixo.
- **Nomes de campo novos**: `#cod_objeto` vira `#id`, `#cod_pai` vira `#pai`, `#cod_classe` vira `#classe_id`.
- **Arquivos** deixam de ser `usarblob`/`srcblob` e viram `arquivo` e `miniatura`, que só mostram arquivo de conteúdo publicado.

## Os verbetes do PBL3, um a um

| Verbete do PBL3 | Onde está no PBL4 | Situação |
|---|---|---|
| ECO, ECOE, ECO_LIMITE | [Imprimir valores](pbl4-imprimir.md) | mudou (escape; `ecoe` não existe) |
| VAR, SE, SENAO, REPETIR | [Variáveis, condições e repetição](pbl4-variaveis-e-condicoes.md) | mudou (sintaxe com `$`) |
| FILHOS, ALEATORIO, LOCALIZAR | [Listas e objetos](pbl4-listas-e-objetos.md) | mudou (filtros; `classes` exato) |
| SEMFILHOS, NAOLOCALIZADO, USAROBJETO | [Listas e objetos](pbl4-listas-e-objetos.md) | igual (`id=` no lugar de `cod_objeto=`) |
| INCLUIR | [Incluir partes e menu](pbl4-incluir-e-menu.md) | mudou (nome de molde, não caminho) |
| INCLUIRMENU | [Incluir partes e menu](pbl4-incluir-e-menu.md) | **não existe**; use `menu` para o menu do site |
| Macros | [Valores, campos e macros](pbl4-valores-e-macros.md) | `ROOT`, `CONFIG`, `COD_OBJETO`, `COD_PAI` e `TAGS` mudaram |

Os conceitos (classe, objeto, pai, filho, metadados, pele e template) estão em [Conceitos do PBL4](pbl4-conceitos.md).

## Converter os moldes

O comando `converter-moldes` lê os moldes de um site do PBL3 e grava a versão PBL4, mais um relatório.

```
php bin/pbl converter-moldes /caminho/do/site/html dados/peles/minha-pele
php bin/pbl converter-moldes /caminho/do/site/html dados/peles/minha-pele --seguro
```

- Converte `.php`, `.pbl`, `.html` e `.htm` que tenham tags `<@ ... @>`, e os moldes que eles incluem.
- Nunca escreve fora da pasta de destino, e o resultado é revalidado pelo PBL4.
- Com `--seguro`, o que era `eco` cru vira `eco` (escapa); sem ele, vira `ecocru`, para a página sair igual.
- O que não tem equivalente (PHP cru, `condicao` complexa, `like`, `executar`) fica como comentário `<@-- REVISAR ... --@>`, com o original, e entra no relatório. **Leia o relatório e resolva cada item** antes de ativar a pele.
- Ids fixos nos moldes (como `pai=1`) só continuam certos se o importador preservar os ids; veja [Como migrar um site do PBL3](../tecnica/migrar-do-pbl3.md).

Para levar também conteúdo, contas e arquivos, use o importador descrito nesse guia.

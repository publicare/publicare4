# ADR 0039 — Fonte única do manual em docs/usuario, com front matter

Status: aceito.

## Contexto
`docs/usuario` tinha guias sem metadados, misturando texto de usuário com texto de contribuição, e faltavam capítulos por tarefa. O manual precisa ser estável (sem datas nem versões) e mantido junto do código.

## Decisão
1. O manual é `docs/usuario/*.md`; não há segunda cópia.
2. Front matter restrito (`chave: valor`, sem aninhamento): `titulo`, `resumo` (até 200), `capitulo`, `ordem` (único no capítulo); opcionais `slug` e `publico: contribuicao`. `capitulos.md` define a árvore.
3. `como-participar` e `estado-do-projeto` ficam fora do manual.
4. `INVENTARIO.md` lista telas e comandos contra guias; um teste reprova tela ou comando sem linha.
5. Texto por tarefa, sem datas nem números de versão; imagens só por marcador de captura (ADR 0042).

## Consequências
Um lugar de edição; quem muda uma tela muda o guia. Front matter restrito dispensa biblioteca de YAML.

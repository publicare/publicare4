# ADR 0043 — Pele do manual

Status: aceito.

## Decisão
Pele `manual` em `app/recursos/peles/manual`: menu lateral por capítulo, seletor de edições, busca nativa sem JavaScript, tema claro/escuro (CSS `prefers-color-scheme` e botão com `localStorage`), impressão por `@media print`, landmarks, foco visível, alvos de 44 px; sem CDN e sem CSS ou JavaScript embutido (teste varre a pele). A pele `publicare` não estava na main: a pele traz layout próprio e a adaptação para estendê-la está em `docs/tecnica/manual.md`.

## Consequências
Sem PDF; impressão pelo navegador.

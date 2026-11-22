# ADR 0032 — Dieta de texto e botão "?" pequeno

Status: aceito.

## Contexto
O "?" de ajuda era um círculo de 28 px (44 px no celular) que disputava espaço com o campo. As telas explicavam demais, com frases de enchimento.

## Decisão
1. **Mantém `<details>`** para a ajuda. O texto fica sempre no HTML, ligado ao campo por `aria-describedby`; abre sem JavaScript, por teclado. O `popover` nativo perderia isso.
2. **Ícone de 1,15 rem (≈ 18 px)**, discreto. A área de toque de 44 px vem de um `::after` absoluto no `summary`, sem alargar a linha. No celular a ajuda ocupa a linha de baixo do rótulo e nunca cobre o campo.
3. **Esc** fecha a ajuda e devolve o foco ao "?" (`pbl-js.js`, melhoria progressiva).
4. **Guia de voz** em [`docs/ux/voz-e-texto.md`](../ux/voz-e-texto.md) e **teste anti-enchimento** (`tests/TextoDoPainelTest.php`): falha com padrão proibido, ajuda acima de 140 caracteres ou subtítulo de mais de uma frase. Exceções só em `tests/fixtures/texto-excecoes.php`, com justificativa.
5. **Auditoria**: `bin/auditar-texto` conta palavras por tela e categoria; a linha de base está em `docs/ux/auditoria-texto-antes.json`. Meta: −40% no texto corrido.

## Consequências
- Avisos de risco (senha, restauração, atualização, exclusão) ficam, reescritos em uma frase.
- Telas da onda 2 seguem sem o limite de tamanho (lista `ONDA_2` no teste) até a aprovação do dono.

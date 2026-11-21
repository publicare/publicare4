# Testes do protótipo (prototipo-ux)

Sem dependências (Node 20+; globs exigem 21+):

    node --test "tests/unit/*.test.mjs" "tests/integration/*.test.mjs" "tests/smoke/*.test.mjs"

- `unit/`: validação ao vivo, contador, busca sem acento e contraste, extraindo funções de `proto.js` (sem navegador).
- `integration/`: prévia estática v2, ausência de PHP, `export-ignore`, assets locais, gerador idempotente.
- `smoke/`: servidor estático local percorrendo os 4 caminhos, `noindex` e assets (200).
- `e2e/`: Playwright (precisa de navegador); `prototipo-ux/tests`: links, ids, rótulos, contraste.

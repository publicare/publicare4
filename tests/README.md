# Testes do protótipo (prototipo-ux)

Sem dependências (Node 21+ por causa dos globs):

    node --test "tests/unit/*.test.mjs" "tests/integration/*.test.mjs" "tests/smoke/*.test.mjs"
    cd prototipo-ux && npm test

- `unit/`: regras puras (`assets/regras.js`): validação, contador, prévia, filtro sem acento.
- `integration/`: pedido de prévia estático, ausência de PHP, `export-ignore`, assets locais, gerador idempotente, sem Bootstrap/fonte externa.
- `smoke/`: servidor estático local percorrendo os 4 caminhos, `noindex` e assets (200).
- `prototipo-ux/tests`: links, ids, rótulos, sem inline/CDN/sombra, menu, interruptores, contraste AA, guia × CSS.

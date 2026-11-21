# E2E do protótipo (tests/e2e)

- `*.feature`: cenários Gherkin (pt) dos 4 caminhos do roteiro, do botão "Isto ficou confuso" e de navegação/teclado/toque.
- `steps/*.spec.mjs`: os mesmos cenários em Playwright (celular 360 e 390 px), servidos por `python3 -m http.server` sobre `prototipo-ux/`. Cada teste leva o título do cenário.
- `contract/` e `regression/`: testes sem dependências (Node 20+) que garantem que features, specs, JS e HTML não se desalinhem.

```
cd tests/e2e
npm test                                 # contrato + regressão (sem navegador)
npm i && npm run instalar:navegador && npm run test:browser   # Playwright
```
Os specs de navegador exigem rede para instalar o Chromium; não rodaram na fábrica.

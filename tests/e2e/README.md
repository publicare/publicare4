# E2E do painel (dieta de texto e botão "?")

- `features/*.feature`: cenários Gherkin (pt).
- `contract/`: contrato do "?" no CSS/JS (tamanho, área de toque, Esc). `node --test`, sem navegador.
- `regression/`: renderiza as telas reais (`support/renderizar-telas.php`, site descartável) e confere marcação acessível, CSP sem inline, padrões proibidos, limite de ajuda e avisos de risco; mais a paridade cenário↔teste.
- `steps/ajuda.spec.mjs`: Playwright (4 viewports). Precisa de painel de teste: `PBL_URL`, `PBL_SITE_ID`, `PBL_LOGIN`, `PBL_SENHA`. Sem `PBL_URL`, tudo é ignorado.

```
cd tests/e2e && npm install && npm run instalar:navegador
npm test                                  # contrato + regressão (offline)
PBL_URL=https://teste.local PBL_SENHA=… npm run test:browser
```

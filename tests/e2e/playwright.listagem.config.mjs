import { defineConfig } from '@playwright/test';

// Roda contra uma instância real do painel: PBL_URL=https://.../_pbl/objeto/1 npx playwright test -c playwright.listagem.config.mjs
export default defineConfig({
  testDir: 'steps',
  testMatch: 'listagem-conteudo.spec.mjs',
  timeout: 20000,
  use: { ignoreHTTPSErrors: true },
});

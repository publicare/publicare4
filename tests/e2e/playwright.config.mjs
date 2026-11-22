import { defineConfig, devices } from '@playwright/test';

// Precisa de um painel PBL4 em execução (site de teste, NÃO produção):
//   PBL_URL=https://www.exemplo.test PBL_SITE_ID=1 PBL_LOGIN=admin PBL_SENHA=… npx playwright test
// Sem PBL_URL os specs são ignorados (skipped) — nunca falham por falta de ambiente.
export default defineConfig({
  testDir: 'steps',
  testMatch: '*.spec.mjs',
  timeout: 30000,
  use: { baseURL: process.env.PBL_URL, ignoreHTTPSErrors: true },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } } },
    { name: 'tablet', use: { ...devices['Desktop Chrome'], viewport: { width: 820, height: 1180 }, hasTouch: true } },
    { name: 'celular-360', use: { ...devices['Pixel 5'], viewport: { width: 360, height: 740 } } },
    { name: 'celular-paisagem', use: { ...devices['Pixel 5'], viewport: { width: 740, height: 360 } } },
  ],
});

import { defineConfig, devices } from '@playwright/test';

// Serve só o protótipo estático; sem rede externa.
export default defineConfig({
  testDir: 'steps',
  testMatch: '*.spec.mjs',
  timeout: 20000,
  use: { baseURL: 'http://127.0.0.1:8099/' },
  webServer: { command: 'python3 -m http.server 8099 --bind 127.0.0.1 --directory ../../prototipo-ux', url: 'http://127.0.0.1:8099/index.html', reuseExistingServer: true },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } } },
    { name: 'celular-390', use: { ...devices['Pixel 5'], viewport: { width: 390, height: 844 } } },
    { name: 'celular-360', use: { ...devices['Pixel 5'], viewport: { width: 360, height: 740 } } },
  ],
});

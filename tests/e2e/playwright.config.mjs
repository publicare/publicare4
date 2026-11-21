import { defineConfig, devices } from '@playwright/test';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const raiz = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'prototipo-ux');
const porta = 4173;

export default defineConfig({
  testDir: './steps',
  testMatch: '*.spec.mjs',
  fullyParallel: true,
  reporter: [['list']],
  use: { baseURL: `http://127.0.0.1:${porta}/`, locale: 'pt-BR', trace: 'retain-on-failure' },
  // Servidor estático local (sem PHP), igual ao da prévia.
  webServer: {
    command: `python3 -m http.server ${porta} --bind 127.0.0.1 --directory "${raiz}"`,
    url: `http://127.0.0.1:${porta}/index.html`,
    reuseExistingServer: true,
  },
  projects: [
    { name: 'celular-390', use: { ...devices['Pixel 7'], viewport: { width: 390, height: 844 } } },
    { name: 'celular-360', use: { ...devices['Pixel 7'], viewport: { width: 360, height: 740 } } },
  ],
});

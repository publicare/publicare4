import { test, expect } from '@playwright/test';

// Roda contra a prévia/instância real: PBL_URL (ex.: https://previa/_pbl/objeto/1) e PBL_COOKIE (sessão de editor).
const URL = process.env.PBL_URL;
test.skip(!URL, 'defina PBL_URL para rodar contra uma instância do painel');
test.beforeEach(async ({ context }) => {
  if (process.env.PBL_COOKIE) {
    const [name, value] = process.env.PBL_COOKIE.split('=');
    await context.addCookies([{ name, value, url: URL }]);
  }
});

const formatos = {
  retrato: { width: 390, height: 844 },
  paisagem: { width: 844, height: 390 },
  tablet: { width: 768, height: 1024 },
  desktop: { width: 1280, height: 800 },
};
const caixa = async (loc) => (await loc.boundingBox());

test('Retrato do celular mostra cartões com ações em uma fileira', async ({ page }) => {
  await page.setViewportSize(formatos.retrato);
  await page.goto(URL);
  const linha = page.locator('.pbl-lc').first();
  await expect(linha.locator('.pbl-icone')).toBeVisible();
  await expect(linha.locator('.pbl-lc-link')).toBeVisible();
  await expect(linha.locator('.pbl-lc-meta')).toBeVisible();
  const botoes = linha.locator('.pbl-lc-acoes > .pbl-lc-acao, .pbl-lc-acoes > .pbl-lc-form .pbl-lc-acao, .pbl-lc-acoes summary');
  const ys = new Set();
  for (const b of await botoes.all()) { const c = await caixa(b); expect(c.height).toBeGreaterThanOrEqual(44); ys.add(Math.round(c.y)); }
  expect(ys.size).toBe(1);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

for (const nome of ['paisagem', 'tablet']) {
  test(nome === 'paisagem' ? 'Paisagem do celular mostra linha horizontal sem quebrar as ações' : 'Tablet mostra linha horizontal', async ({ page }) => {
    await page.setViewportSize(formatos[nome]);
    await page.goto(URL);
    const linha = page.locator('.pbl-lc').first();
    const t = await caixa(linha.locator('.pbl-lc-titulo'));
    const a = await caixa(linha.locator('.pbl-lc-acoes'));
    expect(a.x).toBeGreaterThan(t.x + t.width - 1);
    const botoes = await linha.locator('.pbl-lc-acoes .pbl-lc-acao').all();
    const ys = new Set();
    for (const b of botoes) ys.add(Math.round((await caixa(b)).y));
    expect(ys.size).toBe(1);
  });
}

test('Desktop mostra lista densa', async ({ page }) => {
  await page.setViewportSize(formatos.desktop);
  await page.goto(URL);
  const linha = page.locator('.pbl-lc').first();
  const t = await caixa(linha.locator('.pbl-lc-titulo'));
  const d = linha.locator('.pbl-lc-data');
  if (await d.count()) expect((await caixa(d)).x).toBeGreaterThan(t.x + t.width / 2);
});

test('Só três ações à vista e o resto no menu Mais', async ({ page }) => {
  await page.goto(URL);
  const linha = page.locator('.pbl-lc').first();
  expect(await linha.locator('.pbl-lc-acoes > .pbl-lc-acao, .pbl-lc-acoes > .pbl-lc-form').count()).toBeLessThanOrEqual(3);
  await linha.locator('.pbl-mais > summary').click();
  const itens = linha.locator('.pbl-mais-lista .pbl-lc-item');
  await expect(itens.last()).toHaveClass(/pbl-lc-item--perigo/);
  await expect(itens.last()).toContainText('Apagar');
});

test('O menu abre e fecha pelo teclado, sem JavaScript', async ({ page }) => {
  await page.goto(URL);
  const resumo = page.locator('.pbl-mais > summary').first();
  await resumo.focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('.pbl-mais[open]').first()).toBeVisible();
  await page.keyboard.press('Enter');
  await expect(page.locator('.pbl-mais[open]')).toHaveCount(0);
  await expect(resumo).toBeFocused();
});

test('A linha mostra o nome da classe como texto só na linha pequena', async ({ page }) => {
  await page.goto(URL);
  const icone = page.locator('.pbl-lc .pbl-icone').first();
  const nome = await icone.getAttribute('title');
  expect(await icone.getAttribute('aria-label')).toBe(`Tipo: ${nome}`);
  await expect(page.locator('.pbl-lc-meta').first()).toContainText(nome);
  await expect(page.locator('.pbl-lc-meta', { hasText: 'Por:' })).toHaveCount(0);
});

import { test, expect } from '@playwright/test';

const URL_OK = Boolean(process.env.PBL_URL);
const SITE = process.env.PBL_SITE_ID || '1';
test.skip(!URL_OK, 'defina PBL_URL (painel de teste) para rodar os specs de navegador');

async function entrar(page) {
  await page.goto('/_pbl/entrar');
  await page.getByLabel('Login').fill(process.env.PBL_LOGIN || 'admin');
  await page.getByLabel('Senha').fill(process.env.PBL_SENHA || '');
  await page.getByRole('button', { name: 'Entrar' }).click();
  await expect(page).not.toHaveURL(/entrar/);
}
const abrirConfig = async (page) => { await entrar(page); await page.goto(`/_pbl/site/${SITE}/configuracoes`); };
const ajudaDe = (page, campo) => page.locator('details.pbl-ajuda', { has: page.locator(`summary[aria-label="Ajuda: ${campo}"]`) });
const caixa = async (loc) => { const b = await loc.boundingBox(); expect(b).not.toBeNull(); return b; };

test('O "?" é discreto mas tem área de toque de 44 px', async ({ page }) => {
  await abrirConfig(page);
  const resumo = ajudaDe(page, 'Nome do site').locator('summary');
  const b = await caixa(resumo);
  expect(b.width).toBeLessThanOrEqual(20);
  expect(b.height).toBeLessThanOrEqual(20);
  const area = await resumo.evaluate((el) => {
    const r = getComputedStyle(el, '::after');
    return { w: parseFloat(r.width), h: parseFloat(r.height) };
  });
  expect(area.w).toBeGreaterThanOrEqual(44);
  expect(area.h).toBeGreaterThanOrEqual(44);
  const rotulo = page.locator('.pbl-rotulo', { has: page.locator('label[for="cfg-nome"]') });
  const linha = await caixa(rotulo);
  const label = await caixa(page.locator('label[for="cfg-nome"]'));
  expect(linha.height).toBeLessThanOrEqual(label.height + 4);
});

test('Teclado abre e fecha a ajuda e o foco volta ao "?"', async ({ page }) => {
  await abrirConfig(page);
  const ajuda = ajudaDe(page, 'Nome do site');
  const resumo = ajuda.locator('summary');
  await resumo.focus();
  await page.keyboard.press('Enter');
  await expect(ajuda.locator('.pbl-ajuda-texto')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(ajuda.locator('.pbl-ajuda-texto')).toBeHidden();
  await expect(resumo).toBeFocused();
});

test('Leitor de tela anuncia o botão e a ajuda do campo', async ({ page }) => {
  await abrirConfig(page);
  await expect(ajudaDe(page, 'Nome do site').locator('summary')).toHaveAttribute('aria-label', 'Ajuda: Nome do site');
  const campo = page.locator('#cfg-nome');
  await expect(campo).toHaveAttribute('aria-describedby', 'cfg-nome-ajuda');
  await expect(page.locator('#cfg-nome-ajuda')).toHaveAttribute('role', 'note');
  await expect(page.getByRole('textbox', { name: 'Nome do site' })).toHaveAccessibleDescription(/.+/);
});

test('No celular a ajuda abre abaixo do rótulo sem cobrir o campo', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await abrirConfig(page);
  await ajudaDe(page, 'Nome do site').locator('summary').click();
  const texto = await caixa(page.locator('#cfg-nome-ajuda'));
  const campo = await caixa(page.locator('#cfg-nome'));
  expect(texto.y + texto.height).toBeLessThanOrEqual(campo.y + 0.5);
  const rolagem = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(rolagem).toBeLessThanOrEqual(0);
});

test('Sem JavaScript a ajuda abre e fecha', async ({ browser, baseURL }) => {
  const ctx = await browser.newContext({ javaScriptEnabled: false, baseURL });
  const page = await ctx.newPage();
  // Sem JS não há como preencher o formulário com segurança; o login usa POST puro (HTML), então funciona.
  await abrirConfig(page);
  const ajuda = ajudaDe(page, 'Nome do site');
  await ajuda.locator('summary').click();
  await expect(ajuda.locator('.pbl-ajuda-texto')).toBeVisible();
  await ajuda.locator('summary').click();
  await expect(ajuda.locator('.pbl-ajuda-texto')).toBeHidden();
  await ctx.close();
});

test('Tema escuro mantém o "?" legível', async ({ browser, baseURL }) => {
  const ctx = await browser.newContext({ colorScheme: 'dark', baseURL });
  const page = await ctx.newPage();
  await abrirConfig(page);
  const cores = await ajudaDe(page, 'Nome do site').locator('summary').evaluate((el) => {
    const lum = (c) => { const [r, g, b] = c.match(/[\d.]+/g).slice(0, 3).map(Number).map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
    let fundo = el;
    while (fundo && /rgba\(0, 0, 0, 0\)|transparent/.test(getComputedStyle(fundo).backgroundColor)) fundo = fundo.parentElement;
    const f = lum(getComputedStyle(fundo || document.body).backgroundColor);
    const t = lum(getComputedStyle(el).color);
    return (Math.max(f, t) + 0.05) / (Math.min(f, t) + 0.05);
  });
  expect(cores).toBeGreaterThanOrEqual(4.5);
  await ctx.close();
});

test('Telas principais não têm frases de enchimento', async ({ page }) => {
  await entrar(page);
  for (const caminho of ['/_pbl/', `/_pbl/site/${SITE}/configuracoes`]) {
    await page.goto(caminho);
    const visivel = await page.locator('main').innerText();
    expect(visivel).not.toMatch(/aqui você pode|esta tela|é importante|lembre-se|simplesmente|por favor/i);
  }
});

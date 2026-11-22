import { test, expect } from '@playwright/test';

test('Menu tem cinco itens e migalhas dizem onde estou', async ({ page }) => {
  await page.goto('inicio.html');
  await expect(page.locator('#menu-principal li')).toHaveCount(5);
  await expect(page.locator('nav.migalhas [aria-current="page"]')).toBeVisible();
});

test('Interruptor revela e esconde os campos opcionais', async ({ page }) => {
  await page.goto('novo-evento.html');
  await expect(page.locator('#agendar-campos')).toBeHidden();
  await page.locator('#agendar').check({ force: true });
  await expect(page.locator('#agendar-campos')).toBeVisible();
  await expect(page.locator('[data-acao="publicar"]')).toHaveText('Agendar publicação');
  await page.locator('#agendar').uncheck({ force: true });
  await expect(page.locator('#agendar-campos')).toBeHidden();
});

test('Isto ficou confuso anota e resume', async ({ page }) => {
  await page.goto('inicio.html');
  await page.locator('[data-confuso]').click();
  await page.locator('#confuso-texto').fill('botão escondido');
  await page.locator('[data-anotar]').click();
  await page.goto('index.html#anotacoes');
  await expect(page.locator('#resumo-confusoes')).toHaveValue(/botão escondido/);
});

test('Tema escuro e celular sem rolagem lateral', async ({ page }) => {
  await page.goto('conteudo.html');
  const b = page.locator('.btn-menu');
  if (await b.isVisible()) await b.click();
  await page.locator('[data-tema-alternar]').click();
  await expect(page.locator('html')).toHaveAttribute('data-tema', 'escuro');
  const sobra = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(sobra).toBeLessThanOrEqual(0);
});

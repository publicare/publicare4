import { test, expect } from '@playwright/test';

// Roda contra a prévia gerada pelo servidor (previa/gerar-pacote.php), com a URL em PBL_PREVIA_URL.
// Sem a variável, os testes são ignorados (os mesmos cenários rodam sem navegador no PHPUnit).
const BASE = process.env.PBL_PREVIA_URL;
const entrar = (ir, tema = 'claro') => `/_pbl/previa-entrar?conta=admin&ir=${encodeURIComponent(ir)}&tema=${tema}`;
const TELAS = [
  ['Atualização', '/_pbl/atualizacao'], ['Cópias de segurança', '/_pbl/copias'], ['Tarefas agendadas', '/_pbl/tarefas'],
  ['Contas', '/_pbl/contas'], ['Tipos de conteúdo', '/_pbl/classes'], ['Domínios', '/_pbl/site/1/dominios'], ['Revisão', '/_pbl/revisar'],
];

test.skip(!BASE, 'defina PBL_PREVIA_URL para rodar no navegador');
test.use({ baseURL: BASE });

for (const [nome, caminho] of TELAS) {
  test(`Toda tela redesenhada tem título, migalhas e menu de cinco itens: ${nome}`, async ({ page }) => {
    await page.goto(entrar(caminho));
    await expect(page.locator('h1')).toHaveCount(1);
    await expect(page.locator('.pbl-migalhas')).toBeVisible();
    expect(await page.locator('.pbl-itens > li').count()).toBeLessThanOrEqual(5);
    await expect(page.locator('.pbl-itens [aria-current="page"]')).toHaveCount(1);
  });
  test(`Celular em retrato e paisagem sem rolagem lateral: ${nome}`, async ({ page }) => {
    for (const [w, h] of [[390, 844], [844, 390]]) {
      await page.setViewportSize({ width: w, height: h });
      await page.goto(entrar(caminho));
      const sobra = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      expect(sobra, `${nome} ${w}x${h}`).toBeLessThanOrEqual(0);
    }
  });
}

test('Atualização tem uma ação principal e três abas', async ({ page }) => {
  await page.goto(entrar('/_pbl/atualizacao'));
  await expect(page.locator('.pbl-cabecalho-acao')).toHaveCount(1);
  for (const aba of ['Atualizar', 'Histórico e voltar atrás', 'Configuração']) {
    await expect(page.locator('.pbl-abas-links').getByRole('link', { name: aba })).toBeVisible();
  }
});

test('Abas funcionam sem JavaScript', async ({ browser }) => {
  const ctx = await browser.newContext({ javaScriptEnabled: false, baseURL: BASE });
  const page = await ctx.newPage();
  await page.goto(entrar('/_pbl/atualizacao'));
  await page.getByRole('link', { name: 'Histórico e voltar atrás' }).click();
  expect(page.url()).toContain('aba=historico');
  await expect(page.locator('.pbl-abas-links [aria-current="page"]')).toHaveText(/Histórico/);
  await ctx.close();
});

test('Tema escuro mantém a tela legível', async ({ page }) => {
  await page.goto(entrar('/_pbl/atualizacao', 'escuro'));
  await expect(page.locator('h1')).toBeVisible();
  const cor = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
  const [r, g, b] = cor.match(/\d+/g).map(Number);
  expect((r + g + b) / 3).toBeLessThan(110);
});

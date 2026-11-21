import { test, expect } from '@playwright/test';
import { abrir, semRolagemHorizontal } from './ajudas.mjs';

test.describe('Navegação, teclado e responsividade', () => {
  for (const tela of ['inicio.html', 'editar-pagina.html', 'configuracoes.html', 'conteudo.html']) {
    test(`Sem rolagem horizontal: ${tela}`, async ({ page }) => {
      await abrir(page, tela);
      await semRolagemHorizontal(page);
    });
  }

  test('Link "Pular para o conteúdo" é o primeiro foco', async ({ page }) => {
    await abrir(page, 'inicio.html');
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Pular para o conteúdo' })).toBeFocused();
  });

  test('Tecla / leva o foco à busca global', async ({ page }) => {
    await abrir(page, 'inicio.html');
    await page.keyboard.press('/');
    await expect(page.locator('#busca-global')).toBeFocused();
  });

  test('Alternar tema escuro', async ({ page }) => {
    await abrir(page, 'inicio.html');
    await page.locator('[data-tema]').click();
    await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'dark');
  });

  test('Alvos de toque têm ao menos 44 px', async ({ page }) => {
    await abrir(page, 'editar-pagina.html');
    const baixos = await page.locator('button.btn:visible, .topo-ferramentas a.btn:visible').evaluateAll(
      (els) => els.filter((e) => e.getBoundingClientRect().height < 44).map((e) => e.textContent.trim()));
    expect(baixos).toEqual([]);
  });

  test('Menu de 5 itens', async ({ page }) => {
    await abrir(page, 'inicio.html');
    const botao = page.locator('.botao-menu');
    if (await botao.isVisible()) await botao.click();
    const itens = await page.locator('#menu-principal > ul > li > a').allInnerTexts();
    expect(itens.map((t) => t.replace(/\s*\d+\s*aguardando$/, '').replace(/\s+\d+$/, '').trim()))
      .toEqual(['Início', 'Conteúdo', 'Revisar', 'Site', 'Mais']);
  });
});

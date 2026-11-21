import { test, expect } from '@playwright/test';
import { abrir } from './ajudas.mjs';

const CHAVE = 'pbl-prototipo-confusoes';

test.describe('Roteiro e botão "Isto ficou confuso"', () => {
  test('Roteiro lista 4 caminhos', async ({ page }) => {
    await abrir(page, 'index.html');
    await expect(page.locator('ol.roteiro > li')).toHaveCount(4);
  });

  test('Anotar um ponto confuso e copiar o resumo', async ({ page }) => {
    await abrir(page, 'index.html');
    await page.locator('.topo-ferramentas [data-abrir-confuso]').click();
    await page.locator('#dlg-texto').fill('Não achei o Publicar');
    await page.getByRole('button', { name: 'Anotar' }).click();
    await expect(page.locator('#resumo-confuso')).toHaveValue(/Não achei o Publicar/);
    await expect(page.locator('#total-confuso')).toHaveText('1');
  });

  test('Anotação vazia é recusada com erro', async ({ page }) => {
    await abrir(page, 'index.html');
    await page.locator('.topo-ferramentas [data-abrir-confuso]').click();
    await page.getByRole('button', { name: 'Anotar' }).click();
    await expect(page.locator('#dlg-texto')).toHaveAttribute('aria-invalid', 'true');
  });

  test('Apagar anotações zera o resumo', async ({ page }) => {
    await page.addInitScript((c) => {
      if (!localStorage.getItem(c)) localStorage.setItem(c, JSON.stringify([{ tela: 'Início', texto: 'teste', quando: 'agora' }]));
    }, CHAVE);
    await abrir(page, 'index.html');
    await expect(page.locator('#total-confuso')).toHaveText('1');
    await page.locator('#apagar-anotacoes').click();
    await expect(page.locator('#total-confuso')).toHaveText('0');
  });
});

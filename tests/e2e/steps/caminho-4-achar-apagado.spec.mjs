import { test, expect } from '@playwright/test';
import { abrir, tocar } from './ajudas.mjs';

test('Buscar "Suíte" e recuperar a Suíte Antiga da lixeira', async ({ page }) => {
  await abrir(page, 'inicio.html');
  await page.locator('#busca-global').fill('Suíte');
  await page.locator('#busca-global').press('Enter');
  await expect(page.getByText('Suíte Antiga').first()).toBeVisible();
  await tocar(page, 'Recuperar');
  await tocar(page, 'Confirmar recuperação');
  await expect(page.getByText('Recuperado como Privado').first()).toBeVisible();
});

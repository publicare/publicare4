import { test, expect } from '@playwright/test';
import { abrir, tocar, preencher, abrirAba, esperarInvalido } from './ajudas.mjs';

test.describe('Trocar o texto de um quarto e publicar', () => {
  test('Editar, ver o aviso de não salvo e salvar', async ({ page }) => {
    await abrir(page, 'inicio.html');
    await tocar(page, 'Continuar: Suíte Master');
    await tocar(page, 'Editar');
    await preencher(page, 'titulo', 'Suíte Master Vista Mar');
    await expect(page.getByText('Alterações não salvas').first()).toBeVisible();
    await page.getByRole('button', { name: 'Salvar', exact: true }).first().click();
    await expect(page.getByText('Alterações não salvas').first()).toBeHidden();
  });

  test('Publicar a partir da aba Publicação', async ({ page }) => {
    await abrir(page, 'editar-pagina.html');
    await abrirAba(page, 'Publicação');
    await page.getByRole('button', { name: 'Publicar', exact: true }).first().click();
    await expect(page.locator('[data-selo-estado]').first()).toContainText('Publicado');
  });

  test('Contador de caracteres avisa ao passar do limite', async ({ page }) => {
    await abrir(page, 'editar-pagina.html');
    await preencher(page, 'titulo', 'x'.repeat(130));
    await esperarInvalido(page, 'titulo');
  });
});

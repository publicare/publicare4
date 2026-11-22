import { test, expect } from '@playwright/test';

const menuAberto = async (page) => {
  const b = page.locator('.btn-menu');
  if (await b.isVisible() && (await b.getAttribute('aria-expanded')) === 'false') await b.click();
};

test('Trocar o texto da Suíte Master e publicar', async ({ page }) => {
  await page.goto('conteudo-quartos.html');
  await page.locator('[data-titulo="Suíte Master"] a', { hasText: 'Editar' }).click();
  await page.locator('#texto').fill('Texto novo da suíte, com vista para o jardim.');
  await expect(page.locator('.estado-salvo')).toContainText('Alterações não salvas');
  await page.locator('[data-acao="salvar"]').click();
  await expect(page.locator('.estado-salvo')).toContainText('Salvo às');
  await page.locator('[data-acao="publicar"]').click();
  await expect(page.locator('.estado-salvo')).toContainText('Publicado');
});

test('Criar um evento com data de saída', async ({ page }) => {
  await page.goto('novo-evento.html');
  await page.locator('#titulo').fill('Jantar de inverno');
  await page.locator('#validade').check({ force: true });
  await expect(page.locator('#validade-campos')).toBeVisible();
  await page.locator('#tirar-do-ar').fill('2020-01-01T10:00');
  await page.locator('[data-acao="publicar"]').click();
  await expect(page.locator('#resumo-erros')).toBeVisible();
  await expect(page.locator('.estado-salvo')).toContainText('Não salvou');
});

test('Configurar o contato do site com e-mail inválido', async ({ page }) => {
  await page.goto('configuracoes.html#painel-contato');
  await page.locator('#email').fill('ana@');
  await page.locator('[data-acao="salvar"]').click();
  await expect(page.locator('#email-erro')).not.toBeEmpty();
  await expect(page.locator('.estado-salvo')).toContainText('Não salvou');
});

test('Achar a Suíte Antiga e recuperá-la', async ({ page }) => {
  await page.goto('buscar.html?q=Su%C3%ADte');
  const linha = page.locator('[data-titulo="Suíte Antiga"]');
  await linha.getByRole('button', { name: 'Recuperar', exact: true }).click();
  await linha.getByRole('button', { name: 'Recuperar como privada' }).click();
  await expect(linha.locator('[data-msg]')).toBeVisible();
  await expect(linha).toHaveAttribute('data-estado', 'privado');
});

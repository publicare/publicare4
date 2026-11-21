import { test, expect } from '@playwright/test';
import { abrir, preencher, sairDoCampo, esperarInvalido } from './ajudas.mjs';

test.describe('Configurar o contato do site', () => {
  test('E-mail inválido é apontado ao sair do campo', async ({ page }) => {
    await abrir(page, 'configuracoes.html#painel-contato');
    await preencher(page, 'email', 'isso-nao-e-email');
    await sairDoCampo(page);
    await esperarInvalido(page, 'email');
    await preencher(page, 'email', 'contato@exemplo.test');
    await sairDoCampo(page);
    await esperarInvalido(page, 'email', false);
  });

  test('As 5 abas existem e "Opções avançadas" começa recolhida', async ({ page }) => {
    await abrir(page, 'configuracoes.html');
    await expect(page.locator('[data-abas] a[data-aba]')).toHaveCount(5);
    const avancadas = page.locator('details', { hasText: 'Opções avançadas' }).first();
    await expect(avancadas).not.toHaveAttribute('open', '');
  });
});

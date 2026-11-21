// Passos reutilizáveis (linguagem do Gherkin) sobre a API do Playwright.
import { expect } from '@playwright/test';

export const abrir = (page, caminho) => page.goto(caminho);

export const tocar = (page, nome) =>
  page.getByRole('link', { name: nome }).or(page.getByRole('button', { name: nome })).first().click();

export const preencher = async (page, id, valor) => page.locator(`#${id}`).fill(valor);

export const sairDoCampo = (page) => page.keyboard.press('Tab');

export const abrirAba = (page, nome) => page.getByRole('link', { name: nome, exact: true }).first().click();

export const ligar = (page, rotulo) => page.getByLabel(rotulo).check();

export const esperarInvalido = (page, id, invalido = true) =>
  invalido
    ? expect(page.locator(`#${id}`)).toHaveAttribute('aria-invalid', 'true')
    : expect(page.locator(`#${id}`)).not.toHaveAttribute('aria-invalid', 'true');

export const semRolagemHorizontal = async (page) => {
  const sobra = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(sobra).toBeLessThanOrEqual(0);
};

import { test, expect } from '@playwright/test';

// Contra a prévia no ar (PBL_PREVIA_URL): só o que é público e não depende de ler o e-mail do servidor.
// O fluxo completo com o driver "arquivo" roda no PHPUnit (RecuperacaoDeAcessoPontaAPontaTest).
const BASE = process.env.PBL_PREVIA_URL;
test.skip(!BASE, 'defina PBL_PREVIA_URL para rodar no navegador');
test.use({ baseURL: BASE });

test('A resposta de esqueci é a mesma para conta que existe e que não existe', async ({ page }) => {
  const corpos = [];
  for (const id of ['admin', 'ninguem-' + Date.now()]) {
    await page.goto('/_pbl/esqueci');
    if ((await page.locator('input[name="identificador"]').count()) === 0) return; // e-mail não configurado: nada a comparar
    await page.fill('input[name="identificador"]', id);
    await page.getByRole('button').first().click();
    corpos.push((await page.locator('main').innerText()).trim());
  }
  expect(corpos[0]).toBe(corpos[1]);
});

test('As telas públicas de recuperação protegem contra script em linha e vazamento do link', async ({ page }) => {
  for (const c of ['/_pbl/esqueci', '/_pbl/esqueci-usuario', '/_pbl/entrar']) {
    const r = await page.goto(c);
    expect(r.headers()['content-security-policy']).not.toContain("'unsafe-inline'");
    await expect(page.locator('script:not([src])')).toHaveCount(0);
  }
  const r = await page.goto('/_pbl/redefinir-senha/' + 'a'.repeat(64));
  expect(r.status()).toBe(400);
  expect(r.headers()['referrer-policy']).toBe('no-referrer');
});

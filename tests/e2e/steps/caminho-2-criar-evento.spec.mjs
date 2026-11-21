import { test } from '@playwright/test';
import { abrir, preencher, abrirAba, ligar, sairDoCampo, esperarInvalido } from './ajudas.mjs';

async function prepararAgenda(page) {
  await abrir(page, 'editar-evento.html');
  await abrirAba(page, 'Publicação');
  await ligar(page, 'Agendar publicação');
}

test.describe('Criar um evento com data de saída', () => {
  test('Data de saída antes da data de entrada é rejeitada', async ({ page }) => {
    await abrir(page, 'editar-evento.html');
    await preencher(page, 'titulo', 'Festa de inverno');
    await abrirAba(page, 'Publicação');
    await ligar(page, 'Agendar publicação');
    await preencher(page, 'publicar-em', '2026-07-10T10:00');
    await preencher(page, 'tirar-do-ar', '2026-07-01T10:00');
    await sairDoCampo(page);
    await esperarInvalido(page, 'tirar-do-ar');
  });

  test('Corrigir a data e publicar', async ({ page }) => {
    await prepararAgenda(page);
    await preencher(page, 'publicar-em', '2026-07-01T10:00');
    await preencher(page, 'tirar-do-ar', '2026-07-10T10:00');
    await sairDoCampo(page);
    await esperarInvalido(page, 'tirar-do-ar', false);
  });
});

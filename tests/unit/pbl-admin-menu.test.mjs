import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { Script, createContext } from 'node:vm';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const CODIGO = readFileSync(join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'public', '_pbl', 'assets', 'pbl-admin.js'), 'utf8');

/** Roda o pbl-admin.js com um menu `<details open>` e um `matchMedia` falso; devolve o estado do menu. */
function menuApos({ largo, busca }) {
  const menu = { open: true };
  const doc = {
    querySelector: (s) => (s === '[data-pbl-menu]' ? menu : null),
    querySelectorAll: () => [],
    addEventListener() {},
    documentElement: { setAttribute() {}, getAttribute: () => null },
  };
  const janela = { location: { search: busca }, matchMedia: () => ({ matches: largo, addEventListener() {} }), addEventListener() {} };
  janela.window = janela;
  new Script(CODIGO, { filename: 'pbl-admin.js' }).runInContext(createContext({ document: doc, window: janela, matchMedia: janela.matchMedia, localStorage: { getItem: () => null, setItem() {} } }));
  return menu;
}

test('menu recolhe no celular e fica aberto no desktop', () => {
  assert.equal(menuApos({ largo: false, busca: '' }).open, false);
  assert.equal(menuApos({ largo: true, busca: '' }).open, true);
});
test('?menu=aberto mantém o menu aberto no celular (capturas da prévia)', () => {
  assert.equal(menuApos({ largo: false, busca: '?menu=aberto' }).open, true);
  assert.equal(menuApos({ largo: false, busca: '?tema=escuro&menu=aberto' }).open, true);
  assert.equal(menuApos({ largo: false, busca: '?menu=aberto2' }).open, false, 'só o valor exato vale');
});

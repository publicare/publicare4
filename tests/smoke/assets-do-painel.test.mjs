import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { Script } from 'node:vm';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const base = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'public', '_pbl', 'assets');
const js = ['pbl-formulario.js', 'pbl-js.js'];
const ler = (n) => readFileSync(join(base, n), 'utf8');

test('JS do painel tem sintaxe válida', () => {
  for (const n of js) new Script(ler(n), { filename: n });
});
test('JS do painel não usa eval, document.write nem innerHTML (CSP e XSS)', () => {
  for (const n of js) assert.doesNotMatch(ler(n), /\beval\s*\(|document\.write|\.innerHTML\s*=|insertAdjacentHTML|new Function/, n);
});
test('CSS de formulário não importa recurso externo', () => {
  assert.doesNotMatch(ler('pbl-formulario.css'), /https?:\/\/|@import/);
});
test('CSS cobre tema escuro, foco visível e movimento reduzido', () => {
  const css = ler('pbl-formulario.css');
  assert.match(css, /focus-visible/);
  assert.match(css, /prefers-reduced-motion|prefers-color-scheme|data-bs-theme|data-tema/);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const AQUI = dirname(fileURLToPath(import.meta.url));
const E2E = join(AQUI, '..');
const PROTO = join(E2E, '..', '..', 'prototipo-ux');
const ler = (p) => readFileSync(p, 'utf8');
const arquivos = (dir, ext) => readdirSync(dir).filter((f) => f.endsWith(ext)).map((f) => join(dir, f));
const html = () => arquivos(PROTO, '.html').map(ler).join('\n');

const cenarios = arquivos(join(E2E, 'features'), '.feature')
  .flatMap((f) => [...ler(f).matchAll(/^\s*Cenário: (.+)$/gm)].map((m) => m[1].trim()));
const specs = arquivos(join(E2E, 'steps'), '.spec.mjs').map(ler).join('\n');

test('há cenários Gherkin', () => assert.ok(cenarios.length >= 8));

for (const titulo of cenarios) {
  test(`cenário tem teste Playwright com o mesmo título: ${titulo}`, () => {
    assert.ok(specs.includes(`test('${titulo}'`), `sem spec para “${titulo}”`);
  });
}

test('todo id usado nos specs existe no protótipo', () => {
  const todos = new Set([...html().matchAll(/\sid="([^"]+)"/g)].map((m) => m[1]));
  const usados = new Set([...specs.matchAll(/locator\('#([a-z0-9-]+)'/g)].map((m) => m[1]));
  usados.add('menu-principal');
  for (const id of usados) assert.ok(todos.has(id), `id #${id} não existe no HTML`);
});

test('atributos de dados usados nos specs existem no JS/HTML', () => {
  const base = html() + ler(join(PROTO, 'assets', 'proto.js'));
  for (const a of new Set([...specs.matchAll(/\[(data-[a-z-]+)/g)].map((m) => m[1]))) {
    assert.ok(base.includes(a), `${a} não existe`);
  }
});

test('o protótipo não faz chamadas de rede', () => {
  for (const f of arquivos(join(PROTO, 'assets'), '.js')) {
    assert.doesNotMatch(ler(f), /fetch\(|XMLHttpRequest|WebSocket|sendBeacon|importScripts|(src|href)\s*=\s*['"]https?:/, f);
  }
});

test('config do Playwright cobre desktop e celulares de 390 e 360 px', () => {
  assert.ok(existsSync(join(E2E, 'playwright.config.mjs')));
  const c = ler(join(E2E, 'playwright.config.mjs'));
  for (const w of ['1280', '390', '360']) assert.ok(c.includes(w));
});

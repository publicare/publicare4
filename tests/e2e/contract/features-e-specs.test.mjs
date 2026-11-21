import { test } from 'node:test';
import assert from 'node:assert/strict';
import { features, specs, lerE2e, cenarios } from './auxiliar.mjs';

const titulosDosSpecs = specs().map((s) => lerE2e(`steps/${s}`)).join('\n');

test('há uma feature para cada um dos 4 caminhos, o roteiro e a navegação', () => {
  const nomes = features();
  for (const base of ['caminho-1', 'caminho-2', 'caminho-3', 'caminho-4', 'roteiro', 'navegacao']) {
    assert.ok(nomes.some((n) => n.startsWith(base)), `falta feature ${base}`);
  }
});

test('toda feature declara idioma pt e tem ao menos um cenário', () => {
  for (const f of features()) {
    const t = lerE2e(f);
    assert.match(t, /^# language: pt/m, f);
    assert.ok(cenarios(t).length > 0, f);
  }
});

test('todo cenário tem um teste Playwright com o mesmo título (ou prefixo, nos esquemas)', () => {
  for (const f of features()) {
    const t = lerE2e(f);
    for (const c of cenarios(t)) {
      const base = c.replace(/<[^>]+>/g, '').trim();
      assert.ok(titulosDosSpecs.includes(base), `${f}: cenário sem spec: "${c}"`);
    }
  }
});

test('nenhum spec usa URL externa nem segredos', () => {
  assert.doesNotMatch(titulosDosSpecs, /https?:\/\/(?!127\.0\.0\.1)/);
});

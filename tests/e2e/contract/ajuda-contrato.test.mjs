// Contrato do botão "?" (CSS/JS/marcação). Roda sem navegador: node --test.
import test from 'node:test';
import assert from 'node:assert/strict';
import { ler } from '../support/telas.mjs';

const css = ler('public/_pbl/assets/pbl-formulario.css');
const regra = (seletor) => {
  const i = css.search(new RegExp(`^${seletor.replace(/[.[\\]>()]/g, '\\$&')} \\{`, 'm'));
  assert.ok(i >= 0, `regra “${seletor}” não encontrada`);
  return css.slice(i, css.indexOf('}', i));
};
const rem = (decl, prop) => {
  const m = decl.match(new RegExp(`(?:^|[;{\\s])${prop}:\\s*([\\d.]+)rem`));
  return m ? parseFloat(m[1]) * 16 : null;
};

test('ícone visual do "?" mede entre 16 e 20 px', () => {
  const r = regra('details.pbl-ajuda > summary');
  for (const p of ['width', 'height']) {
    const px = rem(r, p);
    assert.ok(px !== null && px >= 16 && px <= 20, `${p}=${px}px`);
  }
});

test('área de toque ≥ 44 px vem de pseudo-elemento, sem alargar o layout', () => {
  const r = regra('details.pbl-ajuda > summary::after');
  assert.match(r, /position:\s*absolute/);
  const inset = r.match(/inset:\s*-(\d+)px/);
  assert.ok(inset, 'inset negativo ausente');
  const lado = Math.round(1.15 * 16) + 2 * Number(inset[1]);
  assert.ok(lado >= 44, `área ${lado}px`);
  assert.doesNotMatch(regra('details.pbl-ajuda > summary'), /\bpadding:/, 'padding alargaria a linha do rótulo');
});

test('foco visível e estado aberto definidos', () => {
  assert.match(css, /details\.pbl-ajuda > summary:focus-visible \{[^}]*outline/);
  assert.match(css, /details\.pbl-ajuda\[open\] > summary/);
});

test('painel da ajuda nunca flutua sobre o campo (sem position absolute/fixed)', () => {
  assert.doesNotMatch(regra('details.pbl-ajuda > .pbl-ajuda-texto'), /position:\s*(absolute|fixed)/);
  assert.match(css, /\.pbl-rotulo > details\.pbl-ajuda\[open\] \{[^}]*flex:\s*0 0 100%/);
});

test('CSS do "?" usa tokens do tema (claro e escuro), sem cor fixa', () => {
  const bloco = css.split('\n').filter((l) => l.includes('details.pbl-ajuda')).join('\n');
  assert.doesNotMatch(bloco, /#[0-9a-f]{3,8}\b|rgb\(/i);
});

test('Esc fecha a ajuda aberta e devolve o foco ao "?"', () => {
  const js = ler('public/_pbl/assets/pbl-js.js');
  assert.match(js, /e\.key !== 'Escape'/);
  assert.match(js, /closest\('details\.pbl-ajuda\[open\]'\)/);
  assert.match(js, /alvo\.open = false/);
  assert.match(js, /botao\.focus\(\)/);
});

test('Esc não está duplicado em outro script do painel', () => {
  assert.doesNotMatch(ler('public/_pbl/assets/pbl-admin.js'), /details\.pbl-ajuda\[open\]/);
});

test('CSS legado do "?" grande saiu de pbl.css', () => {
  const legado = ler('public/_pbl/assets/pbl.css');
  assert.doesNotMatch(legado, /\.pbl-ajuda-botao\s*\{[^}]*(2\.75rem|44px|var\(--pbl-toque\))/);
});

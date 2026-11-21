import test from 'node:test';
import assert from 'node:assert/strict';
import { carregar } from './extrair.mjs';
import { contraste } from '../../prototipo-ux/tests/auxiliar.mjs';

function elContador() {
  const classes = new Set();
  return { textContent: '', classList: { add: (c) => classes.add(c), remove: (...c) => c.forEach((x) => classes.delete(x)), has: (c) => classes.has(c) }, classes };
}
function ambiente() {
  const falas = [];
  const { atualizarContador } = carregar(['atualizarContador'], { anunciar: (t) => falas.push(t) });
  return { atualizarContador, falas };
}
const campoCom = (n, max) => ({ dataset: { max: String(max) }, value: 'x'.repeat(n), _contador: elContador() });

test('contador mostra n de max abaixo de 90%', () => {
  const { atualizarContador } = ambiente(); const c = campoCom(10, 60);
  atualizarContador(c);
  assert.equal(c._contador.textContent, '10 de 60');
});

test('contador avisa "Faltam" a partir de 90% e marca perto', () => {
  const { atualizarContador } = ambiente(); const c = campoCom(54, 60);
  atualizarContador(c);
  assert.equal(c._contador.textContent, 'Faltam 6');
  assert.ok(c._contador.classes.has('perto'));
});

test('contador passa do limite: marca, e anuncia só uma vez', () => {
  const { atualizarContador, falas } = ambiente(); const c = campoCom(63, 60);
  atualizarContador(c); atualizarContador(c);
  assert.equal(c._contador.textContent, 'Passou 3 do limite de 60');
  assert.ok(c._contador.classes.has('passou'));
  assert.equal(falas.length, 1);
  c.value = 'x'.repeat(10); atualizarContador(c);
  assert.ok(!c._contador.classes.has('passou'));
});

test('exatamente no limite não é "passou"', () => {
  const { atualizarContador } = ambiente(); const c = campoCom(60, 60);
  atualizarContador(c);
  assert.equal(c._contador.textContent, 'Faltam 0');
});

test('busca fake ignora acento e caixa', () => {
  const { semAcento } = carregar(['semAcento']);
  assert.equal(semAcento('Suíte Antiga'), 'suite antiga');
  assert.equal(semAcento('AÇÃO'), 'acao');
});

test('contraste WCAG: referências conhecidas', () => {
  assert.ok(Math.abs(contraste('#000000', '#ffffff') - 21) < 0.01);
  assert.ok(Math.abs(contraste('#777777', '#ffffff') - 4.48) < 0.02);
  assert.ok(contraste('#ffffff', '#ffffff') === 1);
});

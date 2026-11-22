import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const R = createRequire(import.meta.url)('../../prototipo-ux/assets/regras.js');
const v = (valor, r = {}) => R.validarValor(valor, { rotulo: 'Título', ...r });

test('obrigatório vazio pede o preenchimento com o rótulo; opcional vazio é válido', () => {
  assert.equal(v('   ', { obrigatorio: true }), 'Preencha “Título”.');
  assert.equal(v('', {}), '');
});
test('limite de caracteres diz quantos apagar', () => {
  assert.match(v('a'.repeat(65), { max: 60 }), /apague 5 caracteres/);
  assert.match(v('a'.repeat(61), { max: 60 }), /apague 1 caractere\./);
  assert.equal(v('a'.repeat(60), { max: 60 }), '');
});
test('e-mail: aceita completo, recusa incompleto', () => {
  assert.equal(v('a@b.com.br', { tipo: 'email' }), '');
  for (const ruim of ['a@b', 'a b@c.com', '@c.com', 'abc']) assert.notEqual(v(ruim, { tipo: 'email' }), '', ruim);
});
test('telefone: DDD e símbolos; recusa letras e tamanho absurdo', () => {
  assert.equal(v('(11) 3456-7890', { tipo: 'telefone' }), '');
  assert.notEqual(v('123', { tipo: 'telefone' }), '');
  assert.notEqual(v('11 abcd-7890', { tipo: 'telefone' }), '');
  assert.notEqual(v('1'.repeat(14), { tipo: 'telefone' }), '');
});
test('url e lista de urls: só http(s)', () => {
  assert.equal(v('https://exemplo.com.br', { tipo: 'url' }), '');
  for (const ruim of ['exemplo.com', 'javascript:alert(1)', 'ftp://x.com']) assert.notEqual(v(ruim, { tipo: 'url' }), '', ruim);
  assert.equal(v('https://a.com\nhttps://b.com\n\n', { tipo: 'urls' }), '');
  assert.match(v('https://a.com\nfacebook', { tipo: 'urls' }), /“facebook”/);
});
test('endereço (slug), analytics e inteiro com limites', () => {
  assert.equal(v('suite-master', { tipo: 'slug' }), '');
  for (const ruim of ['Suite', 'suíte', 'a b', '-a', 'a--b']) assert.notEqual(v(ruim, { tipo: 'slug' }), '', ruim);
  assert.equal(v('G-ABC_12', { tipo: 'analytics' }), '');
  assert.notEqual(v('G ABC', { tipo: 'analytics' }), '');
  assert.equal(v('10', { tipo: 'inteiro', min: 1, maxValor: 50 }), '');
  assert.match(v('0', { tipo: 'inteiro', min: 1, maxValor: 50 }), /menor valor é 1/);
  assert.match(v('51', { tipo: 'inteiro', min: 1, maxValor: 50 }), /maior valor é 50/);
  assert.notEqual(v('1,5', { tipo: 'inteiro' }), '');
});
test('saída do ar precisa ser depois da publicação', () => {
  assert.match(R.validarIntervalo('2027-12-10T10:00', '2027-12-09T10:00'), /depois da publicação/);
  assert.equal(R.validarIntervalo('2027-12-10T10:00', '2027-12-11T10:00'), '');
  assert.equal(R.validarIntervalo('', '2027-12-11T10:00'), '');
});
test('contador: normal, perto (90%), passou', () => {
  assert.deepEqual(R.contador(38, 120), { texto: '38 de 120', nivel: 'normal' });
  assert.deepEqual(R.contador(108, 120), { texto: 'Faltam 12', nivel: 'perto' });
  assert.deepEqual(R.contador(125, 120), { texto: 'Passou 5', nivel: 'passou' });
});
test('prévia corta com reticências; data em português; endereço sem acento', () => {
  assert.equal(R.cortar('abc', 5), 'abc');
  assert.equal(R.cortar('abcdefghij', 5), 'abcd…');
  assert.equal(R.dataBr('2027-12-31T23:59'), '31/12/2027 às 23:59');
  assert.equal(R.slugDe('Suíte Master!'), 'suite-master');
});
test('filtro da lista ignora acento e caixa e respeita o estado', () => {
  const i = { titulo: 'Suíte Antiga', estado: 'lixeira' };
  assert.ok(R.combina(i, 'suite', 'todos'));
  assert.ok(R.combina(i, 'ANTIGA', 'lixeira'));
  assert.ok(!R.combina(i, 'suite', 'publicado'));
  assert.ok(!R.combina(i, 'jazz', 'todos'));
});

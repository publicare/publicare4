import test from 'node:test';
import assert from 'node:assert/strict';
import { carregar, campo } from './extrair.mjs';

function m(irmaos) {
  const documento = { getElementById: (id) => irmaos?.[id] || null };
  const $ = () => ({ firstChild: { textContent: ' Título ' } });
  return carregar(['urlValida', 'ocultoPorRegra', 'rotuloDe', 'validarCampo'], { document: documento, $ });
}

test('obrigatório vazio pede o preenchimento com o rótulo', () => {
  const { validarCampo } = m();
  assert.equal(validarCampo(campo({ valor: '   ', dataset: { obrigatorio: '' } })), 'Preencha “Título”.');
});

test('campo opcional vazio é válido', () => {
  assert.equal(m().validarCampo(campo({ valor: '' })), '');
});

test('limite de caracteres diz quantos apagar', () => {
  const msg = m().validarCampo(campo({ valor: 'a'.repeat(65), dataset: { max: '60' } }));
  assert.match(msg, /apague 5 caracteres/);
});

test('e-mail: aceita completo, recusa incompleto', () => {
  const { validarCampo } = m();
  assert.equal(validarCampo(campo({ valor: 'a@b.com.br', dataset: { tipo: 'email' } })), '');
  for (const ruim of ['a@b', 'a b@c.com', '@c.com', 'abc']) assert.notEqual(validarCampo(campo({ valor: ruim, dataset: { tipo: 'email' } })), '', ruim);
});

test('telefone: DDD e símbolos; recusa letras e tamanho absurdo', () => {
  const { validarCampo } = m();
  assert.equal(validarCampo(campo({ valor: '(11) 3456-7890', dataset: { tipo: 'telefone' } })), '');
  assert.notEqual(validarCampo(campo({ valor: '123', dataset: { tipo: 'telefone' } })), '');
  assert.notEqual(validarCampo(campo({ valor: '11 abcd-7890', dataset: { tipo: 'telefone' } })), '');
  assert.notEqual(validarCampo(campo({ valor: '1'.repeat(14), dataset: { tipo: 'telefone' } })), '');
});

test('url: só http(s)', () => {
  const { validarCampo } = m();
  assert.equal(validarCampo(campo({ valor: 'https://exemplo.com.br/x', dataset: { tipo: 'url' } })), '');
  assert.notEqual(validarCampo(campo({ valor: 'javascript:alert(1)', dataset: { tipo: 'url' } })), '');
  assert.notEqual(validarCampo(campo({ valor: 'exemplo.com.br', dataset: { tipo: 'url' } })), '');
});

test('urls (uma por linha) aponta a linha ruim', () => {
  const msg = m().validarCampo(campo({ valor: 'https://a.com\nfoo\n', dataset: { tipo: 'urls' } }));
  assert.match(msg, /foo/);
});

test('slug e analytics', () => {
  const { validarCampo } = m();
  assert.equal(validarCampo(campo({ valor: 'suite-master', dataset: { tipo: 'slug' } })), '');
  for (const ruim of ['Suíte', 'a--b', '-a', 'a b']) assert.notEqual(validarCampo(campo({ valor: ruim, dataset: { tipo: 'slug' } })), '', ruim);
  assert.equal(validarCampo(campo({ valor: 'G-AB_12', dataset: { tipo: 'analytics' } })), '');
  assert.notEqual(validarCampo(campo({ valor: 'G 12', dataset: { tipo: 'analytics' } })), '');
});

test('inteiro respeita min e max', () => {
  const { validarCampo } = m();
  const mk = (v) => campo({ valor: v, dataset: { tipo: 'inteiro' }, attrs: { min: '1', max: '10' } });
  assert.equal(validarCampo(mk('5')), '');
  assert.match(validarCampo(mk('0')), /menor valor é 1/);
  assert.match(validarCampo(mk('11')), /maior valor é 10/);
  assert.match(validarCampo(mk('1.5')), /inteiro/);
});

test('saída do ar precisa ser depois da publicação', () => {
  const pub = { value: '2026-05-10T10:00', closest: () => null };
  const { validarCampo } = m({ pub });
  const saida = (v) => campo({ valor: v, dataset: { depoisDe: 'pub' } });
  assert.match(validarCampo(saida('2026-05-09T10:00')), /depois da publicação/);
  assert.match(validarCampo(saida('2026-05-10T10:00')), /depois da publicação/);
  assert.equal(validarCampo(saida('2026-05-11T10:00')), '');
});

test('campo oculto por regra não é validado; painel de aba (data-painel) é', () => {
  const { validarCampo } = m();
  assert.equal(validarCampo(campo({ valor: '', dataset: { obrigatorio: '' }, oculto: true })), '');
  const emPainel = campo({ valor: '', dataset: { obrigatorio: '' } });
  emPainel.closest = () => ({ hasAttribute: (n) => n === 'data-painel' });
  assert.notEqual(validarCampo(emPainel), '');
});

test('campo desabilitado ou hidden não gera erro', () => {
  const { validarCampo } = m();
  const d = campo({ dataset: { obrigatorio: '' } }); d.disabled = true;
  assert.equal(validarCampo(d), '');
  assert.equal(validarCampo(campo({ dataset: { obrigatorio: '' }, tipoInput: 'hidden' })), '');
});

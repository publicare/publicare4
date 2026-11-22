// Contrato da linha de conteúdo (ADR 0032): renderiza o componente real via PHP CLI e confere a marcação.
import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const AQUI = dirname(fileURLToPath(import.meta.url));
const h = JSON.parse(execFileSync('php', [join(AQUI, 'renderizar-linha.php')], { encoding: 'utf8' }));
const conta = (s, re) => (s.match(re) ?? []).length;

test('ícone da classe tem title e rótulo acessível com o nome da classe', () => {
  assert.match(h.completa, /class="pbl-icone" role="img" title="acomodacao" aria-label="Tipo: acomodacao"/);
  assert.match(h.completa, /<svg[^>]*aria-hidden="true"/);
});

test('metadados: classe · selo · data compacta · filhos, em uma linha', () => {
  assert.equal(conta(h.completa, /<p class="pbl-lc-meta">/g), 1);
  assert.match(h.completa, /<span class="pbl-lc-classe">acomodacao<\/span>/);
  assert.match(h.completa, /<span aria-hidden="true">22\/11 01:36<\/span>/);
  assert.match(h.completa, /title="Alterado em 22\/11\/2026 01:36"/);
  assert.match(h.completa, /3 filhos/);
});

test('sem ruído: nada de "Por:", "—" ou "0 filhos" quando não existe', () => {
  assert.doesNotMatch(h.vazia, /Por:|—|0 filhos|<time/);
  assert.doesNotMatch(h.vazia, /pbl-lc-sel/, 'sem seleção em lote quando não selecionável');
});

test('só 3 ações à vista; o resto no menu <details> sem JS, Apagar por último', () => {
  const [vista, menu] = h.completa.split('<details class="pbl-mais">');
  assert.equal(conta(vista, /class="pbl-lc-acao pbl-lc-acao--/g), 3);
  for (const r of ['Editar', 'Tirar do ar', 'Abrir no site']) assert.match(vista, new RegExp(`title="${r}"`));
  assert.match(menu, /aria-label="Mais ações: Varanda/);
  for (const r of ['Abrir pasta', 'Copiar', 'Recortar', 'Mover']) assert.match(menu, new RegExp(`<span>${r}</span>`));
  assert.ok(menu.lastIndexOf('Apagar') > menu.lastIndexOf('Mover'), 'Apagar no fim');
  assert.match(menu, /pbl-lc-item--perigo/);
  assert.doesNotMatch(h.completa, /<script|onclick=|style=/);
});

test('ação externa abre com rel=noopener e o POST leva CSRF', () => {
  assert.match(h.completa, /target="_blank" rel="noopener"/);
  assert.match(h.completa, /<form method="post" action="\/_pbl\/acao" class="pbl-lc-form"><input type="hidden" name="_csrf" value="tok">/);
});

test('XSS: título e nome da classe saem escapados', () => {
  assert.doesNotMatch(h.hostil, /<img src=x/);
  assert.match(h.hostil, /&lt;img src=x onerror=alert\(1\)&gt;/);
});

test('linha sem menu quando só há ações à vista', () => {
  assert.doesNotMatch(h.vazia, /pbl-mais/);
});

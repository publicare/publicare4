import { test } from 'node:test';
import assert from 'node:assert/strict';
import { lerProto, lerE2e, specs, ids, protoTem } from './auxiliar.mjs';

const js = lerProto('assets/proto.js');

test('a chave de localStorage do "Isto ficou confuso" é a mesma do JS e dos testes', () => {
  const chave = js.match(/CHAVE_CONFUSOES\s*=\s*'([^']+)'/)[1];
  assert.equal(chave, 'pbl-prototipo-confusoes');
  assert.ok(lerE2e('steps/roteiro-confuso.spec.mjs').includes(chave));
});

test('ganchos usados pelo JS e pelos specs existem no roteiro', () => {
  const html = lerProto('index.html');
  const presentes = ids(html);
  for (const id of ['resumo-confuso', 'total-confuso', 'copiar-resumo', 'apagar-anotacoes', 'status-resumo', 'busca-global']) {
    assert.ok(presentes.has(id), `index.html sem #${id}`);
  }
  assert.match(html, /data-abrir-confuso/);
  assert.match(html, /class="roteiro lista"/);
  assert.match(js, /id="dlg-texto"|id: 'dlg-texto'|dlg-texto/);
});

test('ids que os specs preenchem existem na tela correspondente', () => {
  const mapa = {
    'editar-pagina.html': ['titulo', 'publicar-em', 'tirar-do-ar', 'agendar', 'estado-publicado'],
    'editar-evento.html': ['titulo', 'publicar-em', 'tirar-do-ar', 'agendar', 'data-evento'],
    'configuracoes.html': ['email', 'telefone', 'redes'],
  };
  for (const [tela, lista] of Object.entries(mapa)) {
    const presentes = ids(lerProto(tela));
    for (const id of lista) assert.ok(presentes.has(id), `${tela} sem #${id}`);
  }
});

test('o JS não depende de rede, eval nem frameworks', () => {
  assert.doesNotMatch(js, /\b(fetch|XMLHttpRequest|eval|import\s*\()\b/);
  assert.doesNotMatch(js, /(src|href)\s*=\s*['"]https?:|\.src\s*=\s*['"]https?:|importScripts|sendBeacon|WebSocket/);
});

test('os specs só visitam telas que existem', () => {
  for (const s of specs()) {
    const t = lerE2e(`steps/${s}`);
    for (const m of t.matchAll(/'([\w-]+\.html)(?:[#?][^']*)?'/g)) assert.ok(protoTem(m[1]), `${s}: ${m[1]}`);
  }
});

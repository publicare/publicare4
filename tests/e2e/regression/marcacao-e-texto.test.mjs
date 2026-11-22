// Regressão sobre o HTML REAL do painel (renderizado por php, sem navegador): marcação da ajuda, CSP e texto enxuto.
import test from 'node:test';
import assert from 'node:assert/strict';
import { telas, texto, ajudas } from '../support/telas.mjs';

const todas = telas();
const nomes = Object.keys(todas);
const PRINCIPAIS = ['inicio', 'conteudo', 'edicao', 'configuracoes'];
const LIMITE_AJUDA = 140;
// Mesma lista de docs/ux/voz-e-texto.md.
const PROIBIDOS = [/aqui você pode/i, /esta tela/i, /é importante/i, /lembre-se/i, /\bbasta\b/i, /simplesmente/i, /por favor/i, /gostaríamos/i, /a fim de/i, /vale ressaltar/i];

test('o painel renderizou as telas esperadas', () => {
  for (const t of [...PRINCIPAIS, 'atualizacao', 'copias-de-seguranca', 'contas', 'entrar', 'lixeira']) assert.ok(nomes.includes(t), `faltou ${t}`);
});

test('todo "?" tem nome acessível "Ajuda: …", texto com id e role="note"', () => {
  let total = 0;
  for (const [tela, html] of Object.entries(todas)) {
    for (const a of ajudas(html)) {
      total++;
      assert.match(a, /<summary aria-label="Ajuda: [^"]+">/, `${tela}: summary sem nome`);
      assert.match(a, /<span aria-hidden="true">\?<\/span>/, `${tela}: glifo deve ficar escondido do leitor`);
      assert.match(a, /<div id="[^"]+" class="pbl-ajuda-texto" role="note">/, `${tela}: texto sem id/role`);
    }
  }
  assert.ok(total >= 10, `poucas ajudas (${total})`);
});

test('ids de ajuda são únicos por tela e todo aria-describedby aponta para um id existente', () => {
  for (const [tela, html] of Object.entries(todas)) {
    const ids = [...html.matchAll(/\sid="([^"]+)"/g)].map((m) => m[1]);
    const unicos = new Set(ids);
    const dup = ids.filter((id, i) => ids.indexOf(id) !== i && id.includes('ajuda'));
    assert.deepEqual(dup, [], `${tela}: ids de ajuda repetidos`);
    for (const m of html.matchAll(/aria-describedby="([^"]+)"/g)) {
      for (const id of m[1].split(/\s+/)) assert.ok(unicos.has(id), `${tela}: aria-describedby aponta para #${id} inexistente`);
    }
  }
});

test('CSP sem inline: nenhuma tela tem style=, on*= nem <script> com corpo', () => {
  for (const [tela, html] of Object.entries(todas)) {
    const semScripts = html.replace(/<script\b[^>]*\bsrc=[^>]*><\/script>/g, '');
    assert.doesNotMatch(semScripts, /<script\b[^>]*>[\s\S]*?\S[\s\S]*?<\/script>/, `${tela}: script inline`);
    assert.doesNotMatch(html, /\sstyle="/, `${tela}: style inline`);
    assert.doesNotMatch(html, /\son[a-z]+="/, `${tela}: manipulador inline`);
  }
});

test('o "?" funciona sem JavaScript: é <details>/<summary>, sem tabindex nem role de botão forçados', () => {
  for (const html of Object.values(todas)) for (const a of ajudas(html)) assert.doesNotMatch(a, /tabindex=|role="button"/);
});

test('telas principais: nenhum padrão proibido no texto visível', () => {
  for (const t of PRINCIPAIS) {
    const visivel = texto(todas[t]);
    for (const re of PROIBIDOS) assert.doesNotMatch(visivel, re, `${t}: ${re}`);
  }
});

test('onda 2 (demais telas): nenhum padrão proibido', () => {
  for (const t of nomes.filter((n) => !PRINCIPAIS.includes(n))) {
    const visivel = texto(todas[t]);
    for (const re of PROIBIDOS) assert.doesNotMatch(visivel, re, `${t}: ${re}`);
  }
});

test('telas principais: ajuda de campo cabe em uma linha (≤ 140 caracteres)', () => {
  for (const t of PRINCIPAIS) {
    for (const a of ajudas(todas[t])) {
      const id = a.match(/<div id="([^"]+)"/)[1];
      const corpo = texto(a.replace(/<summary[\s\S]*?<\/summary>/, ''));
      assert.ok(corpo.length <= LIMITE_AJUDA, `${t}#${id}: ${corpo.length} caracteres`);
    }
  }
});

test('avisos que evitam erro continuam nas telas', () => {
  assert.match(texto(todas['copias-de-seguranca']), /Não feche nem recarregue a página/);
  assert.match(texto(todas['lixeira']), /apagar de vez \(não desfaz\)/);
  assert.match(texto(todas['nova-conta']), /No mínimo 10 caracteres/);
});

test('o painel carrega o JavaScript do Esc como arquivo (pbl-js.js)', () => {
  assert.match(todas['configuracoes'], /<script src="\/_pbl\/assets\/pbl-js\.js/);
});

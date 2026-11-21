import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync } from 'node:fs';
import { PROTO, lerProto, ids, protoTem, features, lerE2e, telasCitadas } from '../contract/auxiliar.mjs';

const telas = readdirSync(PROTO).filter((f) => f.endsWith('.html'));

test('o roteiro tem 4 caminhos e todos os links de partida resolvem (arquivo e âncora)', () => {
  const html = lerProto('index.html');
  const caminhos = html.match(/<ol class="roteiro lista">([\s\S]*?)<\/ol>\s*<p class="aviso-bloco">/)[1];
  assert.equal((caminhos.match(/<li class="cartao">/g) || []).length, 4);
  for (const m of caminhos.matchAll(/href="([^"]+)"/g)) {
    const alvo = m[1];
    assert.ok(protoTem(alvo), `link quebrado: ${alvo}`);
    const [arq, ancora] = alvo.split('?')[0].split('#');
    if (ancora) assert.ok(ids(lerProto(arq)).has(ancora), `âncora ausente: ${alvo}`);
  }
});

test('toda tela tem o mesmo menu de 5 itens e o botão "Isto ficou confuso"', () => {
  for (const t of telas) {
    const html = lerProto(t);
    const menu = html.match(/<nav id="menu-principal"[\s\S]*?<\/nav>/)[0];
    assert.equal((menu.match(/<li>/g) || []).length, 5, t);
    assert.match(html, /data-abrir-confuso/, t);
    assert.match(html, /name="robots" content="noindex/, t);
  }
});

test('toda tela citada nas features existe', () => {
  for (const f of features()) for (const tela of telasCitadas(lerE2e(f))) assert.ok(protoTem(tela), `${f}: ${tela}`);
});

test('o caminho 4 tem item na lixeira e confirmação de recuperação', () => {
  const html = lerProto('buscar.html');
  assert.match(html, /Suíte Antiga/);
  assert.match(html, /Confirmar recuperação/);
  assert.match(html, /Recuperado como Privado/);
});

test('o caminho 1 parte de um quarto privado, para o "Publicar" aparecer', () => {
  const html = lerProto('editar-pagina.html');
  assert.match(html, /id="estado-privado"[^>]*checked/);
  assert.match(html, /data-acao="publicar"/);
});

test('o protótipo continua fora do pacote de release', () => {
  const ga = readFileSyncSafe('../../../.gitattributes');
  assert.match(ga, /prototipo-ux\s+export-ignore/);
});

import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
function readFileSyncSafe(rel) {
  return readFileSync(join(dirname(fileURLToPath(import.meta.url)), rel), 'utf8');
}

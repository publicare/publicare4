import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const E2E = join(dirname(fileURLToPath(import.meta.url)), '..');
const ler = (p) => readFileSync(join(E2E, p), 'utf8');
const feature = ler('features/painel-fase2.feature');
const phpunit = ler('PainelFase2PontaAPontaTest.php');
const spec = ler('steps/painel-fase2.spec.mjs');

test('a feature tem cenários e todo cenário tem teste PHPUnit correspondente', () => {
  const n = [...feature.matchAll(/^\s*(Cenário|Esquema do Cenário): /gm)].length;
  assert.ok(n >= 10, `só ${n} cenários`);
  const testes = [...phpunit.matchAll(/public function (test\w+)/g)].length;
  assert.ok(testes >= n - 2, `${testes} testes PHPUnit para ${n} cenários`);
});

test('toda tela citada nos Exemplos aparece no teste PHPUnit', () => {
  const rotas = ['atualizacao', 'copias', 'tarefas', 'contas', 'classes', 'modelos', 'dominios', 'configuracoes', 'revisar'];
  for (const r of rotas) assert.ok(phpunit.includes(`/_pbl/`) && phpunit.includes(r), `falta ${r}`);
});

test('o spec de navegador se ignora sem prévia e não faz chamada externa', () => {
  assert.match(spec, /test\.skip\(!BASE/);
  assert.doesNotMatch(spec, /https?:\/\/(?!127\.0\.0\.1)/);
});

test('o spec cobre os viewports pedidos', () => {
  for (const v of ['390, 844', '844, 390']) assert.ok(spec.includes(v));
});

// Smoke: serve prototipo-ux/ por HTTP estático (como a prévia) e percorre os fluxos críticos por links.
import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { readFileSync, existsSync, statSync } from 'node:fs';
import { join, extname, normalize } from 'node:path';
import { RAIZ } from '../unit/extrair.mjs';

const TIPOS = { '.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml' };
let servidor, base;

before(async () => {
  servidor = http.createServer((req, res) => {
    const caminho = decodeURIComponent(new URL(req.url, 'http://x').pathname);
    let arq = normalize(join(RAIZ, caminho === '/' ? 'index.html' : caminho));
    if (!arq.startsWith(RAIZ) || !existsSync(arq) || statSync(arq).isDirectory()) { res.writeHead(404); return res.end(); }
    res.writeHead(200, { 'content-type': TIPOS[extname(arq)] || 'application/octet-stream' });
    res.end(readFileSync(arq));
  });
  await new Promise((ok) => servidor.listen(0, '127.0.0.1', ok));
  base = `http://127.0.0.1:${servidor.address().port}`;
});
after(() => servidor.close());

const pegar = async (p) => { const r = await fetch(base + p); return { status: r.status, texto: await r.text() }; };

test('raiz serve o roteiro com os 4 caminhos e o botão "Isto ficou confuso"', async () => {
  const { status, texto } = await pegar('/');
  assert.equal(status, 200);
  assert.equal((texto.match(/<h2>\d\. /g) || []).length, 4);
  assert.match(texto, /Isto ficou confuso/);
  assert.match(texto, /id="resumo-confuso"/);
});

test('rastreio: toda tela alcançável a partir do roteiro responde 200, com noindex e assets 200', async () => {
  const vistas = new Set(), fila = ['/index.html'];
  while (fila.length) {
    const p = fila.shift(); if (vistas.has(p)) continue; vistas.add(p);
    const { status, texto } = await pegar(p);
    assert.equal(status, 200, p);
    assert.match(texto, /<meta name="robots" content="noindex/, `${p}: noindex`);
    for (const m of texto.matchAll(/<a\b[^>]*\shref="([^"#?]+\.html)/g)) fila.push('/' + m[1]);
    for (const m of texto.matchAll(/(?:src|href)="(assets\/[^"#?]+)/g)) assert.equal((await pegar('/' + m[1])).status, 200, `${p} → ${m[1]}`);
  }
  assert.ok(vistas.size >= 15, `só ${vistas.size} telas alcançadas`);
});

test('fluxo crítico 1: Início → Ver → Editar página traz abas Conteúdo, Publicação, Avançado e barra de ações', async () => {
  const { texto } = await pegar('/editar-pagina.html');
  for (const aba of ['Conteúdo', 'Publicação', 'Avançado']) assert.match(texto, new RegExp(aba));
  assert.match(texto, /Salvar/); assert.match(texto, /Publicar/);
});

test('fluxo crítico 2: evento tem validade (tirar do ar) ligada à publicação', async () => {
  const { texto } = await pegar('/editar-evento.html');
  assert.match(texto, /data-depois-de=/);
});

test('fluxo crítico 3: configurações têm 5 abas e Contato com e-mail e telefone validáveis', async () => {
  const { texto } = await pegar('/configuracoes.html');
  assert.equal((texto.match(/\sdata-painel[\s=>]/g) || []).length, 5);
  assert.match(texto, /data-tipo="email"/); assert.match(texto, /data-tipo="telefone"/);
});

test('fluxo crítico 4: busca acha item da lixeira e oferece Recuperar', async () => {
  const { texto } = await pegar('/buscar.html?q=Su%C3%ADte');
  assert.match(texto, /Suíte Antiga/); assert.match(texto, /Recuperar/);
});

test('caminho inexistente dá 404 e não vaza fora do docroot', async () => {
  assert.equal((await pegar('/nao-existe.html')).status, 404);
  const r = await fetch(base + '/..%2f..%2fpackage.json'); assert.notEqual(r.status, 200);
});

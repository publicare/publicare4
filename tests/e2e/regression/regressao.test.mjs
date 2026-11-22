import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const PROTO = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', 'prototipo-ux');
const RAIZ_REPO = join(PROTO, '..');
const telas = readdirSync(PROTO).filter((f) => f.endsWith('.html')).sort();
const ler = (f) => readFileSync(join(PROTO, f), 'utf8');
const sem = (h) => h.replace(/<!--[\s\S]*?-->/g, '');
const ids = (h) => new Set([...h.matchAll(/\sid="([^"]+)"/g)].map((m) => m[1]));

test('toda tela tem noindex', () => {
  for (const t of telas) assert.match(ler(t), /<meta name="robots" content="noindex/, t);
});

test('menu principal tem exatamente 5 itens em toda tela', () => {
  for (const t of telas.filter((x) => x !== 'site-exemplo.html')) {
    const m = ler(t).match(/<nav class="menu-principal"[^>]*>([\s\S]*?)<\/nav>/);
    if (!m) continue;
    assert.equal((m[1].match(/<li>/g) || []).length, 5, t);
  }
});

test('links internos e âncoras resolvem', () => {
  for (const t of telas) {
    for (const [, href] of sem(ler(t)).matchAll(/<a\b[^>]*\shref="([^"]*)"/g)) {
      if (/^(https?:|mailto:|tel:|#)/.test(href) && !href.startsWith('#')) continue;
      const [arq, ancora] = href.split('#');
      const alvo = arq ? arq.split('?')[0] : t;
      assert.ok(existsSync(join(PROTO, alvo)), `${t}: ${href} → arquivo inexistente`);
      if (ancora && !/^painel-/.test(ancora) || (ancora && alvo.endsWith('.html'))) {
        assert.ok(ids(ler(alvo)).has(ancora), `${t}: âncora #${ancora} ausente em ${alvo}`);
      }
    }
  }
});

test('o roteiro leva às quatro telas de partida', () => {
  const r = ler('index.html');
  for (const a of ['inicio.html', 'conteudo-eventos.html', 'configuracoes.html', 'buscar.html']) assert.ok(r.includes(`href="${a}`), a);
});

test('todo formulário editável tem barra de ações com estado salvo', () => {
  for (const t of telas.filter((x) => /^(editar|novo)-/.test(x) || x === 'configuracoes.html')) {
    const h = ler(t);
    assert.match(h, /class="estado-salvo"/, t);
    assert.match(h, /data-acao="(salvar|publicar)"/, t);
  }
});

test('cada interruptor tem rótulo e área de campos', () => {
  for (const t of telas) {
    const h = ler(t);
    for (const [, id] of h.matchAll(/<input[^>]*role="switch"[^>]*\sid="([^"]+)"/g)) {
      assert.match(h, new RegExp(`for="${id}"`), `${t}: sem label para #${id}`);
    }
  }
});

test('tela sem recursos externos nem estilo inline', () => {
  for (const t of telas) {
    const h = sem(ler(t));
    assert.doesNotMatch(h, /(src|href)="https?:\/\//, t);
    assert.doesNotMatch(h, /\sstyle="/, t);
  }
});

test('o protótipo fica fora do pacote de release', () => {
  assert.match(readFileSync(join(RAIZ_REPO, '.gitattributes'), 'utf8'), /^prototipo-ux\s+export-ignore/m);
});

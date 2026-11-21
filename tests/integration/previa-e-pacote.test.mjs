import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { RAIZ } from '../unit/raiz.mjs';

const REPO = join(RAIZ, '..');
const pedidoPath = join(REPO, '..', 'notas', 'previa-pedido.json');

test('pedido de prévia: estático, docroot prototipo-ux, entrada existente, sem PHP', { skip: !existsSync(pedidoPath) && 'pedido fora do jail (CI do repositório)' }, () => {
  const p = JSON.parse(readFileSync(pedidoPath, 'utf8'));
  assert.ok([1, 2].includes(p.versao));
  assert.equal(p.modo, 'estatico');
  assert.equal(p.raiz, 'prototipo-ux');
  assert.ok(existsSync(join(REPO, p.raiz, p.entrada)), 'entrada existe');
  for (const t of p.paginas) assert.ok(existsSync(join(REPO, p.raiz, t.caminho.replace(/^\//, '') || p.entrada)), `tela ${t.caminho}`);
});

test('o protótipo não tem PHP nem arquivos de servidor', () => {
  const varrer = (dir) => readdirSync(dir, { withFileTypes: true }).flatMap((e) => e.isDirectory() ? (e.name === 'node_modules' ? [] : varrer(join(dir, e.name))) : [join(dir, e.name)]);
  const ruins = varrer(RAIZ).filter((f) => /\.(php|phtml|htaccess)$/.test(f));
  assert.deepEqual(ruins, []);
});

test('protótipo fora do pacote de release (export-ignore) e ausente de app/ e public/', () => {
  assert.match(readFileSync(join(REPO, '.gitattributes'), 'utf8'), /^prototipo-ux\s+export-ignore/m);
  assert.ok(!existsSync(join(REPO, 'app', 'prototipo-ux')));
  assert.ok(!existsSync(join(REPO, 'public', 'prototipo-ux')));
});

test('todo CSS/JS referenciado existe localmente e é o mesmo conjunto em todas as telas', () => {
  const conjuntos = new Set();
  for (const f of readdirSync(RAIZ).filter((x) => x.endsWith('.html'))) {
    const html = readFileSync(join(RAIZ, f), 'utf8');
    const refs = [...html.matchAll(/(?:href|src)="(assets\/[^"#?]+)/g)].map((m) => m[1]);
    for (const r of refs) assert.ok(existsSync(join(RAIZ, r)), `${f}: ${r}`);
    conjuntos.add(refs.filter((r) => /\.(css|js)$/.test(r)).sort().join('|'));
  }
  assert.equal(conjuntos.size, 1, 'telas carregam conjuntos de assets diferentes');
});

test('o gerador reproduz exatamente os HTML versionados', async () => {
  const { execFileSync } = await import('node:child_process');
  const { createHash } = await import('node:crypto');
  const hash = () => Object.fromEntries(readdirSync(RAIZ).filter((x) => x.endsWith('.html')).map((f) => [f, createHash('sha1').update(readFileSync(join(RAIZ, f))).digest('hex')]));
  const antes = hash();
  execFileSync(process.execPath, [join(RAIZ, 'gerar', 'gerar.mjs')], { stdio: 'pipe' });
  assert.deepEqual(hash(), antes, 'HTML versionado diverge do gerador: rode npm run gerar');
});

test('guia visual e README acompanham o protótipo e a pasta não usa Bootstrap nem fontes externas', () => {
  assert.ok(existsSync(join(RAIZ, 'GUIA-VISUAL.md')));
  assert.ok(existsSync(join(RAIZ, 'README.md')));
  assert.ok(!existsSync(join(RAIZ, 'assets', 'bootstrap-5.3.8')));
  assert.ok(!/font-face|googleapis/.test(readFileSync(join(RAIZ, 'assets', 'proto.css'), 'utf8')));
});

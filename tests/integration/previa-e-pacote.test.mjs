import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { RAIZ } from '../unit/raiz.mjs';

const REPO = join(RAIZ, '..');
const pedidoPath = join(REPO, '..', 'notas', 'previa-pedido.json');

test('pedido de prévia: PHP v2, pacote gerado por script versionado, docroot dentro da pasta extraída, entrada logada pelas contas fictícias', { skip: !existsSync(pedidoPath) && 'pedido fora do jail (CI do repositório)' }, () => {
  const p = JSON.parse(readFileSync(pedidoPath, 'utf8'));
  assert.equal(p.versao, 2);
  assert.equal(p.modo, 'php');
  const gerar = p.preparo.find((a) => a.acao === 'gerar_pacote');
  const extrair = p.preparo.find((a) => a.acao === 'extrair_pacote');
  assert.ok(gerar && extrair, 'gerar_pacote seguido de extrair_pacote');
  assert.ok(existsSync(join(REPO, gerar.script)), 'script do pacote existe no repositório');
  assert.equal(extrair.de, gerar.para);
  assert.ok(p.docroot.startsWith(extrair.para + '/'), 'docroot dentro da pasta extraída');
  assert.ok(p.gravaveis.includes(extrair.para + '/dados'));
  assert.ok(p.paginas.length * p.viewports.length <= 16);
  assert.equal(new Set(p.paginas.map((t) => t.nome)).size, p.paginas.length, 'nomes únicos');
  for (const t of p.paginas) {
    assert.match(t.nome, /^[a-z0-9-]{1,40}$/);
    assert.ok(t.caminho.length <= 120, t.nome);
    assert.match(t.caminho, /^\/_pbl\/previa-entrar\?conta=(admin|editor|autor)&/, 'entra pela rota de prévia (a captura só faz GET)');
  }
  const temas = new Set(p.paginas.map((t) => /tema=(claro|escuro)/.exec(t.caminho)?.[1]));
  assert.deepEqual([...temas].sort(), ['claro', 'escuro']);
  assert.ok(p.viewports.some((v) => v.largura === 390), 'viewport de celular (390 px)');
  assert.ok(p.viewports.some((v) => v.largura >= 1024), 'viewport de desktop');
  assert.ok(p.paginas.some((t) => t.caminho.includes('menu=aberto')), 'captura do menu aberto (o JS recolhe o menu no celular)');
  for (const tela of ['/_pbl/&', '/_pbl/conteudo/', '/_pbl/objeto/1/editar?aba=publicacao', '/_pbl/mais']) {
    assert.ok(p.paginas.some((t) => t.caminho.includes('ir=' + tela)), 'tela crítica ' + tela);
  }
});

test('gerador do pacote instala as dependências de produção quando o clone não traz vendor/', () => {
  const g = readFileSync(join(REPO, 'previa', 'gerar-pacote.php'), 'utf8');
  assert.match(g, /composer install --no-dev/);
  assert.match(g, /'composer\.lock'/);
});

test('gerador do pacote grava o marcador do modo prévia e o aceita só em dados/', () => {
  const g = readFileSync(join(REPO, 'previa', 'gerar-pacote.php'), 'utf8');
  assert.match(g, /ModoPrevia::MARCADOR/);
  assert.match(g, /\$stage \. '\/dados\/'/);
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

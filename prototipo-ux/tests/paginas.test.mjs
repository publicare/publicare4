import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { paginas, ler, existe, semComentarios, ids, hrefs, tags, attr, contraste, tokens } from './auxiliar.mjs';

const TODAS = paginas();
const ROTEIRO = ['inicio.html', 'conteudo.html', 'ver.html', 'editar-pagina.html', 'editar-evento.html', 'editar.html', 'criar.html', 'buscar.html', 'configuracoes.html', 'revisar.html', 'mais.html', 'contas.html', 'peles.html', 'atualizacao.html', 'copias.html'];

test('as telas pedidas existem', () => {
  for (const t of ['index.html', ...ROTEIRO]) assert.ok(TODAS.includes(t), `falta ${t}`);
});

test('links internos e âncoras não estão quebrados', () => {
  for (const arq of TODAS) {
    for (const h of hrefs(semComentarios(ler(arq)))) {
      if (/^(https?:|mailto:)/.test(h)) continue;
      const [alvo, ancora] = h.replace(/\?[^#]*/, '').split('#');
      const destino = alvo || arq;
      assert.ok(existe(destino), `${arq}: link para ${h} aponta para arquivo inexistente`);
      if (ancora) assert.ok(ids(ler(destino)).includes(ancora), `${arq}: âncora #${ancora} não existe em ${destino}`);
    }
  }
});

test('ids são únicos em cada página', () => {
  for (const arq of TODAS) {
    const lista = ids(semComentarios(ler(arq)));
    const repetidos = lista.filter((x, i) => lista.indexOf(x) !== i);
    assert.deepEqual(repetidos, [], `${arq}: ids repetidos`);
  }
});

test('todo campo de formulário tem rótulo associado', () => {
  for (const arq of TODAS) {
    const html = semComentarios(ler(arq));
    const rotulos = new Set([...html.matchAll(/<label\b[^>]*\sfor="([^"]*)"/g)].map((m) => m[1]));
    for (const nome of ['input', 'select', 'textarea']) {
      for (const a of tags(html, nome)) {
        if (['hidden', 'submit', 'button'].includes(attr(a, 'type'))) continue;
        const id = attr(a, 'id');
        assert.ok(id && (rotulos.has(id) || attr(a, 'aria-label')), `${arq}: <${nome} ${a.trim()}> sem rótulo`);
      }
    }
  }
});

test('rótulos apontam para campos que existem', () => {
  for (const arq of TODAS) {
    const html = semComentarios(ler(arq));
    const todos = ids(html);
    for (const m of html.matchAll(/<label\b[^>]*\sfor="([^"]*)"/g)) assert.ok(todos.includes(m[1]), `${arq}: label for="${m[1]}" sem campo`);
    for (const m of html.matchAll(/aria-describedby="([^"]*)"/g)) for (const id of m[1].split(/\s+/)) assert.ok(todos.includes(id), `${arq}: aria-describedby="${id}" sem alvo`);
  }
});

test('sem style=, on*=, script inline, CDN; com noindex e idioma', () => {
  for (const arq of TODAS) {
    const html = semComentarios(ler(arq));
    assert.ok(!/\sstyle=/.test(html), `${arq}: style inline`);
    assert.ok(!/\son[a-z]+=/i.test(html), `${arq}: manipulador inline`);
    for (const m of html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/g)) assert.ok(/\ssrc="assets\//.test(m[1]) && !m[2].trim(), `${arq}: script inline ou externo`);
    assert.ok(!/(?:src|href)="https?:\/\//.test(html.replace(/<a\b[^>]*>/g, '')), `${arq}: recurso externo`);
    assert.match(html, /<meta name="robots" content="noindex/, `${arq}: sem noindex`);
    assert.match(html, /<html lang="pt-BR"/, `${arq}: sem lang`);
    assert.equal((html.match(/<h1\b/g) || []).length, 1, `${arq}: precisa de um h1`);
    assert.match(html, /<main id="principal"/, `${arq}: sem main`);
  }
});

test('o JavaScript compila e não usa rede nem eval', () => {
  const js = ler('assets/proto.js');
  assert.doesNotThrow(() => new vm.Script(js));
  assert.ok(!/\bfetch\(|XMLHttpRequest|\beval\(|document\.write|new Function/.test(js));
});

test('o menu tem os mesmos 5 itens em todas as telas', () => {
  for (const arq of TODAS) {
    const nav = ler(arq).match(/<nav id="menu-principal"[\s\S]*?<\/nav>/)[0];
    const rotulos = [...nav.matchAll(/<a href="[^"]+"[^>]*>([^<]+)/g)].map((m) => m[1].trim());
    assert.deepEqual(rotulos, ['Início', 'Conteúdo', 'Revisar', 'Site', 'Mais'], arq);
  }
});

test('migalhas terminam no título sem link', () => {
  for (const arq of TODAS.filter((a) => a !== 'index.html')) {
    const html = ler(arq);
    const m = html.match(/<nav class="migalhas"[\s\S]*?<\/nav>/);
    assert.ok(m, `${arq}: sem migalhas`);
    assert.match(m[0], /<li><span aria-current="page">[^<]+<\/span><\/li><\/ol>/, `${arq}: último item deve ser texto`);
  }
});

test('edição tem 3 abas e configurações tem 5, com "Opções avançadas"', () => {
  for (const a of ['editar.html', 'editar-pagina.html', 'editar-evento.html']) {
    const h = ler(a);
    assert.equal((h.match(/data-aba="/g) || []).length, 3, a);
    for (const t of ['Conteúdo', 'Publicação', 'Avançado']) assert.ok(h.includes(`>${t}<`), `${a}: aba ${t}`);
    assert.match(h, /class="barra-acoes"/);
  }
  const c = ler('configuracoes.html');
  assert.equal((c.match(/data-aba="/g) || []).length, 5);
  assert.match(c, /<summary>Opções avançadas<\/summary>/);
});

test('campos reais do evento e da notícia', () => {
  const e = ler('editar-evento.html');
  for (const r of ['Data e hora do evento', 'Local', 'Vagas', 'Link de inscrição', 'Tirar do ar a partir de', 'Publicar a partir de']) assert.ok(e.includes(r), r);
  assert.ok(ler('editar.html').includes('Texto da notícia'));
});

// Fluxos sem JavaScript: tudo se alcança só por links, a partir do roteiro.
test('sem JavaScript, o roteiro alcança todas as telas por links', () => {
  const visto = new Set(['index.html']), fila = ['index.html'];
  while (fila.length) {
    const arq = fila.shift();
    for (const h of hrefs(ler(arq))) {
      const alvo = h.replace(/[?#].*$/, '');
      if (alvo.endsWith('.html') && existe(alvo) && !visto.has(alvo)) { visto.add(alvo); fila.push(alvo); }
    }
  }
  for (const t of TODAS) assert.ok(visto.has(t), `${t} não é alcançável a partir de index.html`);
});

test('os 4 caminhos do roteiro têm ponto de partida e destino', () => {
  const idx = ler('index.html');
  for (const h of ['inicio.html', 'conteudo-eventos.html', 'configuracoes.html#painel-contato', 'buscar.html?q=']) assert.ok(idx.includes(`href="${h}`), h);
  assert.ok(ler('inicio.html').includes('Continuar: Suíte Master'));
  assert.ok(ler('ver.html').includes('href="editar-pagina.html"'));
  assert.ok(ler('buscar.html').includes('Suíte Antiga'));
  assert.ok(ler('criar.html').includes('href="editar-evento.html"'));
  assert.ok(ler('index.html').includes('Isto ficou confuso'));
});

test('contraste AA dos tokens nos dois temas', () => {
  const { claro, escuro } = tokens(ler('assets/proto.css'));
  const texto = [
    ['--pbl-cor-texto', '--pbl-cor-superficie'], ['--pbl-cor-texto', '--pbl-cor-campo'], ['--pbl-cor-ajuda', '--pbl-cor-superficie'], ['--pbl-cor-ajuda', '--pbl-cor-campo'],
    ['--pbl-cor-erro', '--pbl-cor-erro-fundo'], ['--pbl-cor-erro', '--pbl-cor-superficie'], ['--pbl-cor-sucesso', '--pbl-cor-sucesso-fundo'], ['--pbl-cor-sucesso', '--pbl-cor-superficie'],
    ['--pbl-cor-aviso', '--pbl-cor-aviso-fundo'], ['--pbl-cor-aviso', '--pbl-cor-superficie'], ['--pbl-cor-neutro', '--pbl-cor-neutro-fundo'], ['--pbl-cor-link', '--pbl-cor-superficie'],
    ['--pbl-cor-link', '--pbl-cor-campo'], ['--pbl-cor-botao-texto', '--pbl-cor-botao'],
  ];
  const componentes = [['--pbl-cor-borda-campo', '--pbl-cor-superficie'], ['--pbl-cor-borda-campo', '--pbl-cor-campo'], ['--pbl-cor-foco', '--pbl-cor-superficie'], ['--pbl-cor-foco', '--pbl-cor-campo'], ['--pbl-cor-botao', '--pbl-cor-superficie']];
  for (const [nome, t] of [['claro', claro], ['escuro', escuro]]) {
    for (const [a, b] of texto) assert.ok(contraste(t[a], t[b]) >= 4.5, `${nome}: ${a} × ${b} = ${contraste(t[a], t[b]).toFixed(2)} (< 4,5)`);
    for (const [a, b] of componentes) assert.ok(contraste(t[a], t[b]) >= 3, `${nome}: ${a} × ${b} = ${contraste(t[a], t[b]).toFixed(2)} (< 3)`);
  }
});

test('cabeçalho escuro: texto e foco legíveis', () => {
  const css = ler('assets/proto.css');
  assert.match(css, /\.topo \{ background: #212529; color: #f8f9fa/);
  assert.ok(contraste('#f8f9fa', '#212529') >= 4.5);
  assert.ok(contraste('#ffda6a', '#212529') >= 4.5);
  assert.ok(contraste('#dee2e6', '#212529') >= 4.5);
  assert.ok(contraste('#6ea8fe', '#212529') >= 3);
  assert.ok(contraste('#332701', '#ffda6a') >= 4.5);
});

test('alvos de toque, foco e reflow previstos no CSS', () => {
  const css = ler('assets/proto.css');
  assert.match(css, /--pbl-toque-minimo: 44px/);
  assert.match(css, /:focus-visible \{ outline: var\(--pbl-foco-espessura\) solid var\(--pbl-cor-foco\)/);
  assert.match(css, /prefers-reduced-motion/);
  assert.match(css, /safe-area-inset-bottom/);
  assert.match(css, /position: sticky; bottom: 0/);
});

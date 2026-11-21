import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { paginas, ler, existe, semComentarios, ids, hrefs, tags, attr, contraste, tokens } from './auxiliar.mjs';

const TODAS = paginas();
const TELAS = ['index.html', 'guia.html', 'inicio.html', 'conteudo.html', 'conteudo-quartos.html', 'conteudo-eventos.html', 'conteudo-novidades.html', 'ver.html', 'criar.html',
  'editar-pagina.html', 'editar-noticia.html', 'editar-evento.html', 'novo-pagina.html', 'novo-noticia.html', 'novo-evento.html', 'buscar.html', 'configuracoes.html', 'revisar.html',
  'mais.html', 'contas.html', 'conta.html', 'peles.html', 'atualizacao.html', 'copias.html', 'site-exemplo.html'];
const FORMULARIOS = TODAS.filter((a) => /^(editar|novo)-/.test(a));

test('as telas pedidas existem e não há tela sobrando', () => {
  for (const t of TELAS) assert.ok(TODAS.includes(t), `falta ${t}`);
  assert.deepEqual(TODAS.filter((t) => !TELAS.includes(t)), []);
});

test('links internos e âncoras não estão quebrados', () => {
  for (const arq of TODAS) {
    for (const h of hrefs(semComentarios(ler(arq)))) {
      if (/^(https?:|mailto:)/.test(h) || h === '#') continue;
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
    assert.deepEqual(lista.filter((x, i) => lista.indexOf(x) !== i), [], `${arq}: ids repetidos`);
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

test('rótulos e aria-describedby/controls apontam para ids que existem', () => {
  for (const arq of TODAS) {
    const html = semComentarios(ler(arq));
    const todos = ids(html);
    for (const m of html.matchAll(/<label\b[^>]*\sfor="([^"]*)"/g)) assert.ok(todos.includes(m[1]), `${arq}: label for="${m[1]}" sem campo`);
    for (const m of html.matchAll(/aria-(?:describedby|controls|labelledby)="([^"]*)"/g)) for (const id of m[1].split(/\s+/)) assert.ok(todos.includes(id), `${arq}: aria "${id}" sem alvo`);
  }
});

test('sem style=, on*=, script inline, CDN; com noindex, idioma, um h1 e main', () => {
  for (const arq of TODAS) {
    const html = semComentarios(ler(arq));
    assert.ok(!/\sstyle=/.test(html), `${arq}: style inline`);
    assert.ok(!/\son[a-z]+=/i.test(html), `${arq}: manipulador inline`);
    for (const m of html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/g)) assert.ok(/\ssrc="assets\//.test(m[1]) && !m[2].trim(), `${arq}: script inline ou externo`);
    assert.ok(!/(?:src|href)="(?:https?:)?\/\//.test(html.replace(/<a\b[^>]*>/g, '')), `${arq}: recurso externo`);
    assert.match(html, /<meta name="robots" content="noindex/, `${arq}: sem noindex`);
    assert.match(html, /<html lang="pt-BR"/, `${arq}: sem lang`);
    assert.equal((html.match(/<h1\b/g) || []).length, 1, `${arq}: precisa de um h1`);
    assert.match(html, /<main id="principal"/, `${arq}: sem main`);
  }
});

test('o JavaScript compila e não usa rede, eval nem innerHTML', () => {
  for (const f of ['assets/proto.js', 'assets/regras.js', 'assets/tema.js']) {
    const js = ler(f);
    assert.doesNotThrow(() => new vm.Script(js), f);
    assert.ok(!/\bfetch\(|XMLHttpRequest|\beval\(|document\.write|new Function|innerHTML|insertAdjacentHTML/.test(js), `${f}: API proibida`);
  }
});

test('o CSS não importa nada de fora nem usa gradiente ou sombra', () => {
  const css = ler('assets/proto.css');
  assert.ok(!/@import|url\(\s*["']?https?:|\/\/cdn/.test(css));
  assert.ok(!/gradient\(|box-shadow|text-shadow/.test(css), 'visual deve ser plano: sem gradiente nem sombra');
});

test('o menu tem os mesmos 5 itens e a mesma ordem em todas as telas', () => {
  for (const arq of TODAS) {
    const nav = ler(arq).match(/<nav class="menu-principal" id="menu-principal"[\s\S]*?<\/nav>/)[0];
    const rotulos = [...nav.matchAll(/<a href="[^"]+"[^>]*>([^<]+)/g)].map((m) => m[1].trim());
    assert.deepEqual(rotulos, ['Início', 'Conteúdo', 'Revisar', 'Site', 'Mais'], arq);
  }
});

test('cada tela tem "Isto ficou confuso", busca e migalhas terminando em texto', () => {
  for (const arq of TODAS) {
    const html = ler(arq);
    assert.match(html, /data-confuso>Isto ficou confuso</, `${arq}: sem botão`);
    assert.match(html, /<form class="busca" role="search"/, `${arq}: sem busca`);
    const m = html.match(/<nav class="migalhas"[\s\S]*?<\/nav>/);
    assert.ok(m, `${arq}: sem migalhas`);
    assert.match(m[0], /<li><span aria-current="page">[^<]+<\/span><\/li><\/ol>/, `${arq}: último item deve ser texto`);
  }
});

test('uma ação principal por tela (fora barra de ações e diálogos)', () => {
  for (const arq of TODAS) {
    const html = semComentarios(ler(arq)).replace(/<dialog[\s\S]*?<\/dialog>/, '');
    const principais = [...html.matchAll(/<(?:a|button)\b(?![^>]*data-(?:efeito|acao))[^>]*class="btn btn--principal[^"]*"/g)].length;
    const limite = { 'index.html': 1, 'guia.html': 9, 'revisar.html': 2 }[arq] ?? (FORMULARIOS.includes(arq) ? 3 : 1);
    assert.ok(principais <= limite, `${arq}: ${principais} botões principais`);
  }
});

test('edição: 3 abas, toggles com explicação, barra fixa e estado de salvamento', () => {
  for (const a of FORMULARIOS) {
    const h = ler(a);
    assert.equal((h.match(/<nav class="abas" data-abas[\s\S]*?<\/nav>/)[0].match(/<a /g) || []).length, 3, a);
    for (const t of ['Conteúdo', 'Publicação', 'Avançado']) assert.ok(h.includes(`>${t}</a>`), `${a}: aba ${t}`);
    assert.match(h, /class="barra-acoes"/, a);
    assert.match(h, /role="status" data-estado="/, a);
    for (const t of ['Agendar publicação', 'Ter data de validade', 'Personalizar para buscadores (SEO)', 'Usar outro visual só nesta página']) assert.ok(h.includes(t), `${a}: toggle ${t}`);
  }
});

test('todo interruptor tem título, explicação e campos controlados', () => {
  for (const arq of TODAS) {
    const html = ler(arq);
    for (const m of html.matchAll(/<input type="checkbox" role="switch" id="([^"]+)"[^>]*>/g)) {
      const id = m[1];
      assert.ok(html.includes(`<span class="opcao-ajuda" id="${id}-aj">`), `${arq}: interruptor ${id} sem explicação`);
      assert.match(html, new RegExp(`<label class="opcao-texto" for="${id}"><span class="opcao-titulo">[^<]+</span>`), `${arq}: ${id} sem título`);
      if (m[0].includes('aria-controls')) assert.ok(html.includes(`id="${id}-campos"`), `${arq}: ${id} sem campos`);
    }
  }
  assert.equal((ler('configuracoes.html').match(/role="switch"/g) || []).length >= 7, true);
});

test('configurações: 5 abas, "Opções avançadas" recolhido e salvar por aba', () => {
  const c = ler('configuracoes.html');
  assert.equal((c.match(/<nav class="abas"[\s\S]*?<\/nav>/)[0].match(/<a /g) || []).length, 5);
  assert.match(c, /<details class="recolhido"><summary><span>Opções avançadas/);
  assert.match(c, /data-edicao="abas-independentes"/);
  assert.match(c, /data-rotulo-salvar>Salvar identidade/);
});

test('campos reais do evento e da notícia', () => {
  const e = ler('novo-evento.html');
  for (const r of ['Data e hora do evento', 'Local', 'Quantas vagas', 'Endereço da inscrição', 'Tirar do ar a partir de', 'Publicar a partir de']) assert.ok(e.includes(r), r);
  assert.ok(ler('editar-noticia.html').includes('Texto da notícia'));
});

test('formulário: largura de leitura e seções com título e descrição', () => {
  for (const a of [...FORMULARIOS, 'configuracoes.html', 'conta.html']) {
    const h = ler(a);
    assert.match(h, /class="tela tela--form"/, `${a}: coluna de formulário`);
    const secoes = [...h.matchAll(/<section class="secao"[\s\S]*?<\/section>/g)];
    assert.ok(secoes.length >= 2, `${a}: poucas seções`);
    for (const s of secoes) assert.match(s[0], /<div class="secao-cab"><h2 id="[^"]+">[^<]+<\/h2>/, `${a}: seção sem título`);
  }
  const css = ler('assets/proto.css');
  assert.match(css, /--pbl-coluna-form: 680px/);
});

// Sem JavaScript: tudo se alcança só por links, a partir do roteiro.
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
  for (const h of ['inicio.html', 'conteudo-eventos.html', 'configuracoes.html', 'buscar.html?q=']) assert.ok(idx.includes(`href="${h}`), h);
  assert.ok(ler('inicio.html').includes('Continuar: Suíte Master'));
  assert.ok(ler('ver.html').includes('href="editar-pagina.html"'));
  assert.ok(ler('buscar.html').includes('Suíte Antiga'));
  assert.ok(ler('criar.html').includes('href="novo-evento.html"'));
  assert.ok(ler('index.html').includes('Isto ficou confuso'));
});

test('nenhum conteúdo exige JavaScript para ficar legível: campos de interruptor não usam hidden no HTML', () => {
  for (const a of TODAS) assert.ok(!/<div class="opcao-campos"[^>]* hidden/.test(ler(a)), a);
});

test('contraste AA dos tokens nos dois temas', () => {
  const { claro, escuro } = tokens(ler('assets/proto.css'));
  const texto = [
    ['--pbl-texto', '--pbl-fundo'], ['--pbl-texto', '--pbl-superficie'], ['--pbl-texto', '--pbl-suave'], ['--pbl-texto-2', '--pbl-fundo'], ['--pbl-texto-2', '--pbl-superficie'], ['--pbl-texto-2', '--pbl-suave'],
    ['--pbl-acao', '--pbl-fundo'], ['--pbl-acao', '--pbl-superficie'], ['--pbl-acao', '--pbl-suave'], ['--pbl-acao', '--pbl-acao-suave'], ['--pbl-sobre-acao', '--pbl-acao'], ['--pbl-texto', '--pbl-acao-suave'],
    ['--pbl-erro', '--pbl-erro-fundo'], ['--pbl-erro', '--pbl-superficie'], ['--pbl-ok', '--pbl-ok-fundo'], ['--pbl-ok', '--pbl-superficie'],
    ['--pbl-aviso', '--pbl-aviso-fundo'], ['--pbl-aviso', '--pbl-superficie'], ['--pbl-aviso', '--pbl-fundo'], ['--pbl-erro', '--pbl-fundo'],
  ];
  const componentes = [['--pbl-borda', '--pbl-superficie'], ['--pbl-borda', '--pbl-fundo'], ['--pbl-acao', '--pbl-superficie'], ['--pbl-acao', '--pbl-fundo'], ['--pbl-texto-2', '--pbl-suave'], ['--pbl-borda', '--pbl-suave']];
  for (const [nome, t] of [['claro', claro], ['escuro', escuro]]) {
    for (const [a, b] of texto) assert.ok(contraste(t[a], t[b]) >= 4.5, `${nome}: ${a} × ${b} = ${contraste(t[a], t[b]).toFixed(2)} (< 4,5)`);
    for (const [a, b] of componentes) assert.ok(contraste(t[a], t[b]) >= 3, `${nome}: ${a} × ${b} = ${contraste(t[a], t[b]).toFixed(2)} (< 3)`);
  }
});

test('os valores do guia visual (GUIA-VISUAL.md) batem com o CSS', () => {
  const { claro, escuro } = tokens(ler('assets/proto.css'));
  const guia = ler('GUIA-VISUAL.md');
  for (const [nome, t] of [['claro', claro], ['escuro', escuro]]) for (const [k, v] of Object.entries(t)) if (/^--pbl-(fundo|superficie|suave|linha|borda|texto|texto-2|acao|sobre-acao|acao-suave|erro|erro-fundo|aviso|aviso-fundo|ok|ok-fundo)$/.test(k)) assert.ok(guia.includes('`' + v + '`'), `${nome}: ${k} ${v} não está no guia`);
});

test('alvos de toque, foco e reflow previstos no CSS', () => {
  const css = ler('assets/proto.css');
  assert.match(css, /--pbl-toque: 44px/);
  assert.match(css, /:focus-visible \{ outline: 3px solid var\(--pbl-acao\)/);
  assert.match(css, /prefers-reduced-motion/);
  assert.match(css, /safe-area-inset-bottom/);
  assert.match(css, /\.barra-acoes \{ position: sticky; bottom: 0/);
  assert.match(css, /scroll-padding-bottom/);
});

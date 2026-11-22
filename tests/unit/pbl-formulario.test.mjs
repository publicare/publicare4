// Comportamento de public/_pbl/assets/pbl-formulario.js (ADR 0031): roda o script de verdade sobre um DOM mínimo (tests/unit/dom-falso.mjs).
import test from 'node:test';
import assert from 'node:assert/strict';
import { el, emitir, carregar } from './dom-falso.mjs';

const campo = (attrs, rotulo = {}) => el('div', { class: 'mb-3' }, el('input', attrs), el('span', { 'data-pbl-msg': '', id: 'msg-' + (attrs.id ?? attrs.name), ...rotulo }));

function formulario(...filhos) {
  const f = el('form', { 'data-pbl-form': '' }, ...filhos, el('span', { 'data-pbl-estado': '', 'data-estado': 'salvo' }, 'Salvo'));
  return f;
}
const estadoDe = (form) => form.querySelector('[data-pbl-estado]');

// ------------------------------------------------------------------ abas
function abas() {
  const b1 = el('button', { role: 'tab', 'data-pbl-aba': 'conteudo' });
  const b2 = el('button', { role: 'tab', 'data-pbl-aba': 'publicacao' });
  const b3 = el('button', { role: 'tab', 'data-pbl-aba': 'avancado' });
  const nav = el('div', { 'data-pbl-abas-nav': '', hidden: '' }, b1, b2, b3);
  const p1 = el('div', { class: 'pbl-aba-painel', id: 'aba-painel-conteudo' }, el('input', { id: 'campo-titulo', name: 'titulo', required: '' }));
  const p2 = el('div', { class: 'pbl-aba-painel', id: 'aba-painel-publicacao' });
  const p3 = el('div', { class: 'pbl-aba-painel', id: 'aba-painel-avancado' });
  const raiz = el('div', { 'data-pbl-abas-form': '', 'data-pbl-aba-inicial': 'publicacao' }, nav, p1, p2, p3);
  return { raiz, nav, botoes: [b1, b2, b3], paineis: [p1, p2, p3] };
}

test('abas: nav aparece só com JS, aba inicial ativa, só um painel com data-ativa', () => {
  const a = abas();
  const amb = carregar(formulario(a.raiz));
  assert.equal(a.nav.hidden, false);
  assert.deepEqual(a.botoes.map((b) => b.getAttribute('aria-selected')), ['false', 'true', 'false']);
  assert.deepEqual(a.paineis.map((p) => p.hasAttribute('data-ativa')), [false, true, false]);
  assert.deepEqual(a.botoes.map((b) => b.tabIndex), [-1, 0, -1], 'tabindex móvel (roving)');
  assert.match(amb.window.historico.at(-1), /aba=publicacao/);
  assert.doesNotMatch(amb.window.historico.at(-1), /salvo=/, 'tira o aviso "salvo" da URL');
});

test('abas: clique e setas/Home/End trocam a aba e movem o foco', () => {
  const a = abas();
  const amb = carregar(formulario(a.raiz));
  emitir(a.botoes[2], 'click');
  assert.equal(a.paineis[2].hasAttribute('data-ativa'), true);
  const seta = emitir(a.botoes[2], 'keydown', { key: 'ArrowRight' });
  assert.equal(seta.defaultPrevented, true);
  assert.equal(a.botoes[0].getAttribute('aria-selected'), 'true', 'dá a volta');
  assert.equal(amb.document.activeElement, a.botoes[0]);
  emitir(a.botoes[0], 'keydown', { key: 'ArrowLeft' });
  assert.equal(a.botoes[2].getAttribute('aria-selected'), 'true');
  emitir(a.botoes[2], 'keydown', { key: 'Home' });
  assert.equal(a.botoes[0].getAttribute('aria-selected'), 'true');
  emitir(a.botoes[0], 'keydown', { key: 'End' });
  assert.equal(a.botoes[2].getAttribute('aria-selected'), 'true');
  assert.equal(emitir(a.botoes[2], 'keydown', { key: 'a' }).defaultPrevented, false, 'outras teclas não são engolidas');
});

// ------------------------------------------------------------------ interruptores
function toggle(inicial, extra = {}) {
  const caixa = el('input', { type: 'checkbox', checked: 'x' });
  caixa.checked = true; // sem JS a caixa nasce marcada
  const campos = el('div', { 'data-pbl-toggle-campos': '' }, el('input', { name: 'validade', id: 'campo-validade' }));
  const raiz = el('div', { 'data-pbl-toggle': '', 'data-pbl-inicial': inicial, ...extra }, caixa, campos);
  return { raiz, caixa, campos };
}

test('interruptor: volta ao estado de verdade, esconde os campos e os mostra ao ligar', () => {
  const t = toggle('desligado');
  carregar(formulario(t.raiz));
  assert.equal(t.caixa.checked, false);
  assert.equal(t.campos.hidden, true);
  t.caixa.checked = true;
  emitir(t.caixa, 'change');
  assert.equal(t.campos.hidden, false);
  t.caixa.checked = false;
  emitir(t.caixa, 'change');
  assert.equal(t.campos.hidden, true);
});

test('interruptor ligado de início mantém campos visíveis; data-pbl-valor preserva a caixa', () => {
  const ligado = toggle('ligado');
  const comValor = toggle('desligado', { 'data-pbl-valor': '' });
  carregar(formulario(ligado.raiz, comValor.raiz));
  assert.equal(ligado.campos.hidden, false);
  assert.equal(comValor.caixa.checked, true, 'não mexe na caixa quando o valor vem do servidor');
  assert.equal(comValor.campos.hidden, false);
});

// ------------------------------------------------------------------ contador
test('contador: texto, nível ok/perto/passou e ligação por aria-describedby', () => {
  const area = el('textarea', { id: 'campo-descricao', maxlength: '10', 'data-pbl-contador': '' });
  const f = formulario(el('div', { class: 'mb-3' }, area));
  carregar(f);
  const c = area.parentElement.querySelector('.pbl-contador');
  assert.ok(c);
  assert.equal(c.textContent, '0 de 10 caracteres');
  assert.equal(c.getAttribute('data-nivel'), 'ok');
  assert.match(area.getAttribute('aria-describedby'), new RegExp(c.id));
  area.value = 'abcdefghi';
  emitir(area, 'input');
  assert.equal(c.getAttribute('data-nivel'), 'perto');
  area.value = 'abcdefghijk';
  emitir(area, 'input');
  assert.equal(c.textContent, '11 de 10 caracteres');
  assert.equal(c.getAttribute('data-nivel'), 'passou');
});

test('contador com limite recomendado (SEO) usa o limite bom, não o máximo', () => {
  const area = el('textarea', { id: 'campo-dados-seo_titulo', maxlength: '5000', 'data-pbl-limite-bom': '60', 'data-pbl-contador': '' });
  carregar(formulario(el('div', { class: 'mb-3' }, area)));
  assert.equal(area.parentElement.querySelector('.pbl-contador').textContent, '0 de 60 recomendados');
});

test('contador sem limite não cria nada', () => {
  const area = el('textarea', { 'data-pbl-contador': '' });
  carregar(formulario(el('div', { class: 'mb-3' }, area)));
  assert.equal(area.parentElement.querySelector('.pbl-contador'), null);
});

// ------------------------------------------------------------------ validação ao vivo
function formDeValidacao() {
  const titulo = campo({ id: 'campo-titulo', name: 'titulo', required: '' });
  const url = campo({ id: 'campo-url', name: 'url' });
  const peso = campo({ id: 'campo-peso', name: 'peso', value: '0' });
  const f = formulario(titulo, url, peso);
  return { f, titulo: titulo.querySelector('input'), url: url.querySelector('input'), peso: peso.querySelector('input'), msg: (c) => c.parentElement.querySelector('[data-pbl-msg]') };
}

test('validação: obrigatório, endereço e peso ao sair do campo; limpa ao corrigir', () => {
  const v = formDeValidacao();
  carregar(v.f);
  emitir(v.titulo, 'blur');
  assert.equal(v.titulo.getAttribute('aria-invalid'), 'true');
  assert.equal(v.msg(v.titulo).textContent, 'Este campo é obrigatório.');
  assert.match(v.titulo.getAttribute('aria-describedby'), /msg-campo-titulo/);

  v.titulo.value = 'Olá';
  emitir(v.titulo, 'input');
  assert.equal(v.titulo.hasAttribute('aria-invalid'), false, 'corrige ao digitar depois do erro');
  assert.equal(v.msg(v.titulo).textContent, '');
  assert.equal(v.titulo.hasAttribute('aria-describedby'), false);

  v.url.value = 'Meu Endereço';
  emitir(v.url, 'blur');
  assert.match(v.msg(v.url).textContent, /letras minúsculas/);
  v.url.value = 'pasta/meu-endereco-2';
  emitir(v.url, 'input');
  emitir(v.url, 'blur');
  assert.equal(v.url.hasAttribute('aria-invalid'), false);

  v.peso.value = '1,5';
  emitir(v.peso, 'blur');
  assert.match(v.msg(v.peso).textContent, /número inteiro/);
  v.peso.value = '-5';
  emitir(v.peso, 'blur');
  assert.equal(v.peso.hasAttribute('aria-invalid'), false);
});

test('validação: acima do maxlength avisa o limite', () => {
  const t = campo({ id: 'campo-titulo', name: 'titulo', maxlength: '5' });
  carregar(formulario(t));
  const i = t.querySelector('input');
  i.value = 'abcdefg';
  emitir(i, 'blur');
  assert.equal(t.querySelector('[data-pbl-msg]').textContent, 'Passou do limite de 5 caracteres.');
});

test('envio inválido: bloqueia, abre a aba e o interruptor do campo e põe o foco nele', () => {
  const a = abas();
  a.paineis[0].children[0].value = 'Título ok';
  const t = toggle('desligado');
  const alvo = t.campos.children[0];
  alvo.setAttribute('required', '');
  a.paineis[2].appendChild(t.raiz);
  const f = formulario(a.raiz);
  const amb = carregar(f);
  assert.equal(a.paineis[2].hasAttribute('data-ativa'), false);
  assert.equal(emitir(f, 'submit').defaultPrevented, false, 'campo dentro de interruptor desligado não conta');
  t.caixa.checked = true;
  emitir(t.caixa, 'change');
  const ev = emitir(f, 'submit');
  assert.equal(ev.defaultPrevented, true);
  assert.equal(a.paineis[2].hasAttribute('data-ativa'), true, 'abriu a aba do campo com erro');
  assert.equal(amb.document.activeElement, alvo);
  assert.equal(alvo.getAttribute('aria-invalid'), 'true');
  assert.equal(estadoDe(f).textContent, 'Corrija o campo marcado e salve de novo');
});

test('envio inválido: o primeiro campo com erro recebe o foco', () => {
  const v = formDeValidacao();
  const amb = carregar(v.f);
  const ev = emitir(v.f, 'submit');
  assert.equal(ev.defaultPrevented, true);
  assert.equal(amb.document.activeElement, v.titulo);
});

test('envio válido segue sem bloquear e some o aviso ao sair', () => {
  const v = formDeValidacao();
  v.titulo.value = 'Ok';
  const amb = carregar(v.f);
  emitir(v.f, 'input');
  const ev = emitir(v.f, 'submit');
  assert.equal(ev.defaultPrevented, false);
  assert.equal(estadoDe(v.f).textContent, 'Salvando…');
  const sair = amb.window.ouvintes.beforeunload[0]({ preventDefault() { throw new Error('não devia avisar ao enviar'); } });
  assert.equal(sair, undefined);
});

// ------------------------------------------------------------------ aviso ao sair
test('aviso ao sair: só depois de alterar; marca o estado e pede confirmação ao navegador', () => {
  const v = formDeValidacao();
  const amb = carregar(v.f);
  const sair = () => { const e = { defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, returnValue: undefined }; amb.window.ouvintes.beforeunload[0](e); return e; };
  assert.equal(sair().defaultPrevented, false, 'sem mudança não incomoda');
  v.titulo.value = 'Novo';
  emitir(v.f, 'input');
  assert.equal(estadoDe(v.f).textContent, 'Alterações não salvas');
  const e = sair();
  assert.equal(e.defaultPrevented, true);
  assert.equal(e.returnValue, '');
  assert.equal(estadoDe(v.f).getAttribute('data-estado'), 'saindo');
  amb.temporizadores.at(-1).fn();
  assert.equal(estadoDe(v.f).getAttribute('data-estado'), 'alterado', 'volta ao rótulo normal se a pessoa ficou');
});

test('aviso ao sair: página já renderizada como "alterado" (erro 422) conta como suja', () => {
  const v = formDeValidacao();
  estadoDe(v.f).setAttribute('data-estado', 'alterado');
  const amb = carregar(v.f);
  const e = { preventDefault() { this.p = true; } };
  amb.window.ouvintes.beforeunload[0](e);
  assert.equal(e.p, true);
});

// ------------------------------------------------------------------ resumo de erros
test('resumo de erros: ganha foco e o link abre a aba e foca o campo', () => {
  const a = abas();
  const link = el('a', { href: '#campo-titulo' }, 'Título');
  const resumo = el('div', { 'data-pbl-resumo-erros': '', tabindex: '-1' }, link);
  const amb = carregar(formulario(resumo, a.raiz));
  assert.equal(amb.document.activeElement, resumo);
  assert.equal(a.paineis[0].hasAttribute('data-ativa'), false);
  const ev = emitir(link, 'click');
  assert.equal(ev.defaultPrevented, true);
  assert.equal(a.paineis[0].hasAttribute('data-ativa'), true);
  assert.equal(amb.document.activeElement.id, 'campo-titulo');
});

// ------------------------------------------------------------------ endereço e prévia de busca
function previa() {
  const titulo = el('input', { id: 'campo-titulo', name: 'titulo' });
  const url = el('input', { id: 'campo-url', name: 'url' });
  const seoT = el('input', { id: 'campo-dados-seo_titulo' });
  const desc = el('textarea', { id: 'campo-descricao' });
  const endereco = el('span', { 'data-pbl-endereco-previa': '', 'data-pbl-base': 'https://exemplo.org' });
  const raiz = el('div', { 'data-pbl-previa-busca': '', 'data-pbl-base': 'https://exemplo.org', hidden: '' },
    el('span', { 'data-pbl-previa-url': '' }), el('span', { 'data-pbl-previa-titulo': '' }), el('span', { 'data-pbl-previa-texto': '' }), el('span', { 'data-pbl-previa-corte': '' }));
  const f = formulario(el('div', { class: 'mb-3' }, titulo), el('div', { class: 'mb-3' }, url), seoT, desc, endereco, raiz);
  return { f, titulo, url, seoT, desc, endereco, raiz, parte: (n) => raiz.querySelector('[data-pbl-previa-' + n + ']') };
}

test('prévia: aparece, usa o título e gera o endereço sem acento a partir dele', () => {
  const p = previa();
  carregar(p.f);
  assert.equal(p.raiz.hidden, false);
  p.titulo.value = 'Ação  Rápida: Só Hoje!';
  emitir(p.titulo, 'input');
  assert.equal(p.parte('url').textContent, 'https://exemplo.org/acao-rapida-so-hoje');
  assert.equal(p.parte('titulo').textContent, 'Ação  Rápida: Só Hoje!');
  assert.equal(p.endereco.textContent, 'https://exemplo.org/acao-rapida-so-hoje');
  p.url.value = 'minha-pagina';
  emitir(p.url, 'input');
  assert.equal(p.parte('url').textContent, 'https://exemplo.org/minha-pagina', 'endereço digitado vence o gerado');
});

test('prévia: título SEO vence o título e passa de 60 avisa o corte com reticências', () => {
  const p = previa();
  carregar(p.f);
  p.titulo.value = 'curto';
  p.seoT.value = 'x'.repeat(70);
  emitir(p.seoT, 'input');
  assert.equal(p.parte('titulo').textContent, 'x'.repeat(59) + '…');
  assert.equal(p.parte('corte').textContent, 'Pode ser cortado nas buscas.');
  p.seoT.value = 'ok';
  emitir(p.seoT, 'input');
  assert.equal(p.parte('corte').textContent, '');
});

test('prévia: texto de rede social só com texto (marcação digitada nunca vira elemento)', () => {
  const p = previa();
  const amb = carregar(p.f);
  const malicioso = '<img src=x onerror=alert(1)><script>alert(1)</script>';
  p.titulo.value = malicioso;
  p.desc.value = '<b>negrito</b>';
  emitir(p.titulo, 'input');
  const todosOsFilhos = (n) => [n, ...n.children.flatMap(todosOsFilhos)];
  const tags = todosOsFilhos(p.raiz).map((n) => n.tagName);
  assert.deepEqual(tags.filter((t) => ['IMG', 'SCRIPT', 'B'].includes(t)), [], 'nenhum elemento vindo do texto digitado');
  const social = p.raiz.querySelector('[data-pbl-previa-social]');
  assert.ok(social);
  assert.equal(social.querySelector('.pbl-previa-titulo').textContent, malicioso);
  assert.equal(p.parte('texto').textContent, '<b>negrito</b>');
  emitir(p.titulo, 'input');
  assert.equal(p.raiz.querySelectorAll('[data-pbl-previa-social]').length, 1, 'reconstrói, não acumula');
  assert.ok(amb);
});

// ------------------------------------------------------------------ miniatura de imagem
function imagem() {
  const entrada = el('input', { type: 'file', 'data-pbl-imagem': '' });
  const img = el('img');
  const nome = el('span', { 'data-pbl-miniatura-nome': '' });
  const area = el('div', { 'data-pbl-miniatura': '', hidden: '' }, img, nome);
  return { entrada, img, nome, area, f: formulario(el('div', { class: 'mb-3' }, entrada, area)) };
}

test('miniatura: imagem escolhida mostra pré-visualização com nome e tamanho; troca revoga a anterior', () => {
  const i = imagem();
  const amb = carregar(i.f);
  i.entrada.files = [{ type: 'image/png', name: 'foto<b>.png', size: 2048 }];
  emitir(i.entrada, 'change');
  assert.equal(i.area.hidden, false);
  assert.equal(i.img.src, 'blob:fake/1');
  assert.equal(i.nome.textContent, 'foto<b>.png (2 KB)');
  i.entrada.files = [{ type: 'image/jpeg', name: 'pequena.jpg', size: 10 }];
  emitir(i.entrada, 'change');
  assert.deepEqual(amb.blobs.revogados, ['blob:fake/1']);
  assert.equal(i.nome.textContent, 'pequena.jpg (1 KB)', 'mínimo de 1 KB');
});

test('miniatura: arquivo que não é imagem ou escolha cancelada esconde a área', () => {
  const i = imagem();
  carregar(i.f);
  i.entrada.files = [{ type: 'application/pdf', name: 'a.pdf', size: 1 }];
  emitir(i.entrada, 'change');
  assert.equal(i.area.hidden, true);
  i.entrada.files = [];
  emitir(i.entrada, 'change');
  assert.equal(i.area.hidden, true);
});

// ------------------------------------------------------------------ seletor com busca
function seletor(n) {
  const opcoes = Array.from({ length: n }, (_, k) => el('option', { value: String(k) }, k === 3 ? '#3 · Contato' : `#${k} · Página ${k}`));
  opcoes[5].selected = true;
  const sel = el('select', { 'data-pbl-seletor': '' }, ...opcoes);
  return { sel, opcoes, f: formulario(el('div', { class: 'mb-3' }, sel)) };
}

test('seletor com busca: lista longa ganha campo de busca acessível que filtra e mantém o selecionado', () => {
  const s = seletor(12);
  carregar(s.f);
  const busca = s.sel.parentElement.querySelector('.pbl-seletor-busca');
  assert.ok(busca);
  assert.equal(busca.getAttribute('aria-label'), 'Buscar na lista');
  assert.equal(s.sel.parentElement.children[0], busca, 'antes do select');
  busca.value = 'CONTATO';
  emitir(busca, 'input');
  assert.deepEqual(s.opcoes.filter((o) => !o.hidden).map((o) => o.value), ['3', '5'], 'acha sem diferenciar maiúsculas e não esconde o escolhido');
  busca.value = '';
  emitir(busca, 'input');
  assert.equal(s.opcoes.filter((o) => o.hidden).length, 0);
});

test('seletor com busca: lista curta fica como está (sem JS extra)', () => {
  const s = seletor(6);
  s.opcoes[5].selected = true;
  carregar(s.f);
  assert.equal(s.sel.parentElement.querySelector('.pbl-seletor-busca'), null);
});

test('seletor com busca: grupo sem opção visível some', () => {
  const o = [el('option', {}, 'alfa'), el('option', {}, 'beta')];
  const grupo = el('optgroup', {}, ...o);
  const resto = Array.from({ length: 7 }, (_, k) => el('option', {}, 'item ' + k));
  const sel = el('select', { 'data-pbl-seletor': '' }, grupo, ...resto);
  carregar(formulario(el('div', { class: 'mb-3' }, sel)));
  const busca = sel.parentElement.querySelector('.pbl-seletor-busca');
  busca.value = 'item 1';
  emitir(busca, 'input');
  assert.equal(grupo.hidden, true);
});

// ------------------------------------------------------------------ páginas sem formulário
test('página sem formulário nem componentes: o script não falha', () => {
  carregar(el('main', {}, el('p', {}, 'oi')));
});

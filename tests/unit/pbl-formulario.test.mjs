import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { carregar, evento } from './dom-falso.mjs';

const CODIGO = readFileSync(join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'public', '_pbl', 'assets', 'pbl-formulario.js'), 'utf8');
const pagina = (html, opcoes) => carregar(CODIGO, html, opcoes);

/* ---------------------------------------------------------------- abas */
const ABAS = `<form data-pbl-form><div data-pbl-abas-form data-pbl-aba-inicial="publicacao">
  <ul data-pbl-abas-nav hidden>
    <li><button type="button" role="tab" data-pbl-aba="conteudo" id="b1">Conteúdo</button></li>
    <li><button type="button" role="tab" data-pbl-aba="publicacao" id="b2">Publicação</button></li>
    <li><button type="button" role="tab" data-pbl-aba="avancado" id="b3">Avançado</button></li></ul>
  <div class="pbl-aba-painel" id="aba-painel-conteudo"><div class="mb-3"><input id="campo-titulo" name="titulo" required maxlength="60"><span data-pbl-msg id="msg-titulo"></span></div></div>
  <div class="pbl-aba-painel" id="aba-painel-publicacao" data-ativa></div>
  <div class="pbl-aba-painel" id="aba-painel-avancado"></div></div></form>`;

test('abas: JS mostra a barra, abre a aba inicial e marca só ela como selecionada', () => {
  const p = pagina(ABAS);
  assert.equal(p.$('[data-pbl-abas-nav]').hidden, false);
  assert.deepEqual(p.$$('button[role="tab"]').map((b) => b.getAttribute('aria-selected')), ['false', 'true', 'false']);
  assert.deepEqual(p.$$('button[role="tab"]').map((b) => b.tabIndex), [-1, 0, -1], 'roving tabindex');
  assert.deepEqual(p.$$('.pbl-aba-painel').map((x) => x.hasAttribute('data-ativa')), [false, true, false]);
});
test('abas: clique troca o painel e grava ?aba= no endereço sem recarregar (e tira ?salvo)', () => {
  const p = pagina(ABAS);
  p.$('#b3').dispatchEvent(evento('click'));
  assert.equal(p.$('#aba-painel-avancado').hasAttribute('data-ativa'), true);
  assert.equal(p.$('#aba-painel-publicacao').hasAttribute('data-ativa'), false);
  const ultima = new URL(p.janela.history.chamadas.at(-1));
  assert.equal(ultima.searchParams.get('aba'), 'avancado');
  assert.equal(ultima.searchParams.has('salvo'), false);
});
test('abas: setas, Home e End movem o foco com volta circular; outras teclas não fazem nada', () => {
  const p = pagina(ABAS);
  const tecla = (id, key) => { const ev = evento('keydown', { key }); p.$(id).dispatchEvent(ev); return ev; };
  let ev = tecla('#b2', 'ArrowRight');
  assert.equal(ev.defaultPrevented, true);
  assert.equal(p.doc.activeElement, p.$('#b3'));
  tecla('#b3', 'ArrowRight');
  assert.equal(p.doc.activeElement, p.$('#b1'), 'depois da última volta à primeira');
  tecla('#b1', 'ArrowLeft');
  assert.equal(p.doc.activeElement, p.$('#b3'), 'antes da primeira vai para a última');
  tecla('#b3', 'Home');
  assert.equal(p.doc.activeElement, p.$('#b1'));
  tecla('#b1', 'End');
  assert.equal(p.doc.activeElement, p.$('#b3'));
  ev = tecla('#b3', 'a');
  assert.equal(ev.defaultPrevented, false);
});

/* ---------------------------------------------------------------- interruptores */
const TOGGLES = `<form data-pbl-form>
  <div data-pbl-toggle data-pbl-inicial="desligado" id="t1"><input type="checkbox" checked><div data-pbl-toggle-campos id="c1"><input id="x1"></div></div>
  <div data-pbl-toggle data-pbl-inicial="ligado" id="t2"><input type="checkbox"><div data-pbl-toggle-campos id="c2"></div></div>
  <div data-pbl-toggle data-pbl-valor data-pbl-inicial="desligado" id="t3"><input type="checkbox" checked><div data-pbl-toggle-campos id="c3"></div></div></form>`;

test('interruptores: sem JS nascem marcados; com JS voltam ao estado de verdade e escondem os campos', () => {
  const p = pagina(TOGGLES);
  assert.equal(p.$('#t1 input').checked, false);
  assert.equal(p.$('#c1').hidden, true);
  assert.equal(p.$('#t2 input').checked, true);
  assert.equal(p.$('#c2').hidden, false);
});
test('interruptor que é o próprio dado (data-pbl-valor) mantém o valor do servidor', () => {
  const p = pagina(TOGGLES);
  assert.equal(p.$('#t3 input').checked, true);
  assert.equal(p.$('#c3').hidden, false);
});
test('ligar mostra os campos e desligar esconde, sem apagar o que foi digitado', () => {
  const p = pagina(TOGGLES);
  const caixa = p.$('#t1 input');
  p.$('#x1').value = 'rascunho';
  caixa.checked = true;
  caixa.dispatchEvent(evento('change'));
  assert.equal(p.$('#c1').hidden, false);
  caixa.checked = false;
  caixa.dispatchEvent(evento('change'));
  assert.equal(p.$('#c1').hidden, true);
  assert.equal(p.$('#x1').value, 'rascunho');
});

/* ---------------------------------------------------------------- contador */
test('contador: cria o aviso depois do campo, liga por aria-describedby e muda de nível (ok, perto, passou)', () => {
  const p = pagina('<form data-pbl-form><input id="campo-titulo" name="titulo" maxlength="60" data-pbl-contador aria-describedby="ajuda"></form>');
  const campo = p.$('#campo-titulo');
  const aviso = p.$('#contador-campo-titulo');
  assert.ok(aviso, 'contador criado');
  assert.equal(campo.parentElement.children.indexOf(aviso), campo.parentElement.children.indexOf(campo) + 1);
  assert.equal(campo.getAttribute('aria-describedby'), 'ajuda contador-campo-titulo');
  const digitar = (n) => { campo.value = 'a'.repeat(n); campo.dispatchEvent(evento('input')); };
  assert.equal(aviso.textContent, '0 de 60 caracteres');
  assert.equal(aviso.getAttribute('data-nivel'), 'ok');
  digitar(54);
  assert.equal(aviso.getAttribute('data-nivel'), 'perto');
  digitar(61);
  assert.equal(aviso.textContent, '61 de 60 caracteres');
  assert.equal(aviso.getAttribute('data-nivel'), 'passou');
});
test('contador: limite recomendado (SEO) tem prioridade sobre maxlength e diz "recomendados"', () => {
  const p = pagina('<form data-pbl-form><textarea id="a" maxlength="500" data-pbl-contador data-pbl-limite-bom="160"></textarea></form>');
  assert.equal(p.$('#contador-a').textContent, '0 de 160 recomendados');
});
test('contador: campo sem limite não ganha contador', () => {
  const p = pagina('<form data-pbl-form><input id="a" data-pbl-contador></form>');
  assert.equal(p.$('#contador-a'), null);
});

/* ---------------------------------------------------------------- validação ao vivo */
const VALIDACAO = `<form data-pbl-form><span data-pbl-estado data-estado="salvo">Tudo salvo</span>
  <div class="mb-3"><input id="campo-titulo" name="titulo" required maxlength="10"><span id="m1" data-pbl-msg></span></div>
  <div class="mb-3"><input id="campo-url" name="url"><span id="m2" data-pbl-msg></span></div>
  <div class="mb-3"><input id="campo-peso" name="peso" value="0"><span id="m3" data-pbl-msg></span></div></form>`;

test('validação: ao sair do campo obrigatório vazio marca aria-invalid e escreve a mensagem ligada por aria-describedby', () => {
  const p = pagina(VALIDACAO);
  const t = p.$('#campo-titulo');
  t.dispatchEvent(evento('blur'));
  assert.equal(t.getAttribute('aria-invalid'), 'true');
  assert.equal(p.$('#m1').textContent, 'Este campo é obrigatório.');
  assert.equal(t.getAttribute('aria-describedby'), 'm1');
});
test('validação: depois do erro, digitar revalida a cada tecla e limpa tudo quando corrige', () => {
  const p = pagina(VALIDACAO);
  const t = p.$('#campo-titulo');
  t.dispatchEvent(evento('blur'));
  t.value = 'Ola';
  t.dispatchEvent(evento('input'));
  assert.equal(t.hasAttribute('aria-invalid'), false);
  assert.equal(p.$('#m1').textContent, '');
  assert.equal(t.hasAttribute('aria-describedby'), false);
});
test('validação: não grita antes da hora (digitar num campo ainda sem erro não valida)', () => {
  const p = pagina(VALIDACAO);
  const u = p.$('#campo-url');
  u.value = 'ERRADO COM ESPAÇO';
  u.dispatchEvent(evento('input'));
  assert.equal(u.hasAttribute('aria-invalid'), false);
  u.dispatchEvent(evento('blur'));
  assert.equal(u.getAttribute('aria-invalid'), 'true');
});
test('validação: endereço aceita minúsculas, números, hífen e "/" e recusa o resto', () => {
  const p = pagina(VALIDACAO);
  const u = p.$('#campo-url');
  const erro = (v) => { u.value = v; u.dispatchEvent(evento('blur')); return u.hasAttribute('aria-invalid'); };
  for (const bom of ['', 'noticias', 'noticias/2026-ano-novo', 'a1/b2']) assert.equal(erro(bom), false, bom);
  for (const ruim of ['Noticias', 'notícias', 'a b', '/inicio', 'fim/', 'a//b', '-a', 'a_b']) assert.equal(erro(ruim), true, ruim);
});
test('validação: peso é inteiro de até 6 dígitos, com sinal opcional', () => {
  const p = pagina(VALIDACAO);
  const w = p.$('#campo-peso');
  const erro = (v) => { w.value = v; w.dispatchEvent(evento('blur')); return w.hasAttribute('aria-invalid'); };
  for (const bom of ['0', '10', '-5', '999999']) assert.equal(erro(bom), false, bom);
  for (const ruim of ['', 'a', '1.5', '1234567', '--1']) assert.equal(erro(ruim), true, ruim);
});
test('validação: passou do maxlength diz o limite', () => {
  const p = pagina(VALIDACAO);
  const t = p.$('#campo-titulo');
  t.value = 'a'.repeat(11);
  t.dispatchEvent(evento('blur'));
  assert.equal(p.$('#m1').textContent, 'Passou do limite de 10 caracteres.');
});
test('envio: com campo inválido bloqueia, vai para a aba do erro, foca o campo e avisa; válido deixa seguir', () => {
  const p = pagina(ABAS.replace('data-pbl-aba-inicial="publicacao"', 'data-pbl-aba-inicial="avancado"').replace('</form>', '<span data-pbl-estado data-estado="salvo"></span></form>'));
  const form = p.$('form');
  let ev = evento('submit');
  form.dispatchEvent(ev);
  assert.equal(ev.defaultPrevented, true, 'título obrigatório vazio');
  assert.equal(p.doc.activeElement, p.$('#campo-titulo'));
  assert.equal(p.$('#aba-painel-conteudo').hasAttribute('data-ativa'), true, 'abriu a aba do campo com erro');
  assert.match(p.$('[data-pbl-estado]').textContent, /Corrija o campo marcado/);
  p.$('#campo-titulo').value = 'Título';
  ev = evento('submit');
  form.dispatchEvent(ev);
  assert.equal(ev.defaultPrevented, false);
  assert.equal(p.$('[data-pbl-estado]').textContent, 'Salvando…');
});
test('envio: campo dentro de interruptor desligado não bloqueia o envio', () => {
  const p = pagina(`<form data-pbl-form><div data-pbl-toggle data-pbl-inicial="desligado"><input type="checkbox">
    <div data-pbl-toggle-campos><div class="mb-3"><input name="url" id="campo-url" value="INVÁLIDO"><span data-pbl-msg></span></div></div></div></form>`);
  const ev = evento('submit');
  p.$('form').dispatchEvent(ev);
  assert.equal(ev.defaultPrevented, false);
});
test('envio: ao ligar o interruptor, o mesmo campo passa a ser validado', () => {
  const p = pagina(`<form data-pbl-form><div data-pbl-toggle data-pbl-inicial="desligado"><input type="checkbox" id="k">
    <div data-pbl-toggle-campos><div class="mb-3"><input name="url" id="campo-url" value="INVÁLIDO"><span data-pbl-msg></span></div></div></div></form>`);
  p.$('#k').checked = true;
  p.$('#k').dispatchEvent(evento('change'));
  const ev = evento('submit');
  p.$('form').dispatchEvent(ev);
  assert.equal(ev.defaultPrevented, true);
});
test('erro mostrado em campo escondido abre a aba, o <details> e liga o interruptor que o contém', () => {
  const p = pagina(`<form data-pbl-form><div data-pbl-abas-form data-pbl-aba-inicial="conteudo"><ul data-pbl-abas-nav hidden>
      <li><button type="button" role="tab" data-pbl-aba="conteudo">C</button></li><li><button type="button" role="tab" data-pbl-aba="avancado">A</button></li></ul>
    <div class="pbl-aba-painel" id="aba-painel-conteudo"></div>
    <div class="pbl-aba-painel" id="aba-painel-avancado"><details><summary>x</summary><div data-pbl-toggle data-pbl-inicial="desligado"><input type="checkbox">
      <div data-pbl-toggle-campos><div class="mb-3"><input id="campo-peso" name="peso" value="x"><span data-pbl-msg></span></div></div></div></details></div></div>
    <div data-pbl-resumo-erros tabindex="-1"><a href="#campo-peso">Peso</a></div></form>`);
  assert.equal(p.doc.activeElement, p.$('[data-pbl-resumo-erros]'), 'foco no resumo ao carregar');
  const ev = evento('click');
  p.$('a').dispatchEvent(ev);
  assert.equal(ev.defaultPrevented, true);
  assert.equal(p.$('#aba-painel-avancado').hasAttribute('data-ativa'), true);
  assert.equal(p.$('details').hasAttribute('open'), true);
  assert.equal(p.$('[data-pbl-toggle] input').checked, true);
  assert.equal(p.$('[data-pbl-toggle-campos]').hidden, false);
  assert.equal(p.doc.activeElement, p.$('#campo-peso'));
});
test('resumo de erros: link para campo que não existe segue o comportamento normal do navegador', () => {
  const p = pagina('<form data-pbl-form><div data-pbl-resumo-erros tabindex="-1"><a href="#nada">x</a></div></form>');
  const ev = evento('click');
  p.$('a').dispatchEvent(ev);
  assert.equal(ev.defaultPrevented, false);
});

/* ---------------------------------------------------------------- estado salvo / aviso ao sair */
const AVISO = '<form data-pbl-form><span data-pbl-estado data-estado="salvo">Tudo salvo</span><input id="campo-titulo" name="titulo"></form>';

test('aviso ao sair: sem alteração não pede confirmação', () => {
  const p = pagina(AVISO);
  assert.equal(p.sair().defaultPrevented, false);
});
test('aviso ao sair: depois de editar marca "não salvas", pede confirmação e volta ao texto normal', () => {
  const p = pagina(AVISO);
  p.$('#campo-titulo').dispatchEvent(evento('input', { bubbles: true }));
  p.$('form').dispatchEvent(evento('input'));
  assert.equal(p.$('[data-pbl-estado]').textContent, 'Alterações não salvas');
  const ev = p.sair();
  assert.equal(ev.defaultPrevented, true);
  assert.equal(ev.returnValue, '');
  assert.equal(p.$('[data-pbl-estado]').getAttribute('data-estado'), 'saindo');
  p.rodarTarefas();
  assert.equal(p.$('[data-pbl-estado]').getAttribute('data-estado'), 'alterado');
});
test('aviso ao sair: salvar (submit válido) libera a saída', () => {
  const p = pagina(AVISO);
  p.$('form').dispatchEvent(evento('change'));
  p.$('form').dispatchEvent(evento('submit'));
  assert.equal(p.sair().defaultPrevented, false);
});
test('aviso ao sair: tela que já chega como "alterado" (erro do servidor) pede confirmação mesmo sem digitar', () => {
  const p = pagina(AVISO.replace('data-estado="salvo"', 'data-estado="alterado"'));
  assert.equal(p.sair().defaultPrevented, true);
});
test('aviso ao sair: envio bloqueado pela validação não libera a saída', () => {
  const p = pagina('<form data-pbl-form><div class="mb-3"><input name="titulo" required><span data-pbl-msg></span></div></form>');
  p.$('form').dispatchEvent(evento('change'));
  p.$('form').dispatchEvent(evento('submit'));
  assert.equal(p.sair().defaultPrevented, true);
});

/* ---------------------------------------------------------------- endereço e prévias */
const PREVIAS = `<form data-pbl-form>
  <input id="campo-titulo" name="titulo"><input id="campo-url" name="url"><input type="checkbox" name="usar_url">
  <textarea id="campo-descricao"></textarea><input id="campo-dados-seo_titulo"><textarea id="campo-dados-seo_descricao"></textarea>
  <span data-pbl-endereco-previa data-pbl-base="https://site.test"></span>
  <div data-pbl-previa-busca hidden data-pbl-base="https://site.test"><span data-pbl-previa-url></span><span data-pbl-previa-titulo></span><span data-pbl-previa-texto></span><span data-pbl-previa-corte></span></div>
  <div data-pbl-miniatura hidden><img src="x"></div></form>`;
const digitar = (p, id, v) => { const e = p.$('#' + id); e.value = v; e.dispatchEvent(evento('input')); };

test('endereço: sem endereço digitado, mostra o gerado do título (sem acento, com hífen) e avisa', () => {
  const p = pagina(PREVIAS);
  digitar(p, 'campo-titulo', 'Ação Nova: 2026!');
  assert.equal(p.$('[data-pbl-endereco-previa]').textContent, 'https://site.test/acao-nova-2026 (gerado do título)');
  digitar(p, 'campo-url', 'meu-endereco');
  assert.equal(p.$('[data-pbl-endereco-previa]').textContent, 'https://site.test/meu-endereco');
});
test('endereço: título vazio vira "conteudo"', () => {
  const p = pagina(PREVIAS);
  digitar(p, 'campo-titulo', '!!!');
  assert.match(p.$('[data-pbl-endereco-previa]').textContent, /\/conteudo/);
});
test('prévia de busca: aparece, usa título/descrição de SEO antes dos comuns e corta no limite com aviso', () => {
  const p = pagina(PREVIAS);
  assert.equal(p.$('[data-pbl-previa-busca]').hidden, false);
  digitar(p, 'campo-titulo', 'Título comum');
  digitar(p, 'campo-descricao', 'Descrição comum');
  assert.equal(p.$('[data-pbl-previa-titulo]').textContent, 'Título comum');
  assert.equal(p.$('[data-pbl-previa-texto]').textContent, 'Descrição comum');
  digitar(p, 'campo-dados-seo_titulo', 'T'.repeat(80));
  digitar(p, 'campo-dados-seo_descricao', 'D'.repeat(200));
  assert.equal(p.$('[data-pbl-previa-titulo]').textContent, 'T'.repeat(59) + '…');
  assert.equal(p.$('[data-pbl-previa-texto]').textContent, 'D'.repeat(159) + '…');
  assert.equal(p.$('[data-pbl-previa-corte]').textContent, 'Pode ser cortado nas buscas.');
});
test('prévia de busca: título vazio mostra "Sem título"', () => {
  const p = pagina(PREVIAS);
  assert.equal(p.$('[data-pbl-previa-titulo]').textContent, 'Sem título');
});
test('prévia de rede social é remontada a cada digitação (sem acumular) e usa a miniatura visível', () => {
  const p = pagina(PREVIAS);
  p.$('[data-pbl-miniatura]').removeAttribute('hidden');
  p.$('[data-pbl-miniatura] img').src = 'blob:fake/9';
  digitar(p, 'campo-titulo', 'Um');
  digitar(p, 'campo-titulo', 'Dois');
  const social = p.$$('[data-pbl-previa-social]');
  assert.equal(social.length, 1);
  assert.equal(social[0].querySelectorAll('.pbl-previa-titulo')[0].textContent, 'Dois');
  const img = social[0].querySelector('img');
  assert.equal(img.src, 'blob:fake/9');
  assert.equal(img.alt, '', 'decorativa');
});
test('XSS: marcação digitada vira texto nas prévias, nunca elemento', () => {
  const p = pagina(PREVIAS);
  const ataque = '<script>alert(1)</script><img src=x onerror=alert(2)>';
  digitar(p, 'campo-titulo', ataque);
  digitar(p, 'campo-descricao', ataque);
  digitar(p, 'campo-url', ataque);
  const previa = p.$('[data-pbl-previa-busca]');
  assert.equal(previa.querySelectorAll('script').length, 0);
  assert.equal(previa.querySelectorAll('img').length, 0, 'nenhum <img> criado a partir do texto');
  assert.equal(p.$('[data-pbl-previa-titulo]').textContent, ataque);
  assert.equal(p.$('[data-pbl-endereco-previa]').children.length, 0);
  assert.equal(p.$('[data-pbl-previa-social]').querySelectorAll('script').length, 0);
});

/* ---------------------------------------------------------------- miniatura de imagem */
const IMAGEM = `<form data-pbl-form><div class="mb-3"><input type="file" data-pbl-imagem id="f"><div data-pbl-miniatura hidden><img alt=""><span data-pbl-miniatura-nome></span></div></div></form>`;
const escolher = (p, arquivo) => { const f = p.$('#f'); f.files = arquivo ? [arquivo] : []; f.dispatchEvent(evento('change')); };

test('miniatura: imagem escolhida mostra a prévia com nome e tamanho em KB', () => {
  const p = pagina(IMAGEM);
  escolher(p, { type: 'image/png', name: 'foto.png', size: 2048 });
  assert.equal(p.$('[data-pbl-miniatura]').hidden, false);
  assert.equal(p.$('[data-pbl-miniatura] img').src, 'blob:fake/1');
  assert.equal(p.$('[data-pbl-miniatura-nome]').textContent, 'foto.png (2 KB)');
});
test('miniatura: arquivo pequeno nunca diz 0 KB', () => {
  const p = pagina(IMAGEM);
  escolher(p, { type: 'image/gif', name: 'a.gif', size: 10 });
  assert.equal(p.$('[data-pbl-miniatura-nome]').textContent, 'a.gif (1 KB)');
});
test('miniatura: trocar a imagem libera o blob anterior; limpar ou escolher não-imagem esconde a prévia', () => {
  const p = pagina(IMAGEM);
  escolher(p, { type: 'image/png', name: 'a.png', size: 1024 });
  escolher(p, { type: 'image/jpeg', name: 'b.jpg', size: 1024 });
  assert.deepEqual(p.blobs.revogados, ['blob:fake/1']);
  escolher(p, { type: 'application/pdf', name: 'c.pdf', size: 1024 });
  assert.equal(p.$('[data-pbl-miniatura]').hidden, true);
  assert.deepEqual(p.blobs.revogados, ['blob:fake/1', 'blob:fake/2']);
  assert.equal(p.blobs.criados.length, 2, 'PDF não gera blob');
  escolher(p, null);
  assert.equal(p.$('[data-pbl-miniatura]').hidden, true);
});
test('miniatura: o nome do arquivo entra como texto, nunca como HTML', () => {
  const p = pagina(IMAGEM);
  escolher(p, { type: 'image/png', name: '<b>x</b>.png', size: 1024 });
  assert.equal(p.$('[data-pbl-miniatura-nome]').children.length, 0);
  assert.equal(p.$('[data-pbl-miniatura-nome]').textContent, '<b>x</b>.png (1 KB)');
});

/* ---------------------------------------------------------------- seletor com busca */
const opcoes = (n, prefixo = 'Item') => Array.from({ length: n }, (_, i) => `<option value="${i + 1}"${i === 2 ? ' selected' : ''}>#${i + 1} · ${prefixo} ${i + 1}</option>`).join('');

test('seletor: lista curta (menos de 8) fica como está; lista longa ganha campo de busca acessível antes dela', () => {
  assert.equal(pagina(`<form data-pbl-form><select data-pbl-seletor id="s">${opcoes(7)}</select></form>`).$('.pbl-seletor-busca'), null);
  const p = pagina(`<form data-pbl-form><div id="g"><select data-pbl-seletor id="s">${opcoes(12)}</select></div></form>`);
  const busca = p.$('.pbl-seletor-busca');
  assert.ok(busca);
  assert.equal(busca.type, 'search');
  assert.equal(busca.getAttribute('aria-label'), 'Buscar na lista');
  const filhos = p.$('#g').children;
  assert.equal(filhos.indexOf(busca), filhos.indexOf(p.$('#s')) - 1);
});
test('seletor: filtra sem diferenciar maiúsculas, mantém a opção escolhida e some com grupos vazios', () => {
  const p = pagina(`<form data-pbl-form><select data-pbl-seletor id="s"><optgroup id="g1" label="A">${opcoes(9, 'Notícia')}</optgroup><optgroup id="g2" label="B"><option value="99">#99 · Contato</option></optgroup></select></form>`);
  const busca = p.$('.pbl-seletor-busca');
  const filtrar = (q) => { busca.value = q; busca.dispatchEvent(evento('input')); return p.$$('option').filter((o) => !o.hidden).map((o) => o.getAttribute('value')); };
  assert.deepEqual(filtrar('CONTATO'), ['3', '99'], 'a escolhida (3) nunca some');
  assert.equal(p.$('#g2').hidden, false);
  assert.deepEqual(filtrar('#7'), ['3', '7']);
  assert.equal(p.$('#g2').hidden, true, 'grupo sem opção visível some');
  assert.equal(p.$('#g1').hidden, false);
  assert.equal(filtrar('').length, 10, 'busca vazia mostra tudo');
  assert.equal(p.$('#g2').hidden, false);
});

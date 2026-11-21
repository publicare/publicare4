/* PBL4 — protótipo clicável. Só interações: abas, recolher, validação, contador, prévia, seletores, busca fake, estado salvo/não salvo.
   Sem framework, sem CDN, sem rede. Tudo opcional: sem JavaScript as telas continuam navegáveis e legíveis. */
(function () {
  'use strict';
  var raiz = document.documentElement;
  raiz.classList.add('js');

  var CHAVE_TEMA = 'pbl-prototipo-tema';
  var CHAVE_CONFUSOES = 'pbl-prototipo-confusoes';

  function guardar(chave, valor) { try { localStorage.setItem(chave, valor); } catch (e) { /* modo privado */ } }
  function ler(chave) { try { return localStorage.getItem(chave); } catch (e) { return null; } }
  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function semAcento(t) { return String(t).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }
  function hora() { var d = new Date(); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
  function criar(tag, atributos, texto) {
    var el = document.createElement(tag);
    Object.keys(atributos || {}).forEach(function (k) { el.setAttribute(k, atributos[k]); });
    if (texto !== undefined) el.textContent = texto;
    return el;
  }
  function anunciar(texto) {
    var r = $('#anuncio');
    if (!r) { r = criar('div', { id: 'anuncio', 'class': 'visualmente-oculto', 'aria-live': 'polite', role: 'status' }); document.body.appendChild(r); }
    r.textContent = '';
    setTimeout(function () { r.textContent = texto; }, 30);
  }
  function rotuloDe(campo) {
    var l = campo.id && $('label[for="' + campo.id + '"]');
    return l ? l.firstChild.textContent.trim() : (campo.getAttribute('aria-label') || campo.name || 'campo');
  }
  function descrever(campo, id) {
    var atual = (campo.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
    if (atual.indexOf(id) < 0) atual.push(id);
    campo.setAttribute('aria-describedby', atual.join(' '));
  }
  function aplicarTema(t) {
    raiz.setAttribute('data-bs-theme', t);
    $$('[data-tema]').forEach(function (b) { b.setAttribute('aria-pressed', t === 'dark' ? 'true' : 'false'); b.textContent = t === 'dark' ? 'Tema: escuro' : 'Tema: claro'; });
  }

  /* ---------- Tema ---------- */
  var salvo = ler(CHAVE_TEMA);
  var tema = salvo || (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  raiz.setAttribute('data-bs-theme', tema);

  function iniciar() {
    aplicarTema(raiz.getAttribute('data-bs-theme'));
    $$('[data-tema]').forEach(function (b) {
      b.addEventListener('click', function () {
        var novo = raiz.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        guardar(CHAVE_TEMA, novo); aplicarTema(novo);
      });
    });
    iniciarMenu(); iniciarAbas(); iniciarContadores(); iniciarCamposCondicionais(); iniciarPrevias();
    iniciarImagens(); iniciarTags(); iniciarSeletoresConteudo(); iniciarFormularios(); iniciarAcoes();
    iniciarBuscaGlobal(); iniciarFiltros(); iniciarResultadosBusca(); iniciarProgresso(); iniciarConfuso(); iniciarRestaurar();
  }

  /* ---------- Menu no celular ---------- */
  function iniciarMenu() {
    var botao = $('.botao-menu'), menu = $('#menu-principal');
    if (!botao || !menu) return;
    botao.setAttribute('aria-expanded', 'false');
    botao.addEventListener('click', function () {
      var aberto = menu.classList.toggle('aberto');
      botao.setAttribute('aria-expanded', aberto ? 'true' : 'false');
    });
    menu.addEventListener('keydown', function (e) { if (e.key === 'Escape' && menu.classList.contains('aberto')) { menu.classList.remove('aberto'); botao.setAttribute('aria-expanded', 'false'); botao.focus(); } });
  }

  /* ---------- Abas (links) ---------- */
  function mostrarAba(grupo, id, foco) {
    var achou = false;
    $$('[data-painel]', grupo).forEach(function (p) { var e = p.id === id; p.hidden = !e; if (e) achou = true; });
    if (!achou) return false;
    $$('a[data-aba]', grupo).forEach(function (a) {
      if (a.getAttribute('href') === '#' + id) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
    if (foco) { var a = $('a[href="#' + id + '"]', grupo); if (a) a.focus(); }
    return true;
  }
  function iniciarAbas() {
    $$('[data-abas]').forEach(function (grupo) {
      var primeira = $('a[data-aba]', grupo);
      if (!primeira) return;
      var alvo = location.hash.slice(1);
      if (!mostrarAba(grupo, alvo, false)) mostrarAba(grupo, primeira.getAttribute('href').slice(1), false);
      $$('a[data-aba]', grupo).forEach(function (a) {
        a.addEventListener('click', function (e) {
          e.preventDefault();
          var id = a.getAttribute('href').slice(1);
          mostrarAba(grupo, id, false);
          if (history.replaceState) history.replaceState(null, '', '#' + id);
        });
      });
    });
  }
  function revelar(campo) {
    var det = campo.closest('details'); while (det) { det.open = true; det = det.parentElement && det.parentElement.closest('details'); }
    var painel = campo.closest('[data-painel]');
    if (painel) { var grupo = painel.closest('[data-abas]'); if (grupo) mostrarAba(grupo, painel.id, false); }
  }
  function atualizarMarcas(form) {
    $$('[data-painel]', form.closest('[data-abas]') || form).forEach(function (p) {
      var marca = $('a[href="#' + p.id + '"] .marca-aba', form.closest('[data-abas]') || document);
      if (!marca) return;
      var erros = $$('[aria-invalid="true"]', p).length;
      var sujo = p.dataset.sujo === '1';
      marca.textContent = erros ? ' •' + erros + ' com erro' : (sujo ? ' • não salvo' : '');
    });
  }

  /* ---------- Contador ---------- */
  function atualizarContador(campo) {
    var max = parseInt(campo.dataset.max, 10), c = campo._contador;
    if (!c) return;
    var n = campo.value.length;
    c.classList.remove('perto', 'passou');
    if (n > max) { c.textContent = 'Passou ' + (n - max) + ' do limite de ' + max; c.classList.add('passou'); if (!campo._passou) anunciar('Passou do limite de ' + max + ' caracteres.'); campo._passou = true; return; }
    campo._passou = false;
    if (n >= max * 0.9) { c.textContent = 'Faltam ' + (max - n); c.classList.add('perto'); } else { c.textContent = n + ' de ' + max; }
  }
  function iniciarContadores() {
    $$('[data-max]').forEach(function (campo) {
      var c = criar('p', { 'class': 'contador', id: campo.id + '-contador' });
      campo.insertAdjacentElement('afterend', c); campo._contador = c;
      descrever(campo, c.id); atualizarContador(campo);
      campo.addEventListener('input', function () { atualizarContador(campo); });
    });
  }

  /* ---------- Campos condicionais (Agendar) ---------- */
  function iniciarCamposCondicionais() {
    $$('[data-alterna]').forEach(function (chk) {
      var alvo = document.getElementById(chk.dataset.alterna);
      if (!alvo) return;
      function atualizar() { alvo.hidden = !chk.checked; }
      chk.addEventListener('change', atualizar); atualizar();
    });
  }

  /* ---------- Prévia (busca e rede social) ---------- */
  function primeiroValor(ids) {
    for (var i = 0; i < ids.length; i++) { var c = document.getElementById(ids[i]); if (c && c.value.trim()) return c.value.trim(); }
    return '';
  }
  function iniciarPrevias() {
    var espelhos = $$('[data-espelha]');
    function atualizar() {
      espelhos.forEach(function (el) {
        var texto = primeiroValor(el.dataset.espelha.split(','));
        var limite = parseInt(el.dataset.limite || '0', 10);
        el.textContent = limite && texto.length > limite ? texto.slice(0, limite - 1) + '…' : (texto || el.dataset.vazio || '');
      });
      $$('[data-corte-de]').forEach(function (el) {
        var n = primeiroValor(el.dataset.corteDe.split(',')).length, lim = parseInt(el.dataset.limite, 10);
        el.textContent = n > lim ? 'Pode ser cortado nos resultados de busca (' + n + ' de ' + lim + ').' : '';
      });
    }
    $$('input, textarea').forEach(function (c) { c.addEventListener('input', atualizar); });
    document.addEventListener('pbl:imagem', function (e) {
      $$('img[data-imagem-de="' + e.detail.id + '"]').forEach(function (img) { if (e.detail.url) { img.src = e.detail.url; img.hidden = false; } else { img.removeAttribute('src'); img.hidden = true; } });
    });
    atualizar();
  }

  /* ---------- Seletor de imagem ---------- */
  var TIPOS_IMAGEM = ['image/png', 'image/jpeg', 'image/webp'], MAX_IMAGEM = 2 * 1024 * 1024;
  function iniciarImagens() {
    $$('[data-seletor-imagem]').forEach(function (zona) {
      var input = $('input[type="file"]', zona), nome = $('.nome-arquivo', zona), mini = $('img.miniatura', zona), tirar = $('[data-tirar-imagem]', zona);
      var atual = zona.dataset.atual || '';
      var erro = criar('p', { 'class': 'erro-campo', id: input.id + '-erro', hidden: '' }); zona.appendChild(erro);
      function estado(arquivo, url) {
        nome.textContent = arquivo ? arquivo : (atual || 'Escolha uma imagem');
        if (url) { mini.src = url; mini.hidden = false; } else { mini.removeAttribute('src'); mini.hidden = true; }
        tirar.hidden = !(arquivo || atual);
        document.dispatchEvent(new CustomEvent('pbl:imagem', { detail: { id: input.id, url: url || '' } }));
      }
      function mostrarErro(msg) { erro.textContent = msg; erro.hidden = !msg; if (msg) { input.setAttribute('aria-invalid', 'true'); descrever(input, erro.id); } else input.removeAttribute('aria-invalid'); }
      function aceitar(arquivo) {
        mostrarErro('');
        if (TIPOS_IMAGEM.indexOf(arquivo.type) < 0) { mostrarErro('Use uma imagem PNG, JPG ou WebP.'); input.value = ''; return; }
        if (arquivo.size > MAX_IMAGEM) { mostrarErro('A imagem passa de 2 MB. Escolha uma menor.'); input.value = ''; return; }
        var leitor = new FileReader();
        leitor.onload = function () { estado(arquivo.name, leitor.result); input.dispatchEvent(new Event('change', { bubbles: true })); };
        leitor.readAsDataURL(arquivo);
      }
      input.addEventListener('change', function () { if (input.files && input.files[0]) aceitar(input.files[0]); });
      tirar.addEventListener('click', function () { input.value = ''; atual = ''; mostrarErro(''); estado('', ''); input.dispatchEvent(new Event('change', { bubbles: true })); input.focus(); });
      ['dragenter', 'dragover'].forEach(function (n) { zona.addEventListener(n, function (e) { e.preventDefault(); zona.classList.add('sobre'); }); });
      ['dragleave', 'drop'].forEach(function (n) { zona.addEventListener(n, function (e) { e.preventDefault(); zona.classList.remove('sobre'); }); });
      zona.addEventListener('drop', function (e) { var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]; if (f) aceitar(f); });
      estado('', '');
    });
  }

  /* ---------- Tags ---------- */
  var TAGS_EXISTENTES = ['hospedagem', 'verão', 'inverno', 'família', 'gastronomia', 'música', 'passeios', 'promoção'];
  function iniciarTags() {
    $$('[data-tags]').forEach(function (bloco) {
      var original = $('input[type="text"]', bloco), maxTags = parseInt(bloco.dataset.tags || '10', 10);
      var rotulo = $('label[for="' + original.id + '"]');
      var lista = criar('ul', { 'class': 'tags-chips', 'aria-label': 'Tags escolhidas' });
      var novo = criar('input', { type: 'text', id: original.id + '-novo', 'class': 'form-control', list: original.id + '-sugestoes', autocomplete: 'off', enterkeyhint: 'done' });
      var dl = criar('datalist', { id: original.id + '-sugestoes' });
      TAGS_EXISTENTES.forEach(function (t) { dl.appendChild(criar('option', { value: t })); });
      var msg = criar('p', { 'class': 'erro-campo', id: original.id + '-erro', hidden: '' });
      var valores = original.value.split(',').map(function (t) { return t.trim(); }).filter(Boolean);
      if (rotulo) rotulo.setAttribute('for', novo.id);
      var ajuda = $('.ajuda', bloco); if (ajuda) descrever(novo, ajuda.id);
      descrever(novo, msg.id);
      original.hidden = true; original.setAttribute('aria-hidden', 'true'); original.tabIndex = -1;
      original.insertAdjacentElement('beforebegin', lista);
      original.insertAdjacentElement('afterend', novo); novo.insertAdjacentElement('afterend', dl); dl.insertAdjacentElement('afterend', msg);
      function desenhar() {
        lista.textContent = '';
        valores.forEach(function (t) {
          var li = criar('li', { 'class': 'chip' }); li.appendChild(criar('span', {}, t));
          var b = criar('button', { type: 'button', 'aria-label': 'Remover tag ' + t }, '×');
          b.addEventListener('click', function () { valores = valores.filter(function (v) { return v !== t; }); sincronizar(); novo.focus(); anunciar('Tag ' + t + ' removida.'); });
          li.appendChild(b); lista.appendChild(li);
        });
        lista.hidden = !valores.length;
      }
      function sincronizar() { original.value = valores.join(', '); desenhar(); original.dispatchEvent(new Event('input', { bubbles: true })); }
      function adicionar() {
        var t = novo.value.replace(/,/g, '').trim();
        if (!t) return;
        if (valores.length >= maxTags) { msg.textContent = 'Use no máximo ' + maxTags + ' tags. Remova uma para adicionar outra.'; msg.hidden = false; return; }
        msg.hidden = true;
        if (valores.map(semAcento).indexOf(semAcento(t)) < 0) { valores.push(t); sincronizar(); anunciar('Tag ' + t + ' adicionada.'); }
        novo.value = '';
      }
      novo.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); adicionar(); } });
      novo.addEventListener('change', adicionar);
      novo.addEventListener('blur', adicionar);
      desenhar();
    });
  }

  /* ---------- Seletor de conteúdo (combobox ARIA 1.2) ---------- */
  var CONTEUDOS = [
    ['1', 'Início'], ['2', 'Quartos › Suíte Master'], ['3', 'Quartos › Quarto Standard'], ['4', 'Quartos › Quarto Família'],
    ['5', 'Eventos › Noite de Jazz'], ['6', 'Eventos › Feira de Inverno'], ['7', 'Novidades › Inverno no Sítio'], ['8', 'Contato']
  ];
  function iniciarSeletoresConteudo() {
    $$('[data-seletor-conteudo]').forEach(function (bloco) {
      var select = $('select', bloco), rotulo = $('label[for="' + select.id + '"]');
      var id = select.id + '-busca';
      var input = criar('input', { type: 'text', id: id, 'class': 'form-control', role: 'combobox', 'aria-expanded': 'false', 'aria-controls': id + '-lista', 'aria-autocomplete': 'list', autocomplete: 'off' });
      var lista = criar('ul', { id: id + '-lista', role: 'listbox', 'aria-label': 'Resultados', hidden: '' });
      var oculto = criar('input', { type: 'hidden', name: select.name }); var envolt = criar('div', { 'class': 'combo' });
      var ajuda = $('.ajuda', bloco); if (ajuda) descrever(input, ajuda.id);
      var atualOpcao = select.options[select.selectedIndex];
      if (atualOpcao && atualOpcao.value) { input.value = atualOpcao.textContent; oculto.value = atualOpcao.value; }
      select.hidden = true; select.disabled = true; select.removeAttribute('name');
      if (rotulo) rotulo.setAttribute('for', id);
      select.insertAdjacentElement('afterend', envolt); envolt.appendChild(input); envolt.appendChild(lista); envolt.appendChild(oculto);
      var ativo = -1, itens = [], timer = null;
      function fechar() { lista.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); ativo = -1; }
      function marcar(i) {
        ativo = i;
        $$('li', lista).forEach(function (li, k) { li.setAttribute('aria-selected', k === i ? 'true' : 'false'); });
        if (i >= 0) input.setAttribute('aria-activedescendant', id + '-op' + i); else input.removeAttribute('aria-activedescendant');
      }
      function escolher(i) { var it = itens[i]; if (!it) return; input.value = it[1]; oculto.value = it[0]; fechar(); input.dispatchEvent(new Event('change', { bubbles: true })); }
      function buscar() {
        var q = semAcento(input.value.trim());
        if (q.length < 2) { fechar(); return; }
        itens = CONTEUDOS.filter(function (c) { return semAcento(c[1]).indexOf(q) >= 0; });
        lista.textContent = '';
        if (!itens.length) { var vazio = criar('li', { role: 'presentation' }, 'Nenhum conteúdo com “' + input.value.trim() + '”.'); vazio.classList.add('ajuda'); lista.appendChild(vazio); }
        itens.forEach(function (c, i) {
          var li = criar('li', { role: 'option', id: id + '-op' + i, 'aria-selected': 'false' }, c[1]);
          li.addEventListener('mousedown', function (e) { e.preventDefault(); escolher(i); });
          lista.appendChild(li);
        });
        lista.hidden = false; input.setAttribute('aria-expanded', 'true'); marcar(-1);
        anunciar(itens.length + ' resultados.');
      }
      input.addEventListener('input', function () { oculto.value = ''; clearTimeout(timer); timer = setTimeout(buscar, 300); });
      input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); if (lista.hidden) buscar(); else marcar(Math.min(ativo + 1, itens.length - 1)); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); marcar(Math.max(ativo - 1, 0)); }
        else if (e.key === 'Enter' && !lista.hidden && ativo >= 0) { e.preventDefault(); escolher(ativo); }
        else if (e.key === 'Escape' && !lista.hidden) { e.preventDefault(); e.stopPropagation(); fechar(); }
      });
      input.addEventListener('blur', function () { setTimeout(fechar, 120); });
    });
  }

  /* ---------- Validação ---------- */
  var RE_EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  function urlValida(t) { try { var u = new URL(t); return u.protocol === 'http:' || u.protocol === 'https:'; } catch (e) { return false; } }
  function ocultoPorRegra(c) { var h = c.closest('[hidden]'); return !!h && !h.hasAttribute('data-painel'); }
  function validarCampo(campo) {
    if (campo.disabled || campo.type === 'hidden' || ocultoPorRegra(campo)) return '';
    var v = campo.value.trim(), tipo = campo.dataset.tipo, rot = rotuloDe(campo);
    if (campo.hasAttribute('data-obrigatorio') && !v) return 'Preencha “' + rot + '”.';
    if (!v) return '';
    if (campo.dataset.max && v.length > parseInt(campo.dataset.max, 10)) return '“' + rot + '” passou do limite: apague ' + (v.length - parseInt(campo.dataset.max, 10)) + ' caracteres.';
    if (tipo === 'email' && !RE_EMAIL.test(v)) return 'Use um e-mail completo, como nome@exemplo.com.br.';
    if (tipo === 'telefone') { var d = v.replace(/\D/g, ''); if (!/^[\d\s()+\-]+$/.test(v) || d.length < 8 || d.length > 13) return 'Use só números, espaços, parênteses e hífen, com DDD. Ex.: (11) 3456-7890.'; }
    if (tipo === 'url' && !urlValida(v)) return 'Use um endereço completo começando por https://. Ex.: https://exemplo.com.br/inscricao.';
    if (tipo === 'urls') { var ruins = v.split(/\n/).map(function (l) { return l.trim(); }).filter(function (l) { return l && !urlValida(l); }); if (ruins.length) return 'Uma destas linhas não é um endereço válido: ' + ruins[0] + '. Use https:// no começo, um por linha.'; }
    if (tipo === 'slug' && !/^[a-z0-9]+(-[a-z0-9]+)*$/.test(v)) return 'Use só letras minúsculas sem acento, números e hífen. Ex.: suite-master.';
    if (tipo === 'analytics' && !/^[A-Za-z0-9_-]+$/.test(v)) return 'Use só letras, números, hífen e sublinhado.';
    if (tipo === 'inteiro') {
      var n = Number(v), min = campo.getAttribute('min'), max = campo.getAttribute('max');
      if (!/^-?\d+$/.test(v)) return 'Digite um número inteiro.';
      if (min !== null && n < Number(min)) return 'O menor valor é ' + min + '.';
      if (max !== null && n > Number(max)) return 'O maior valor é ' + max + '.';
    }
    if (campo.dataset.depoisDe) {
      var ref = document.getElementById(campo.dataset.depoisDe);
      if (ref && ref.value && !ocultoPorRegra(ref) && campo.value <= ref.value) return 'A saída do ar precisa ser depois da publicação.';
    }
    return '';
  }
  function erroDe(campo) {
    var id = campo.id + '-erro', e = document.getElementById(id);
    if (!e) {
      e = criar('p', { 'class': 'erro-campo', id: id, hidden: '' });
      var depois = campo._contador || campo.closest('.combo') || campo; depois.insertAdjacentElement('afterend', e);
      descrever(campo, id);
    }
    return e;
  }
  function mostrarCampo(campo) {
    var msg = validarCampo(campo), e = erroDe(campo);
    e.textContent = msg; e.hidden = !msg;
    if (msg) { campo.setAttribute('aria-invalid', 'true'); campo._comErro = true; } else campo.removeAttribute('aria-invalid');
    var form = campo.form; if (form) atualizarMarcas(form);
    return msg;
  }
  function controles(ctx) { return $$('input:not([type=hidden]):not([type=file]):not([type=checkbox]):not([type=radio]):not([type=search]), textarea, select', ctx).filter(function (c) { return c.id && !c.disabled; }); }

  function iniciarFormularios() {
    $$('form[data-salvavel]').forEach(function (form) {
      var resumo = $('.resumo-erros', form), estadoEl = $('.estado-salvamento', form), versao = parseInt(form.dataset.versao || '0', 10);
      var ocupado = false, sujoGeral = false;
      form.noValidate = true;
      function assinatura(c) { return c.type === 'checkbox' || c.type === 'radio' ? (c.checked ? '1' : '0') : c.value; }
      var todos = $$('input:not([type=search]), textarea, select', form).filter(function (c) { return c.name || c.id; });
      todos.forEach(function (c) { c._inicial = assinatura(c); });
      function escreverEstado(classe, texto) { if (!estadoEl) return; estadoEl.className = 'estado-salvamento ' + classe; estadoEl.textContent = texto; }
      function recalcular() {
        var qualquer = false;
        $$('[data-painel]', form).concat(form.closest('[data-painel]') ? [] : []).forEach(function (p) {
          var s = todos.some(function (c) { return p.contains(c) && c._inicial !== assinatura(c); });
          p.dataset.sujo = s ? '1' : '0'; if (s) qualquer = true;
        });
        if (!$('[data-painel]', form)) qualquer = todos.some(function (c) { return c._inicial !== assinatura(c); });
        sujoGeral = qualquer;
        if (!ocupado) { if (qualquer) escreverEstado('alterado', 'Alterações não salvas'); }
        controles(form).forEach(function (c) { if (c._comErro) mostrarCampo(c); });
        atualizarMarcas(form);
        calcularEstado(form);
      }
      form.addEventListener('input', function (e) {
        var c = e.target;
        if (c._comErro) mostrarCampo(c);
        recalcular();
      });
      form.addEventListener('change', function (e) {
        var c = e.target;
        if (controles(form).indexOf(c) >= 0) mostrarCampo(c);
        recalcular();
      });
      form.addEventListener('focusout', function (e) { if (controles(form).indexOf(e.target) >= 0 && e.target.value.trim()) mostrarCampo(e.target); });

      function validar(escopo) {
        var ruins = [];
        controles(escopo).forEach(function (c) { var m = mostrarCampo(c); if (m) ruins.push({ campo: c, msg: m }); });
        return ruins;
      }
      function mostrarResumo(ruins) {
        if (!resumo) return;
        resumo.textContent = '';
        resumo.appendChild(criar('strong', {}, ruins.length === 1 ? 'Corrija 1 campo antes de continuar' : 'Corrija ' + ruins.length + ' campos antes de continuar'));
        var ul = criar('ul'); resumo.appendChild(ul);
        ruins.forEach(function (r) {
          var li = criar('li'), a = criar('a', { href: '#' + r.campo.id }, r.msg);
          a.addEventListener('click', function (ev) { ev.preventDefault(); revelar(r.campo); r.campo.focus(); });
          li.appendChild(a); ul.appendChild(li);
        });
        resumo.hidden = false; resumo.focus();
        revelar(ruins[0].campo);
      }
      function concluir(acao, painel) {
        ocupado = false; versao += 1; form.dataset.versao = String(versao);
        todos.forEach(function (c) { if (!painel || painel.contains(c)) c._inicial = assinatura(c); });
        var msg;
        if (acao === 'publicar') { definirEstado(form, 'publicado'); msg = 'Publicado' + saidaTexto(form) + '.'; }
        else if (acao === 'painel') msg = (painel.dataset.nome || 'Aba') + ' salvo.';
        else msg = 'Salvo às ' + hora() + ' · versão ' + versao;
        recalcular();
        if (acao === 'painel') { var st = $('[data-status]', painel); if (st) { st.textContent = msg; st.className = 'status aviso-ok'; } }
        else escreverEstado('salvo', acao === 'publicar' ? msg + ' ' + 'Salvo às ' + hora() + ' · versão ' + versao : msg);
        if (acao !== 'painel') anunciar(msg);
        $$('[data-salvar]', form).forEach(function (b) { b.disabled = false; if (b._rot) b.textContent = b._rot; });
        if (acao === 'painel' && !sujoGeral) escreverEstado('salvo', 'Sem alterações');
      }
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (ocupado) return;
        var bt = ev.submitter, acao = (bt && bt.dataset.acao) || 'salvar';
        var painel = acao === 'painel' ? bt.closest('[data-painel]') : null;
        if (resumo) resumo.hidden = true;
        var ruins = validar(painel || form);
        atualizarMarcas(form);
        if (ruins.length) { escreverEstado('erro', 'Não salvou: corrija os campos'); mostrarResumo(ruins); return; }
        ocupado = true; escreverEstado('', 'Gravando…');
        $$('[data-salvar]', form).forEach(function (b) { b.disabled = true; b._rot = b.textContent; });
        if (bt) bt.textContent = 'Salvando…';
        setTimeout(function () { concluir(acao, painel); }, 600);
      });
      document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); var b = $('[data-acao="salvar"]', form) || $('[data-acao="painel"]', $$('[data-painel]', form).filter(function (p) { return !p.hidden; })[0] || form); if (b) b.click(); }
      });
      window.addEventListener('beforeunload', function (e) { if (sujoGeral && !ocupado) { e.preventDefault(); e.returnValue = ''; } });
      form.querySelectorAll('[data-acao="publicar"]').forEach(function (b) { b.hidden = estadoAtual(form) !== 'privado'; });
      calcularEstado(form);
    });
  }

  /* ---------- Estado do conteúdo (Privado / Publicado / Agendado) ---------- */
  function estadoAtual(form) { var r = $('input[name="estado"]:checked', form); return r ? r.value : 'privado'; }
  function definirEstado(form, valor) { var r = $('input[name="estado"][value="' + valor + '"]', form); if (r) { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); } }
  function formatarData(v) { if (!v) return ''; var p = v.split('T'); var d = p[0].split('-'); return d[2] + '/' + d[1] + '/' + d[0] + (p[1] ? ' às ' + p[1] : ''); }
  function saidaTexto(form) { var s = $('#tirar-do-ar', form), ag = $('#agendar', form); return s && s.value && ag && ag.checked ? '. Sai do ar em ' + formatarData(s.value) : ''; }
  function calcularEstado(form) {
    var e = estadoAtual(form), ag = $('#agendar', form), pub = $('#publicar-em', form), rotulo = e === 'privado' ? 'Privado' : 'Publicado', classe = e === 'privado' ? 'selo-privado' : 'selo-publicado';
    if (e === 'publicado' && ag && ag.checked && pub && pub.value && new Date(pub.value) > new Date()) { rotulo = 'Agendado'; classe = 'selo-agendado'; }
    $$('[data-selo-estado]').forEach(function (s) { s.textContent = rotulo; s.className = 'selo ' + classe; });
    $$('[data-acao="publicar"]', form).forEach(function (b) { b.hidden = e !== 'privado'; });
  }

  /* ---------- Ações simuladas (data-msg) ---------- */
  function iniciarAcoes() {
    var global = null;
    function alvoStatus(b) {
      if (b.dataset.alvo) return document.getElementById(b.dataset.alvo);
      var cx = b.closest('[data-escopo]'); var s = cx && $('[data-status]', cx);
      if (s) return s;
      if (!global) { global = criar('div', { 'class': 'status', role: 'status', 'data-status': '' }); var m = $('main'); m.insertBefore(global, m.firstChild.nextSibling); }
      return global;
    }
    $$('[data-msg]').forEach(function (b) {
      b.addEventListener('click', function (e) {
        e.preventDefault();
        var st = alvoStatus(b);
        if (b.dataset.exige) {
          var c = document.getElementById(b.dataset.exige);
          if (c && !c.value.trim()) { c.setAttribute('aria-invalid', 'true'); var er = erroDe(c); er.textContent = c.dataset.msgExige || 'Preencha este campo para continuar.'; er.hidden = false; c.focus(); return; }
          if (c) { c.removeAttribute('aria-invalid'); erroDe(c).hidden = true; }
        }
        var chk = b.dataset.exigeMarcado && document.getElementById(b.dataset.exigeMarcado);
        if (chk && !chk.checked) return;
        function pronto() { st.textContent = b.dataset.msg.replace('{hora}', hora()); st.className = 'status aviso-ok'; b.disabled = false; if (b.dataset.depois) { var o = document.getElementById(b.dataset.depois); if (o) o.hidden = false; } if (b.dataset.esconde) { var h = document.getElementById(b.dataset.esconde); if (h) h.hidden = true; } }
        if (b.dataset.espera) { st.textContent = b.dataset.esperaMsg || 'Aguarde…'; st.className = 'status aviso-bloco'; b.disabled = true; setTimeout(pronto, parseInt(b.dataset.espera, 10)); } else pronto();
      });
    });
  }
  function iniciarRestaurar() {
    $$('[data-exige-marcado]').forEach(function (b) {
      var chk = document.getElementById(b.dataset.exigeMarcado);
      if (!chk) return;
      function a() { b.disabled = !chk.checked; }
      chk.addEventListener('change', a); a();
    });
  }
  function iniciarProgresso() {
    $$('[data-progresso]').forEach(function (b) {
      var barra = document.getElementById(b.dataset.progresso), st = document.getElementById(b.dataset.alvo);
      if (!barra) return;
      b.addEventListener('click', function (e) {
        e.preventDefault();
        var senha = document.getElementById(b.dataset.exige);
        if (senha && !senha.value.trim()) { senha.setAttribute('aria-invalid', 'true'); var er = erroDe(senha); er.textContent = 'Digite sua senha para atualizar.'; er.hidden = false; senha.focus(); return; }
        if (senha) { senha.removeAttribute('aria-invalid'); erroDe(senha).hidden = true; }
        barra.hidden = false; barra.value = 0; b.disabled = true; st.textContent = 'Atualizando… 0%'; st.className = 'status aviso-bloco';
        var n = 0, t = setInterval(function () {
          n += 20; barra.value = n; st.textContent = 'Atualizando… ' + n + '%';
          if (n >= 100) { clearInterval(t); st.textContent = 'Atualizado para a versão 4.2.0 (simulação).'; st.className = 'status aviso-ok'; }
        }, 400);
      });
    });
  }

  /* ---------- Busca global ---------- */
  function iniciarBuscaGlobal() {
    document.addEventListener('keydown', function (e) {
      if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
      var t = e.target, tag = t && t.tagName;
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (t && t.isContentEditable)) return;
      var campo = $('.busca-global input[type="search"]'); if (campo) { e.preventDefault(); campo.focus(); }
    });
  }
  function iniciarResultadosBusca() {
    var lista = $('[data-resultados]'); if (!lista) return;
    var q = (new URLSearchParams(location.search).get('q') || '').trim();
    var campo = $('.busca-global input[type="search"]'); if (campo) campo.value = q;
    var st = $('[data-contagem]'), vazio = $('[data-sem-resultado]');
    var itens = $$('[data-resultado]', lista), n = 0;
    itens.forEach(function (li) { var ok = !q || semAcento(li.textContent).indexOf(semAcento(q)) >= 0; li.hidden = !ok; if (ok) n++; });
    if (st) st.textContent = q ? n + ' resultado' + (n === 1 ? '' : 's') + ' para “' + q + '”.' : 'Todos os conteúdos (' + n + ').';
    if (vazio) { vazio.hidden = n > 0; var s = $('[data-termo]', vazio); if (s) s.textContent = q; }
  }
  function iniciarFiltros() {
    var form = $('[data-filtros]'); if (!form) return;
    var lista = $('[data-lista-conteudo]'), st = $('[data-contagem]'), vazio = $('[data-sem-resultado]');
    function aplicar() {
      var q = semAcento($('[name="q"]', form).value.trim()), est = $('[name="estado"]', form).value, n = 0;
      $$('[data-item]', lista).forEach(function (li) {
        var e = li.dataset.estado;
        var ok = (!q || semAcento(li.dataset.titulo).indexOf(q) >= 0) && (est === 'todos' ? e !== 'lixeira' : est === e);
        li.hidden = !ok; if (ok) n++;
      });
      st.textContent = n + (n === 1 ? ' item' : ' itens');
      vazio.hidden = n > 0;
    }
    form.addEventListener('input', aplicar); form.addEventListener('change', aplicar);
    form.addEventListener('submit', function (e) { e.preventDefault(); aplicar(); });
    aplicar();
  }

  /* ---------- "Isto ficou confuso" ---------- */
  function lerConfusoes() { try { return JSON.parse(ler(CHAVE_CONFUSOES) || '[]'); } catch (e) { return []; } }
  function nomeDaTela() { return document.body.dataset.tela || document.title; }
  function resumoTexto() {
    var lista = lerConfusoes();
    if (!lista.length) return 'Nenhum ponto anotado.';
    return 'Pontos que ficaram confusos (' + lista.length + '):\n' + lista.map(function (c, i) { return (i + 1) + '. [' + c.tela + '] ' + c.texto + ' (' + c.quando + ')'; }).join('\n');
  }
  function desenharResumo() {
    var area = $('#resumo-confuso'); if (area) area.value = resumoTexto();
    var n = $('#total-confuso'); if (n) n.textContent = String(lerConfusoes().length);
  }
  function iniciarConfuso() {
    var dlg = null;
    function montar() {
      dlg = criar('dialog', { 'aria-labelledby': 'dlg-titulo' });
      dlg.innerHTML = '<form method="dialog"><h2 id="dlg-titulo">Isto ficou confuso</h2>' +
        '<p>Tela: <strong id="dlg-tela"></strong></p>' +
        '<div class="campo"><label for="dlg-texto">O que ficou confuso?</label><p class="ajuda" id="dlg-ajuda">Escreva em poucas palavras o que você esperava e o que aconteceu.</p>' +
        '<textarea id="dlg-texto" class="form-control" rows="4" data-max="300" aria-describedby="dlg-ajuda"></textarea></div>' +
        '<div class="acoes"><button type="submit" class="btn btn-primary" value="anotar">Anotar</button><button type="button" class="btn btn-outline-secondary" id="dlg-cancelar">Cancelar</button></div></form>';
      document.body.appendChild(dlg);
      var t = $('#dlg-texto', dlg);
      var c = criar('p', { 'class': 'contador', id: 'dlg-texto-contador' }); t.insertAdjacentElement('afterend', c); t._contador = c; descrever(t, c.id); atualizarContador(t);
      t.addEventListener('input', function () { atualizarContador(t); });
      $('#dlg-cancelar', dlg).addEventListener('click', function () { dlg.close(); });
      dlg.addEventListener('submit', function (e) {
        var texto = t.value.trim().slice(0, 300);
        if (!texto) { e.preventDefault(); t.setAttribute('aria-invalid', 'true'); var er = erroDe(t); er.textContent = 'Escreva o que ficou confuso antes de anotar.'; er.hidden = false; t.focus(); return; }
        var lista = lerConfusoes(); lista.push({ tela: nomeDaTela(), texto: texto, quando: new Date().toLocaleString('pt-BR') });
        guardar(CHAVE_CONFUSOES, JSON.stringify(lista)); t.value = ''; atualizarContador(t); desenharResumo();
        anunciar('Anotado. Total: ' + lista.length + '.');
      });
    }
    $$('[data-abrir-confuso]').forEach(function (b) {
      b.addEventListener('click', function (e) {
        e.preventDefault();
        if (!dlg) montar();
        if (typeof dlg.showModal !== 'function') { location.href = 'index.html#anotacoes'; return; }
        $('#dlg-tela', dlg).textContent = nomeDaTela(); var er = $('#dlg-texto-erro', dlg); if (er) er.hidden = true; $('#dlg-texto', dlg).removeAttribute('aria-invalid');
        dlg.showModal(); $('#dlg-texto', dlg).focus();
      });
    });
    var area = $('#resumo-confuso');
    if (area) {
      desenharResumo();
      var st = $('#status-resumo');
      $('#copiar-resumo').addEventListener('click', function () {
        area.select();
        var ok = navigator.clipboard && navigator.clipboard.writeText ? navigator.clipboard.writeText(area.value) : Promise.reject();
        ok.then(function () { st.textContent = 'Resumo copiado.'; }, function () { st.textContent = 'Não consegui copiar sozinho. O texto está selecionado: copie com o menu do celular.'; area.focus(); area.select(); });
      });
      $('#apagar-anotacoes').addEventListener('click', function () { guardar(CHAVE_CONFUSOES, '[]'); desenharResumo(); st.textContent = 'Anotações apagadas.'; });
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();
})();

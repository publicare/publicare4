/* Interações do protótipo. Sem rede, sem framework. As regras puras estão em regras.js. */
(function () {
  'use strict';
  var R = window.PblRegras;
  var CHAVE_CONFUSOES = 'pbl-prototipo-confusoes';
  var CHAVE_TEMA = 'pbl-tema';

  /* ---------- utilitários ---------- */
  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function guardar(chave, valor) { try { localStorage.setItem(chave, valor); } catch (e) { /* modo privado */ } }
  function ler(chave) { try { return localStorage.getItem(chave); } catch (e) { return null; } }
  function hora() { var d = new Date(); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
  function agoraLocal() { var d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 16); }
  function criar(tag, atributos, texto) {
    var e = document.createElement(tag);
    Object.keys(atributos || {}).forEach(function (k) { e.setAttribute(k, atributos[k]); });
    if (texto != null) e.textContent = texto;
    return e;
  }
  function anunciar(texto) {
    var t = $('#toast');
    if (!t) return;
    t.textContent = texto;
    t.hidden = false;
    clearTimeout(anunciar.id);
    anunciar.id = setTimeout(function () { t.hidden = true; }, 4000);
  }

  /* ---------- tema ---------- */
  function iniciarTema() {
    var b = $('[data-tema-alternar]');
    if (!b) return;
    function rotular() {
      var escuro = document.documentElement.getAttribute('data-tema') === 'escuro';
      b.textContent = escuro ? 'Tema claro' : 'Tema escuro';
    }
    rotular();
    b.addEventListener('click', function () {
      var novo = document.documentElement.getAttribute('data-tema') === 'escuro' ? 'claro' : 'escuro';
      document.documentElement.setAttribute('data-tema', novo);
      guardar(CHAVE_TEMA, novo);
      rotular();
    });
  }

  /* ---------- menu no celular ---------- */
  function iniciarMenu() {
    var b = $('.btn-menu'), painel = $('#lateral');
    if (!b || !painel) return;
    painel.setAttribute('data-fechado', '');
    b.setAttribute('aria-expanded', 'false');
    b.addEventListener('click', function () {
      var abrir = painel.hasAttribute('data-fechado');
      if (abrir) painel.removeAttribute('data-fechado'); else painel.setAttribute('data-fechado', '');
      b.setAttribute('aria-expanded', String(abrir));
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !painel.hasAttribute('data-fechado') && b.offsetParent !== null) { painel.setAttribute('data-fechado', ''); b.setAttribute('aria-expanded', 'false'); b.focus(); }
    });
  }

  /* ---------- abas (links; sem JS os painéis ficam empilhados) ---------- */
  function mostrarAba(id) {
    var nav = $('[data-abas]');
    if (!nav) return false;
    var painel = document.getElementById(id);
    if (!painel || !painel.classList.contains('painel')) return false;
    $$('.painel').forEach(function (p) { p.hidden = p !== painel; });
    $$('a', nav).forEach(function (a) {
      if (a.getAttribute('href') === '#' + id) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
    var ativa = $('a[aria-current]', nav);
    if (ativa && ativa.scrollIntoView) ativa.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    if (history.replaceState) history.replaceState(null, '', '#' + id);
    atualizarBarraPorAba();
    return true;
  }
  function iniciarAbas() {
    var nav = $('[data-abas]');
    if (!nav) return;
    var alvo = location.hash.slice(1);
    if (!/^painel-/.test(alvo) || !mostrarAba(alvo)) mostrarAba($('a', nav).getAttribute('href').slice(1));
    nav.addEventListener('click', function (e) {
      var a = e.target.closest('a');
      if (!a) return;
      e.preventDefault();
      mostrarAba(a.getAttribute('href').slice(1));
    });
  }
  function abaDoCampo(campo) {
    var p = campo.closest('.painel');
    return p ? p.id : null;
  }

  /* ---------- interruptores ---------- */
  function iniciarInterruptores() {
    $$('.opcao').forEach(function (op) {
      var chk = $('input[role="switch"]', op), campos = $('.opcao-campos', op);
      if (!chk || !campos) return;
      function aplicar() { campos.hidden = !chk.checked; }
      aplicar();
      chk.addEventListener('change', aplicar);
    });
  }
  function abrirOpcaoDe(campo) {
    var campos = campo.closest('.opcao-campos');
    if (!campos) return;
    var chk = $('input[role="switch"]', campos.parentNode);
    if (chk && !chk.checked) { chk.checked = true; chk.dispatchEvent(new Event('change', { bubbles: true })); }
  }

  /* ---------- contador ---------- */
  function iniciarContadores() {
    $$('[data-contador-de]').forEach(function (c) {
      var campo = document.getElementById(c.getAttribute('data-contador-de'));
      var max = parseInt(c.getAttribute('data-max'), 10);
      if (!campo || !max) return;
      function atualizar() {
        var r = R.contador(campo.value.length, max);
        var antes = c.getAttribute('data-nivel');
        c.textContent = r.texto;
        c.setAttribute('data-nivel', r.nivel);
        if (r.nivel === 'passou' && antes !== 'passou') anunciar('“' + rotuloDe(campo) + '” passou do limite.');
      }
      campo.addEventListener('input', atualizar);
      atualizar();
    });
  }

  /* ---------- prévias de busca ---------- */
  function primeiroValor(ids) {
    var lista = String(ids || '').split(/\s+/).filter(Boolean);
    for (var i = 0; i < lista.length; i++) { var c = document.getElementById(lista[i]); if (c && c.value.trim()) return c.value.trim(); }
    return '';
  }
  function iniciarPrevias() {
    $$('[data-previa]').forEach(function (p) {
      var fontes = (p.getAttribute('data-fontes-titulo') + ' ' + p.getAttribute('data-fontes-desc') + ' ' + (p.getAttribute('data-fontes-sufixo') || '') + ' ' + (p.getAttribute('data-fontes-endereco') || '')).split(/\s+/);
      function atualizar() {
        var t = primeiroValor(p.getAttribute('data-fontes-titulo')) || 'Sem título';
        var suf = primeiroValor(p.getAttribute('data-fontes-sufixo'));
        var d = primeiroValor(p.getAttribute('data-fontes-desc')) || 'Sem descrição. Os buscadores vão escolher um trecho da página.';
        var e = primeiroValor(p.getAttribute('data-fontes-endereco'));
        var pref = p.getAttribute('data-url-prefixo') || 'exemplo.com.br/';
        $('.previa-titulo', p).textContent = R.cortar(t + (suf ? ' ' + suf : ''), 60);
        $('.previa-texto', p).textContent = R.cortar(d, 160);
        var u = $('.previa-url', p); if (u) u.textContent = pref + e;
        var corte = $('.previa-corte', p);
        if (corte) corte.hidden = !((t + (suf ? ' ' + suf : '')).length > 60 || d.length > 160);
      }
      fontes.forEach(function (id) { var c = document.getElementById(id); if (c) c.addEventListener('input', atualizar); });
      atualizar();
    });
  }

  /* ---------- seletor de imagem ---------- */
  var TIPOS_IMAGEM = ['image/png', 'image/jpeg', 'image/webp'];
  function iniciarImagens() {
    $$('[data-arquivo]').forEach(function (caixa) {
      var entrada = $('input[type="file"]', caixa), mini = $('.miniatura', caixa), nome = $('[data-nome]', caixa);
      var tirar = $('[data-tirar]', caixa), escolher = $('[data-escolher]', caixa), erro = $('.erro', caixa.parentNode);
      var vazio = nome.getAttribute('data-vazio') || 'Nenhuma imagem escolhida';
      var original = { nome: nome.textContent };
      function mensagem(m) { erro.textContent = m; if (m) entrada.setAttribute('aria-invalid', 'true'); else entrada.removeAttribute('aria-invalid'); }
      function limpar() { nome.textContent = vazio; mini.textContent = 'Sem imagem'; if (tirar) tirar.hidden = true; entrada.value = ''; mensagem(''); entrada.dispatchEvent(new Event('change', { bubbles: true })); }
      function receber(arq) {
        if (!arq) return;
        if (TIPOS_IMAGEM.indexOf(arq.type) === -1) { mensagem('Use uma imagem PNG, JPG ou WebP.'); return; }
        if (arq.size > 2 * 1024 * 1024) { mensagem('A imagem passa de 2 MB. Escolha uma menor.'); return; }
        mensagem('');
        var leitor = new FileReader();
        leitor.onload = function () {
          var img = criar('img', { alt: '', src: String(leitor.result) });
          mini.textContent = ''; mini.appendChild(img);
          nome.textContent = arq.name;
          if (tirar) tirar.hidden = false;
        };
        leitor.readAsDataURL(arq);
      }
      escolher.addEventListener('click', function () { entrada.click(); });
      entrada.addEventListener('change', function () { if (entrada.files && entrada.files[0]) receber(entrada.files[0]); });
      if (tirar) tirar.addEventListener('click', function () { limpar(); escolher.focus(); });
      ['dragenter', 'dragover'].forEach(function (ev) { caixa.addEventListener(ev, function (e) { e.preventDefault(); caixa.setAttribute('data-arrastando', ''); }); });
      ['dragleave', 'drop'].forEach(function (ev) { caixa.addEventListener(ev, function (e) { e.preventDefault(); caixa.removeAttribute('data-arrastando'); }); });
      caixa.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files[0]) { receber(e.dataTransfer.files[0]); caixa.dispatchEvent(new Event('input', { bubbles: true })); } });
      if (original.nome.trim() === vazio && tirar) tirar.hidden = true;
    });
  }

  /* ---------- etiquetas ---------- */
  function iniciarEtiquetas() {
    $$('[data-tags]').forEach(function (caixa) {
      var entrada = $('input[type="text"]', caixa), oculto = $('input[type="hidden"]', caixa), max = 10;
      var lista = entrada.value.split(',').map(function (t) { return t.trim(); }).filter(Boolean);
      entrada.value = '';
      var aviso = $('.erro', caixa.parentNode);
      function sincronizar() { oculto.value = lista.join(', '); oculto.dispatchEvent(new Event('change', { bubbles: true })); }
      function desenhar() {
        $$('.chip', caixa).forEach(function (c) { c.remove(); });
        lista.forEach(function (t) {
          var chip = criar('span', { 'class': 'chip' });
          chip.appendChild(criar('span', {}, t));
          var b = criar('button', { type: 'button', 'aria-label': 'Remover tag ' + t }, '×');
          b.addEventListener('click', function () { lista = lista.filter(function (x) { return x !== t; }); desenhar(); sincronizar(); entrada.focus(); });
          chip.appendChild(b);
          caixa.insertBefore(chip, entrada);
        });
        $$('[data-sugestao]', caixa.parentNode).forEach(function (b) { b.hidden = lista.indexOf(b.getAttribute('data-sugestao')) !== -1; });
      }
      function adicionar(t) {
        t = t.trim().replace(/,$/, '');
        if (!t) return;
        if (lista.indexOf(t) !== -1) { entrada.value = ''; return; }
        if (lista.length >= max) { aviso.textContent = 'No máximo ' + max + ' tags. Remova uma para adicionar outra.'; return; }
        aviso.textContent = '';
        lista.push(t); entrada.value = ''; desenhar(); sincronizar();
      }
      entrada.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); adicionar(entrada.value); }
        else if (e.key === 'Backspace' && !entrada.value && lista.length) { lista.pop(); desenhar(); sincronizar(); }
      });
      entrada.addEventListener('blur', function () { adicionar(entrada.value); });
      $$('[data-sugestao]', caixa.parentNode).forEach(function (b) { b.addEventListener('click', function () { adicionar(b.getAttribute('data-sugestao')); entrada.focus(); }); });
      oculto.value = lista.join(', ');
      desenhar();
    });
  }

  /* ---------- endereço sugerido pelo título (só em conteúdo novo) ---------- */
  function iniciarEndereco() {
    var t = $('#titulo'), e = $('#endereco');
    if (!t || !e) return;
    var auto = !e.value;
    t.addEventListener('input', function () { if (auto) { e.value = R.slugDe(t.value).slice(0, 60); if (e.hasAttribute('aria-invalid')) validarCampo(e); } });
    e.addEventListener('input', function () { auto = false; });
  }

  /* ---------- seletor de conteúdo com busca (combobox) ---------- */
  function iniciarCombos() {
    $$('[data-combo]').forEach(function (combo) {
      var entrada = $('input[role="combobox"]', combo), lista = $('[role="listbox"]', combo), oculto = $('input[type="hidden"]', combo);
      var opcoes = JSON.parse(combo.getAttribute('data-opcoes') || '[]'), ativo = -1, atraso = null, visiveis = [];
      function fechar() { lista.hidden = true; entrada.setAttribute('aria-expanded', 'false'); entrada.removeAttribute('aria-activedescendant'); ativo = -1; }
      function marcar(i) {
        ativo = i;
        $$('li', lista).forEach(function (li, k) { li.setAttribute('aria-selected', String(k === i)); });
        if (i >= 0) entrada.setAttribute('aria-activedescendant', lista.children[i].id); else entrada.removeAttribute('aria-activedescendant');
      }
      function escolher(o) { entrada.value = o.titulo; oculto.value = o.id; oculto.dispatchEvent(new Event('change', { bubbles: true })); fechar(); }
      function buscar() {
        var t = entrada.value.trim();
        oculto.value = '';
        if (t.length < 2) { fechar(); return; }
        visiveis = opcoes.filter(function (o) { return R.combina({ titulo: o.titulo + ' ' + o.caminho, estado: 'todos' }, t, 'todos'); });
        lista.textContent = '';
        visiveis.forEach(function (o, i) {
          var li = criar('li', { role: 'option', id: lista.id + '-' + i, 'aria-selected': 'false' }, o.titulo);
          li.appendChild(criar('small', {}, o.caminho));
          li.addEventListener('mousedown', function (e) { e.preventDefault(); escolher(o); });
          lista.appendChild(li);
        });
        if (!visiveis.length) lista.appendChild(criar('li', { role: 'presentation' }, 'Nada com “' + t + '”.'));
        lista.hidden = false; entrada.setAttribute('aria-expanded', 'true'); marcar(-1);
      }
      entrada.addEventListener('input', function () { clearTimeout(atraso); atraso = setTimeout(buscar, 250); });
      entrada.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); if (lista.hidden) buscar(); else marcar(Math.min(ativo + 1, visiveis.length - 1)); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); marcar(Math.max(ativo - 1, 0)); }
        else if (e.key === 'Enter' && !lista.hidden && ativo >= 0) { e.preventDefault(); escolher(visiveis[ativo]); }
        else if (e.key === 'Escape' && !lista.hidden) { e.preventDefault(); fechar(); }
      });
      entrada.addEventListener('blur', function () { setTimeout(fechar, 100); });
    });
  }

  /* ---------- validação ---------- */
  function rotuloDe(campo) {
    var l = campo.id && $('label[for="' + campo.id + '"]');
    if (!l) return campo.getAttribute('aria-label') || 'Este campo';
    var n = l.firstChild;
    return (n && n.textContent.trim()) || l.textContent.trim();
  }
  function oculto(c) { return !!c.closest('[hidden]:not(.painel)'); }
  function controles(ctx) {
    return $$('input:not([type=hidden]):not([type=file]):not([type=checkbox]):not([type=radio]):not([type=search]):not([role=combobox]), textarea, select', ctx)
      .filter(function (c) { return c.id && !c.disabled && !c.readOnly && !c.closest('dialog'); });
  }
  function marcarCampo(campo, msg) {
    var e = document.getElementById(campo.id + '-erro');
    if (e) e.textContent = msg;
    if (msg) campo.setAttribute('aria-invalid', 'true'); else campo.removeAttribute('aria-invalid');
  }
  function validarCampo(campo) {
    if (oculto(campo)) { marcarCampo(campo, ''); return ''; }
    var d = campo.dataset;
    var msg = R.validarValor(campo.value, {
      rotulo: rotuloDe(campo), obrigatorio: 'obrigatorio' in d, max: d.max ? parseInt(d.max, 10) : 0, tipo: d.tipo,
      min: d.min != null ? parseInt(d.min, 10) : null, maxValor: d.maxValor != null ? parseInt(d.maxValor, 10) : null
    });
    if (!msg && campo.id === 'tirar-do-ar') {
      var agendar = $('#agendar'), pub = $('#publicar-em');
      msg = R.validarIntervalo(agendar && agendar.checked && pub ? pub.value : agoraLocal(), campo.value);
    }
    marcarCampo(campo, msg);
    return msg;
  }
  function iniciarValidacaoAoVivo(form) {
    controles(form).forEach(function (c) {
      c.addEventListener('blur', function () { if (c.value || c.hasAttribute('aria-invalid')) validarCampo(c); });
      c.addEventListener('input', function () { if (c.hasAttribute('aria-invalid')) validarCampo(c); });
      c.addEventListener('change', function () { validarCampo(c); });
    });
    ['agendar', 'publicar-em'].forEach(function (id) {
      var c = document.getElementById(id), saida = $('#tirar-do-ar');
      if (c && saida) c.addEventListener('change', function () { if (saida.hasAttribute('aria-invalid') || saida.value) validarCampo(saida); });
    });
  }
  function validarForm(form, escopo) {
    var erros = [];
    controles(escopo || form).forEach(function (c) { var m = validarCampo(c); if (m) erros.push({ campo: c, msg: m }); });
    return erros;
  }
  function marcarAbas(erros) {
    $$('[data-abas] a').forEach(function (a) {
      var velho = $('.marca-aba', a); if (velho) velho.remove();
      var n = erros.filter(function (e) { return '#' + abaDoCampo(e.campo) === a.getAttribute('href'); }).length;
      if (n) { var m = criar('span', { 'class': 'marca-aba' }, '•' + n); m.appendChild(criar('span', { 'class': 'so-leitor' }, n === 1 ? ' erro' : ' erros')); a.appendChild(m); }
    });
  }
  function mostrarResumo(form, erros, acao) {
    var r = $('#resumo-erros', form);
    if (!r) return;
    if (!erros.length) { r.hidden = true; r.textContent = ''; return; }
    r.textContent = '';
    var corpo = criar('div');
    corpo.appendChild(criar('h2', {}, 'Corrija ' + (erros.length === 1 ? '1 campo' : erros.length + ' campos') + ' antes de ' + acao + '.'));
    var ul = criar('ul');
    erros.forEach(function (e) {
      var li = criar('li'), a = criar('a', { href: '#' + e.campo.id }, e.msg);
      a.addEventListener('click', function (ev) { ev.preventDefault(); irParaCampo(e.campo); });
      li.appendChild(a); ul.appendChild(li);
    });
    corpo.appendChild(ul); r.appendChild(corpo);
    r.hidden = false; r.focus();
  }
  function irParaCampo(campo) {
    var aba = abaDoCampo(campo);
    if (aba) mostrarAba(aba);
    abrirOpcaoDe(campo);
    var det = campo.closest('details'); if (det) det.open = true;
    campo.focus();
  }

  /* ---------- estado salvo / não salvo, Salvar e Publicar ---------- */
  var sujos = {};
  function painelAtivo() { var a = $('[data-abas] a[aria-current]'); return a ? a.getAttribute('href').slice(1) : null; }
  function nomeDaAba(id) { var a = $('[data-abas] a[href="#' + id + '"]'); return a ? a.firstChild.textContent.trim() : ''; }
  function definirEstado(form, estado, texto) {
    var e = $('.estado-salvo', form);
    if (!e) return;
    e.setAttribute('data-estado', estado);
    e.textContent = texto;
  }
  function atualizarBarraPorAba() {
    var form = $('form[data-edicao]');
    if (!form || form.getAttribute('data-edicao') !== 'abas-independentes') return;
    var id = painelAtivo(), s = $('[data-rotulo-salvar]', form), nome = nomeDaAba(id);
    if (s && nome) s.textContent = 'Salvar ' + nome.toLowerCase();
    if (form._dirty !== undefined) refletirSujos(form);
  }
  function refletirSujos(form) {
    var independentes = form.getAttribute('data-edicao') === 'abas-independentes';
    var ids = Object.keys(sujos);
    if (independentes) {
      $$('[data-abas] a').forEach(function (a) {
        var velho = $('.marca-aba--aviso', a); if (velho) velho.remove();
        if (sujos[a.getAttribute('href').slice(1)]) a.appendChild(criar('span', { 'class': 'marca-aba marca-aba--aviso' }, '• não salvo'));
      });
      var atual = painelAtivo();
      if (!ids.length) definirEstado(form, 'salvo', form._ultimo || 'Tudo salvo');
      else if (sujos[atual]) definirEstado(form, 'alterado', 'Alterações não salvas nesta aba');
      else definirEstado(form, 'alterado', 'Há alterações não salvas em ' + ids.map(function (i) { return nomeDaAba(i); }).join(', '));
    } else if (!ids.length) definirEstado(form, 'salvo', form._ultimo || 'Tudo salvo');
    else definirEstado(form, 'alterado', 'Alterações não salvas');
  }
  function iniciarEdicao() {
    var form = $('form[data-edicao]');
    if (!form) return;
    var independentes = form.getAttribute('data-edicao') === 'abas-independentes';
    form._dirty = false;
    form._ultimo = $('.estado-salvo', form).textContent;
    form.addEventListener('submit', function (e) { e.preventDefault(); });
    iniciarValidacaoAoVivo(form);
    function marcar(e) {
      if (e.target.closest('.busca, dialog, [data-ignorar]')) return;
      var p = e.target.closest('.painel');
      sujos[independentes && p ? p.id : 'form'] = true;
      form._dirty = true;
      refletirSujos(form);
    }
    form.addEventListener('input', marcar);
    form.addEventListener('change', marcar);
    window.addEventListener('beforeunload', function (e) { if (Object.keys(sujos).length) { e.preventDefault(); e.returnValue = ''; } });

    var botoes = $$('[data-acao]', form);
    function bloquear(sim) { botoes.forEach(function (b) { b.disabled = sim; }); }
    function executar(acao) {
      var escopo = independentes ? document.getElementById(painelAtivo()) : form;
      var erros = validarForm(form, escopo);
      marcarAbas(erros);
      mostrarResumo(form, erros, acao === 'publicar' ? 'publicar' : 'salvar');
      if (erros.length) { definirEstado(form, 'erro', 'Não salvou: corrija ' + (erros.length === 1 ? '1 campo' : erros.length + ' campos') + '.'); return; }
      definirEstado(form, 'gravando', acao === 'publicar' ? 'Publicando…' : 'Salvando…');
      bloquear(true);
      setTimeout(function () {
        bloquear(false);
        var versao = parseInt(form.getAttribute('data-versao') || '0', 10);
        var msg;
        if (independentes) { delete sujos[painelAtivo()]; msg = 'Salvo às ' + hora() + ' · ' + nomeDaAba(painelAtivo()); }
        else { sujos = {}; if (versao) { form.setAttribute('data-versao', String(versao + 1)); } msg = 'Salvo às ' + hora() + (versao ? ' · versão ' + (versao + 1) : ''); }
        if (acao === 'publicar') msg = aplicarPublicacao(form) + ' às ' + hora();
        if (acao === 'tirar') msg = aplicarTirarDoAr(form);
        form._ultimo = msg;
        refletirSujos(form);
        definirEstado(form, Object.keys(sujos).length ? 'alterado' : 'salvo', Object.keys(sujos).length ? $('.estado-salvo', form).textContent : msg);
        anunciar(msg);
      }, 600);
    }
    botoes.forEach(function (b) {
      b.addEventListener('click', function () {
        var a = b.getAttribute('data-acao');
        executar(a === 'enviar-revisao' ? 'salvar' : a);
      });
    });
    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); executar('salvar'); }
    });
    $$('#agendar', form).forEach(function (c) { c.addEventListener('change', function () { rotularPublicar(form); }); });
    rotularPublicar(form);
    atualizarBarraPorAba();
  }
  function rotularPublicar(form) {
    var b = $('[data-acao="publicar"]', form), ag = $('#agendar', form);
    if (b) b.textContent = ag && ag.checked ? 'Agendar publicação' : 'Publicar';
  }
  function definirSelo(form, texto, classe) {
    $$('[data-selo-estado]').forEach(function (s) { s.textContent = texto; s.className = 'selo ' + classe; });
  }
  function aplicarPublicacao(form) {
    var ag = $('#agendar', form), pub = $('#publicar-em', form), val = $('#validade', form), saida = $('#tirar-do-ar', form);
    var futuro = ag && ag.checked && pub && pub.value && pub.value > agoraLocal();
    var sai = val && val.checked && saida && saida.value ? ' Sai do ar em ' + R.dataBr(saida.value) + '.' : '';
    form.setAttribute('data-estado-conteudo', futuro ? 'agendado' : 'publicado');
    $$('[data-so-privado]', form).forEach(function (e) { e.hidden = true; });
    $$('[data-so-publicado]', form).forEach(function (e) { e.hidden = false; });
    var salvar = $('[data-acao="salvar"]', form); if (salvar) { salvar.className = 'btn btn--principal'; salvar.textContent = 'Salvar alterações'; }
    definirSelo(form, futuro ? 'Agendado' : 'Publicado', futuro ? 'selo--aviso' : 'selo--ok');
    var txt = $('[data-estado-texto]', form);
    if (txt) txt.textContent = futuro ? 'Entra no ar em ' + R.dataBr(pub.value) + '.' + sai : 'Já está visível no site.' + sai;
    return (futuro ? 'Agendado para ' + R.dataBr(pub.value) : 'Publicado') + (sai ? '.' + sai.replace(/\.$/, '') : '');
  }
  function aplicarTirarDoAr(form) {
    form.setAttribute('data-estado-conteudo', 'privado');
    $$('[data-so-privado]', form).forEach(function (e) { e.hidden = false; });
    $$('[data-so-publicado]', form).forEach(function (e) { e.hidden = true; });
    var salvar = $('[data-acao="salvar"]', form); if (salvar) { salvar.className = 'btn btn--secundario'; salvar.textContent = 'Salvar rascunho'; }
    definirSelo(form, 'Privado', '');
    var txt = $('[data-estado-texto]', form); if (txt) txt.textContent = 'Só quem tem conta vê.';
    return 'Voltou para Privado às ' + hora();
  }

  /* ---------- lista: filtros, busca e ações na linha ---------- */
  function iniciarFiltros() {
    var caixa = $('[data-filtros]');
    var texto = $('[data-filtro-texto]');
    var itens = $$('[data-item]');
    if (!itens.length || (!caixa && !texto)) return;
    var estado = 'todos';
    var vazio = $('[data-vazio]'), cont = $('[data-contagem]');
    function aplicar() {
      var n = 0;
      itens.forEach(function (li) {
        var ok = R.combina({ titulo: li.getAttribute('data-titulo'), estado: li.getAttribute('data-estado') }, texto ? texto.value : '', estado);
        li.hidden = !ok; if (ok) n++;
      });
      $$('[data-grupo]').forEach(function (g) { g.hidden = !$$('[data-item]:not([hidden])', g).length; });
      if (cont) cont.textContent = n === 0 ? 'Nenhum resultado.' : (n === 1 ? '1 resultado' : n + ' resultados');
      if (vazio) vazio.hidden = n !== 0;
    }
    if (caixa) caixa.addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      $$('button', caixa).forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); });
      estado = b.getAttribute('data-estado'); aplicar();
    });
    if (texto) texto.addEventListener('input', aplicar);
    var q = new URLSearchParams(location.search).get('q');
    if (q && texto) texto.value = q;
    var f = new URLSearchParams(location.search).get('estado');
    if (f && caixa) { var b = $('button[data-estado="' + f + '"]', caixa); if (b) b.click(); }
    aplicar();
  }

  function iniciarRevelar() {
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-revela]');
      if (!b) return;
      var alvo = document.getElementById(b.getAttribute('data-revela'));
      if (!alvo) return;
      var abrir = alvo.hidden;
      alvo.hidden = !abrir;
      b.setAttribute('aria-expanded', String(abrir));
      if (abrir) { var c = alvo.querySelector('input, textarea, button'); if (c) c.focus(); }
    });
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-fecha]');
      if (!b) return;
      var alvo = document.getElementById(b.getAttribute('data-fecha'));
      if (alvo) { alvo.hidden = true; var abre = $('[data-revela="' + alvo.id + '"]'); if (abre) { abre.setAttribute('aria-expanded', 'false'); abre.focus(); } }
    });
  }
  function selo(texto, classe) { return criar('span', { 'class': 'selo' + (classe ? ' ' + classe : '') }, texto); }
  var EFEITOS = {
    recuperar: function (li) {
      li.setAttribute('data-estado', 'privado');
      var s = $('.selo', li); s.className = 'selo'; s.textContent = 'Privado';
      return 'Recuperado como Privado. Para mostrar no site, abra e publique.';
    },
    aprovar: function (li) { var s = $('.selo', li); if (s) { s.className = 'selo selo--ok'; s.textContent = 'Publicado'; } contarRevisao(-1); return 'Aprovado e publicado.'; },
    devolver: function (li) { var s = $('.selo', li); if (s) { s.className = 'selo selo--aviso'; s.textContent = 'Devolvido'; } contarRevisao(-1); return 'Devolvido com o seu motivo. Quem enviou será avisada.'; },
    restaurar: function () { return 'Restauração simulada. Nada foi alterado de verdade.'; },
    pele: function (li) {
      $$('[data-em-uso]').forEach(function (s) { s.remove(); });
      var fim = $('.item-fim', li); fim.insertBefore(selo('Em uso', 'selo--ok'), fim.firstChild); fim.querySelectorAll('[data-revela]').forEach(function (b) { b.hidden = true; });
      li.setAttribute('data-em-uso', ''); return 'Pele trocada. (Simulação: o site de verdade não mudou.)';
    },
    enviar: function (li) { var s = $('.selo', li); if (s) { s.className = 'selo selo--aviso'; s.textContent = 'Em revisão'; } return 'Enviado para revisão. Ainda não está no ar.'; }
  };
  function contarRevisao(delta) {
    $$('[data-contagem-revisar]').forEach(function (c) { var n = Math.max(0, parseInt(c.textContent, 10) + delta); c.textContent = String(n); if (!n) c.hidden = true; });
  }
  function iniciarAcoesDeLinha() {
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-efeito]');
      if (!b) return;
      var li = b.closest('[data-linha]');
      var caixa = b.closest('[data-confirmacao]');
      var ok = true;
      if (caixa) $$('[data-exigir]', caixa).forEach(function (c) {
        var falta = c.type === 'checkbox' ? !c.checked : !c.value.trim();
        var erro = document.getElementById(c.id + '-erro');
        if (erro) erro.textContent = falta ? c.getAttribute('data-exigir') : '';
        if (falta) { c.setAttribute('aria-invalid', 'true'); if (ok) c.focus(); ok = false; } else c.removeAttribute('aria-invalid');
      });
      if (!ok) return;
      var fn = EFEITOS[b.getAttribute('data-efeito')];
      var msg = fn ? fn(li) : 'Feito.';
      var alvo = $('[data-msg]', li);
      if (alvo) { alvo.textContent = msg; alvo.hidden = false; }
      $$('[data-confirmacao]', li).forEach(function (c) { c.hidden = true; });
      anunciar(msg);
    });
  }
  function iniciarAtualizacao() {
    var b = $('[data-atualizar]');
    if (!b) return;
    var barra = $('progress', document), msg = $('#atualizar-msg'), senha = $('#senha-atualizar'), erro = $('#senha-atualizar-erro');
    b.addEventListener('click', function () {
      if (!senha.value.trim()) { erro.textContent = 'Confirme com a sua senha.'; senha.setAttribute('aria-invalid', 'true'); senha.focus(); return; }
      erro.textContent = ''; senha.removeAttribute('aria-invalid');
      b.disabled = true; barra.hidden = false; barra.value = 0; msg.textContent = 'Atualizando…';
      var t = setInterval(function () {
        barra.value += 20;
        if (barra.value >= 100) { clearInterval(t); msg.textContent = 'Simulação concluída. A versão não mudou de verdade.'; b.disabled = false; }
      }, 400);
    });
  }
  function iniciarNovaCopia() {
    var b = $('[data-nova-copia]');
    if (!b) return;
    b.addEventListener('click', function () {
      var ul = $('#lista-copias');
      var li = criar('li', { 'data-linha': '' });
      var d = criar('div', { 'class': 'item' }), t = criar('div', { 'class': 'item-texto' });
      t.appendChild(criar('span', { 'class': 'item-titulo' }, 'Cópia de hoje, ' + hora()));
      t.appendChild(criar('span', { 'class': 'item-meta' }, 'Acabou de ser criada · 41 MB · feita por Ana'));
      d.appendChild(t); li.appendChild(d); ul.insertBefore(li, ul.firstChild);
      var vazio = $('[data-vazio]'); if (vazio) vazio.hidden = true;
      anunciar('Cópia criada às ' + hora() + '.');
    });
  }

  /* ---------- busca global ---------- */
  function iniciarAtalhos() {
    document.addEventListener('keydown', function (e) {
      if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
      var a = document.activeElement;
      if (a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) return;
      var campo = $('.busca input');
      if (campo) { e.preventDefault(); var l = $('#lateral'); if (l && l.hasAttribute('data-fechado')) { var m = $('.btn-menu'); if (m && m.offsetParent) m.click(); } campo.focus(); }
    });
  }

  /* ---------- "Isto ficou confuso" ---------- */
  function lerConfusoes() { try { var v = JSON.parse(ler(CHAVE_CONFUSOES) || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; } }
  function nomeDaTela() { return document.body.getAttribute('data-tela') || document.title; }
  function resumoTexto() {
    var l = lerConfusoes();
    if (!l.length) return '';
    return l.map(function (c, i) { return (i + 1) + '. [' + c.tela + '] ' + c.texto; }).join('\n');
  }
  function desenharResumo() {
    var t = $('#resumo-confusoes');
    if (!t) return;
    var txt = resumoTexto();
    t.value = txt;
    var vazio = $('#resumo-vazio'); if (vazio) vazio.hidden = !!txt;
    $$('[data-so-com-notas]').forEach(function (e) { e.hidden = !txt; });
  }
  function iniciarConfuso() {
    var dlg = $('#dlg-confuso');
    if (dlg && typeof dlg.showModal === 'function') {
      var campo = $('textarea', dlg), tela = $('[data-tela-nome]', dlg), cont = $('[data-contador-confuso]', dlg), msg = $('.erro', dlg);
      $$('[data-confuso]').forEach(function (a) {
        a.addEventListener('click', function (e) {
          e.preventDefault();
          tela.textContent = nomeDaTela(); campo.value = ''; msg.textContent = ''; cont.textContent = '0 de 300';
          dlg.showModal(); campo.focus();
        });
      });
      campo.addEventListener('input', function () { var r = R.contador(campo.value.length, 300); cont.textContent = r.texto; cont.setAttribute('data-nivel', r.nivel); });
      $('[data-anotar]', dlg).addEventListener('click', function () {
        var t = campo.value.trim();
        if (!t) { msg.textContent = 'Escreva o que ficou confuso.'; campo.setAttribute('aria-invalid', 'true'); campo.focus(); return; }
        if (t.length > 300) { msg.textContent = 'Passou de 300 caracteres. Resuma um pouco.'; campo.focus(); return; }
        campo.removeAttribute('aria-invalid');
        var l = lerConfusoes(); l.push({ tela: nomeDaTela(), texto: t, quando: new Date().toISOString() });
        guardar(CHAVE_CONFUSOES, JSON.stringify(l));
        dlg.close(); desenharResumo(); anunciar('Anotado. Você vê o resumo no fim do roteiro.');
      });
      $('[data-cancelar]', dlg).addEventListener('click', function () { dlg.close(); });
    }
    var copiar = $('#copiar-resumo'), apagar = $('#apagar-confusoes'), msg2 = $('#resumo-msg'), area = $('#resumo-confusoes');
    if (copiar) copiar.addEventListener('click', function () {
      area.select();
      var ok = function () { msg2.textContent = 'Copiado.'; };
      var falha = function () { msg2.textContent = 'Não consegui copiar sozinho. O texto está selecionado: use Copiar do seu aparelho.'; };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(area.value).then(ok, falha);
      else { try { document.execCommand('copy') ? ok() : falha(); } catch (e) { falha(); } }
    });
    if (apagar) apagar.addEventListener('click', function () { guardar(CHAVE_CONFUSOES, '[]'); desenharResumo(); msg2.textContent = 'Anotações apagadas.'; });
    desenharResumo();
  }

  document.addEventListener('DOMContentLoaded', function () {
    iniciarTema(); iniciarMenu(); iniciarAbas(); iniciarInterruptores(); iniciarContadores(); iniciarPrevias();
    iniciarImagens(); iniciarEtiquetas(); iniciarEndereco(); iniciarCombos(); iniciarEdicao(); iniciarFiltros(); iniciarRevelar();
    iniciarAcoesDeLinha(); iniciarAtualizacao(); iniciarNovaCopia(); iniciarAtalhos(); iniciarConfuso();
  });
}());

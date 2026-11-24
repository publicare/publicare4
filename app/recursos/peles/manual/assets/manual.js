/* Manual: melhorias opcionais. Sem este arquivo o manual continua legível e navegável (menu, busca e links são HTML puro). */
(function () {
  'use strict';
  var raiz = document.documentElement;

  function temaAtual() {
    var t = raiz.getAttribute('data-tema');
    if (t) { return t; }
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'escuro' : 'claro';
  }

  var botaoTema = document.querySelector('[data-manual-tema]');
  function rotular() {
    if (!botaoTema) { return; }
    var escuro = temaAtual() === 'escuro';
    botaoTema.textContent = escuro ? 'Tema claro' : 'Tema escuro';
    botaoTema.setAttribute('aria-pressed', escuro ? 'true' : 'false');
  }
  if (botaoTema) {
    botaoTema.hidden = false;
    rotular();
    botaoTema.addEventListener('click', function () {
      var novo = temaAtual() === 'escuro' ? 'claro' : 'escuro';
      raiz.setAttribute('data-tema', novo);
      try { localStorage.setItem('manual-tema', novo); } catch (e) { /* sem armazenamento */ }
      rotular();
    });
  }

  var botaoImprimir = document.querySelector('[data-manual-imprimir]');
  if (botaoImprimir) {
    botaoImprimir.hidden = false;
    botaoImprimir.addEventListener('click', function () { window.print(); });
  }

  /* Os títulos chegam sem id (o HTML do conteúdo é limpo ao salvar). Cada item de "Nesta página" aponta para #id, na mesma ordem
     dos títulos h2 e h3 do texto: aqui o id volta para o título certo, e o link de âncora passa a funcionar. */
  var corpo = document.querySelector('[data-manual-corpo]');
  var toc = document.querySelector('.toc');
  if (corpo && toc) {
    var links = toc.querySelectorAll('a[href^="#"]');
    var titulos = corpo.querySelectorAll('h2, h3');
    for (var i = 0; i < links.length && i < titulos.length; i++) {
      titulos[i].id = links[i].getAttribute('href').slice(1);
    }
    if (location.hash.length > 1) {
      var alvo = document.getElementById(decodeURIComponent(location.hash.slice(1)));
      if (alvo) { alvo.scrollIntoView(); }
    }
  }

  /* Marca onde você está: a página no menu lateral e a edição no seletor. */
  var caminho = location.pathname.replace(/\/?$/, '/');
  var atual = null;
  var itens = document.querySelectorAll('.lateral a');
  for (var j = 0; j < itens.length; j++) {
    if (itens[j].pathname.replace(/\/?$/, '/') === caminho) {
      itens[j].setAttribute('aria-current', 'page');
      atual = itens[j];
    }
  }
  if (atual && atual.scrollIntoView) { atual.scrollIntoView({ block: 'nearest' }); }
  var edicoes = document.querySelectorAll('.edicoes a');
  var melhor = null;
  for (var k = 0; k < edicoes.length; k++) {
    var prefixo = edicoes[k].getAttribute('data-edicao');
    if (prefixo && caminho.indexOf(prefixo) === 0 && (!melhor || prefixo.length > melhor.getAttribute('data-edicao').length)) { melhor = edicoes[k]; }
  }
  if (melhor) { melhor.setAttribute('aria-current', 'true'); }
})();

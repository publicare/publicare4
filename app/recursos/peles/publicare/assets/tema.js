/* Pele "publicare": tema claro, escuro ou automático (o do aparelho). Guarda só a escolha, no próprio navegador (localStorage),
   e a aplica em <html data-tema>. Sem JavaScript o site segue o aparelho. Arquivo local, sem inline (CSP script-src 'self'). */
(function () {
  'use strict';
  var CHAVE = 'pbl-tema';
  var raiz = document.documentElement;
  var NOMES = { '': 'Automático', claro: 'Claro', escuro: 'Escuro' };
  var ORDEM = ['', 'claro', 'escuro'];

  function ler() {
    try {
      var v = localStorage.getItem(CHAVE);
      return v === 'claro' || v === 'escuro' ? v : '';
    } catch (e) {
      return '';
    }
  }

  function aplicar(v) {
    if (v) {
      raiz.setAttribute('data-tema', v);
    } else {
      raiz.removeAttribute('data-tema');
    }
  }

  aplicar(ler()); /* já no <head>: evita o lampejo do tema errado */

  function iniciar() {
    var botao = document.querySelector('[data-pbl-tema]');
    if (!botao) {
      return;
    }
    var rotulo = botao.querySelector('[data-pbl-tema-rotulo]');
    function mostrar() {
      var v = ler();
      if (rotulo) {
        rotulo.textContent = NOMES[v];
      }
      botao.setAttribute('aria-label', 'Tema: ' + NOMES[v].toLowerCase() + '. Clique para trocar.');
    }
    botao.hidden = false;
    mostrar();
    botao.addEventListener('click', function () {
      var proximo = ORDEM[(ORDEM.indexOf(ler()) + 1) % ORDEM.length];
      try {
        if (proximo) {
          localStorage.setItem(CHAVE, proximo);
        } else {
          localStorage.removeItem(CHAVE);
        }
      } catch (e) {
        /* sem armazenamento: vale só nesta página */
      }
      aplicar(proximo);
      mostrar();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar);
  } else {
    iniciar();
  }
})();

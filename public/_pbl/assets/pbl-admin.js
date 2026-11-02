/* PBL4 — script da administração (ADR 0012, adendo de 2026-11). Arquivo próprio, sem inline; tudo dirigido por atributos data-*. */
(function () {
  'use strict';

  /* data-pbl-visivel-se="id-do-select:valor1,valor2": o elemento só aparece quando o select tem um desses valores. */
  document.querySelectorAll('[data-pbl-visivel-se]').forEach(function (el) {
    var partes = (el.getAttribute('data-pbl-visivel-se') || '').split(':');
    var seletor = document.getElementById(partes[0]);
    if (!seletor) {
      return;
    }
    var valores = (partes[1] || '').split(',');
    var atualizar = function () {
      el.hidden = valores.indexOf(seletor.value) === -1;
    };
    seletor.addEventListener('change', atualizar);
    atualizar();
  });

  /* Campo inválido dentro de painel recolhido: abre o painel, senão o navegador não consegue mostrar o erro nem enviar. */
  document.addEventListener('invalid', function (e) {
    var painel = e.target && e.target.closest ? e.target.closest('.collapse:not(.show)') : null;
    if (!painel) {
      return;
    }
    painel.classList.add('show');
    var botao = painel.id ? document.querySelector('[data-bs-target="#' + painel.id + '"]') : null;
    if (botao) {
      botao.classList.remove('collapsed');
      botao.setAttribute('aria-expanded', 'true');
    }
  }, true);

  /* data-pbl-abas: o servidor entrega todos os painéis (empilhados, sem JavaScript) e a aba ativa com `active show`. Aqui o script
     liga o modo de abas: mostra a barra, esconde os títulos dos painéis e põe tab-content/tab-pane (o que faz o Bootstrap esconder
     os inativos). A aba escolhida vai para ?aba= (replaceState), para sobreviver a recarregar e a novo acesso. */
  document.querySelectorAll('[data-pbl-abas]').forEach(function (raiz) {
    var nav = raiz.querySelector('[data-pbl-abas-nav]');
    var paineis = raiz.querySelector('[data-pbl-abas-paineis]');
    if (!nav || !paineis) {
      return;
    }
    nav.hidden = false;
    paineis.classList.add('tab-content');
    paineis.querySelectorAll('.pbl-aba-painel').forEach(function (p) {
      p.classList.add('tab-pane', 'fade');
    });
    paineis.querySelectorAll('.pbl-aba-titulo').forEach(function (t) {
      t.classList.add('visually-hidden');
    });
    nav.addEventListener('shown.bs.tab', function (e) {
      var chave = e.target && e.target.getAttribute('data-pbl-aba');
      if (chave && window.history && history.replaceState) {
        var url = new URL(window.location.href);
        url.searchParams.set('aba', chave);
        url.searchParams.delete('ok');
        history.replaceState(null, '', url.toString());
      }
    });
  });

  /* Campo inválido dentro de painel de aba escondido: mostra a aba, senão o navegador não consegue focar o campo. */
  document.addEventListener('invalid', function (e) {
    var painel = e.target && e.target.closest ? e.target.closest('.tab-pane:not(.active)') : null;
    var botao = painel && painel.id ? document.querySelector('[data-bs-target="#' + painel.id + '"]') : null;
    if (botao && window.bootstrap) {
      window.bootstrap.Tab.getOrCreateInstance(botao).show();
    }
  }, true);
})();

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
})();

/* PBL4 — marca que há JavaScript e aplica o tema ANTES de pintar a página (arquivo próprio, sem inline; ADR 0033).
   Sem este script a página continua completa: menu, painéis, abas e ajudas funcionam só com HTML e CSS. */
(function () {
  'use strict';
  var raiz = document.documentElement;
  raiz.classList.add('js');
  var guardado = null;
  try {
    guardado = window.localStorage.getItem('pbl-tema');
  } catch (e) {
    guardado = null;
  }
  // Prévia descartável: a entrada de prévia pode fixar o tema por cookie (para capturas); sem o cookie, vale a preferência de sempre.
  var daPrevia = /(?:^|; )pbl-tema-previa=(claro|escuro)(?:;|$)/.exec(document.cookie || '');
  if (daPrevia) {
    guardado = daPrevia[1];
  }
  var tema = guardado === 'claro' || guardado === 'escuro' ? guardado : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'escuro' : 'claro');
  raiz.setAttribute('data-bs-theme', tema === 'escuro' ? 'dark' : 'light');
})();

/* Esc fecha a ajuda "?" aberta e devolve o foco a ela (melhoria progressiva: sem JavaScript, Enter/Espaço abre e fecha). */
document.addEventListener('keydown', function (e) {
  'use strict';
  if (e.key !== 'Escape') {
    return;
  }
  var alvo = e.target && e.target.closest ? e.target.closest('details.pbl-ajuda[open]') : null;
  if (!alvo) {
    return;
  }
  alvo.open = false;
  var botao = alvo.querySelector('summary');
  if (botao) {
    botao.focus();
  }
});

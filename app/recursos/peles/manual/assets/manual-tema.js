/* Aplica o tema salvo antes da primeira pintura (evita o "pisca"). Sem preferência salva, vale o tema do sistema. */
(function () {
  try {
    var t = localStorage.getItem('manual-tema');
    if (t === 'claro' || t === 'escuro') { document.documentElement.setAttribute('data-tema', t); }
  } catch (e) { /* armazenamento bloqueado: segue o sistema */ }
})();

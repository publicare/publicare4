/* Aplica o tema antes da primeira pintura (carregado no <head>, sem inline). */
(function () {
  var t = null;
  try { t = localStorage.getItem('pbl-tema'); } catch (e) { /* modo privado */ }
  if (t !== 'claro' && t !== 'escuro') t = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'escuro' : 'claro';
  document.documentElement.setAttribute('data-tema', t);
  document.documentElement.classList.add('js');
}());

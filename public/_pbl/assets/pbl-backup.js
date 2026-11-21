/* PBL4 — cópias de segurança (/_pbl/copias). Arquivo próprio, sem inline (CSP script-src 'self').
   Ao enviar um formulário [data-pbl-backup-form] (fazer cópia, restaurar), mostra a barra "trabalhando" e desativa o botão para
   evitar envio duplo. A cópia roda dentro do POST; sem JavaScript tudo funciona igual, só sem a barra. */
(function () {
  'use strict';

  var formularios = document.querySelectorAll('[data-pbl-backup-form]');
  Array.prototype.forEach.call(formularios, function (form) {
    form.addEventListener('submit', function () {
      if (!form.checkValidity()) {
        return;
      }
      var andamento = form.querySelector('[data-pbl-backup-andamento]');
      if (andamento) {
        andamento.hidden = false;
      }
      /* Desativa depois do envio começar: um botão desativado dentro do evento não enviaria o formulário. */
      window.setTimeout(function () {
        Array.prototype.forEach.call(form.querySelectorAll('button[type="submit"]'), function (b) {
          b.disabled = true;
        });
      }, 0);
    });
  });
})();

/* PBL4 — passos do assistente de instalação (/_pbl/instalar). Arquivo próprio, sem inline (CSP script-src 'self').
   Sem JavaScript o formulário continua completo: todos os passos aparecem de uma vez e o servidor valida tudo. */
(function () {
  'use strict';

  var form = document.querySelector('form[data-pbl-instalar]');
  if (!form) {
    return;
  }
  var paineis = Array.prototype.slice.call(form.querySelectorAll('[data-passo]'));
  var atual = parseInt(form.getAttribute('data-passo-inicial') || '1', 10) || 1;
  var barra = document.querySelector('progress.pbl-progresso');
  var numero = document.querySelector('[data-pbl-passo-numero]');
  var tituloAtual = document.querySelector('[data-pbl-passo-titulo]');
  var itens = Array.prototype.slice.call(document.querySelectorAll('[data-passo-item]'));

  document.documentElement.classList.add('pbl-instalar-js');
  form.querySelectorAll('.pbl-nav-js, .pbl-mostrar-js, [data-pbl-voltar][hidden]').forEach(function (el) {
    el.hidden = false;
  });

  function campo(nome) {
    var el = form.elements.namedItem(nome);
    return el && 'value' in el ? el.value : '';
  }

  function preencherRevisao() {
    var alvo = form.querySelector('[data-pbl-revisao]');
    if (!alvo) {
      return;
    }
    var modelos = Array.prototype.slice.call(form.querySelectorAll('input[name="modelos[]"]:checked')).map(function (c) {
      var rotulo = form.querySelector('label[for="' + c.id + '"] strong');
      return rotulo ? rotulo.textContent : c.value;
    });
    var linhas = [
      ['Nome do site', campo('site')],
      ['Endereço (domínio)', campo('dominio')],
      ['Login do administrador', campo('login')],
      ['Nome', campo('nome') || '(igual ao login)'],
      ['E-mail', campo('email')],
      ['Senha', campo('senha') ? 'definida (' + campo('senha').length + ' caracteres, não é mostrada)' : 'não preenchida'],
      ['Modelos opcionais', modelos.length ? modelos.join(', ') : 'nenhum']
    ];
    alvo.textContent = '';
    linhas.forEach(function (l) {
      var dt = document.createElement('dt');
      var dd = document.createElement('dd');
      dt.textContent = l[0];
      dd.textContent = l[1]; /* textContent: o que foi digitado nunca vira HTML */
      alvo.appendChild(dt);
      alvo.appendChild(dd);
    });
  }

  function mostrar(n, mover) {
    atual = Math.max(1, Math.min(5, n));
    paineis.forEach(function (p) {
      p.hidden = parseInt(p.getAttribute('data-passo'), 10) !== atual;
    });
    if (barra) {
      barra.value = atual;
    }
    if (numero) {
      numero.textContent = String(atual);
    }
    itens.forEach(function (li) {
      var n2 = parseInt(li.getAttribute('data-passo-item'), 10);
      li.className = 'pbl-passo pbl-passo-' + (n2 < atual ? 'feito' : (n2 === atual ? 'atual' : 'a-fazer'));
      if (n2 === atual) {
        li.setAttribute('aria-current', 'step');
      } else {
        li.removeAttribute('aria-current');
      }
    });
    var painel = paineis.filter(function (p) {
      return !p.hidden;
    })[0];
    var titulo = painel ? painel.querySelector('h2') : null;
    if (tituloAtual && titulo) {
      tituloAtual.textContent = titulo.textContent.replace(/^\d+\.\s*/, '');
    }
    if (atual === 5) {
      preencherRevisao();
    }
    if (mover && titulo) {
      titulo.focus();
      if (titulo.scrollIntoView) {
        titulo.scrollIntoView({ block: 'start' });
      }
    }
  }

  function passoValido() {
    var painel = paineis.filter(function (p) {
      return !p.hidden;
    })[0];
    if (!painel) {
      return true;
    }
    var campos = Array.prototype.slice.call(painel.querySelectorAll('input, select, textarea'));
    for (var i = 0; i < campos.length; i++) {
      if (!campos[i].checkValidity()) {
        campos[i].reportValidity();
        return false;
      }
    }
    if (atual === 3 && campo('senha') !== campo('senha2')) {
      var s2 = form.elements.namedItem('senha2');
      s2.setCustomValidity('As duas senhas não são iguais.');
      s2.reportValidity();
      s2.setCustomValidity('');
      return false;
    }
    return true;
  }

  form.addEventListener('click', function (e) {
    var alvo = e.target && e.target.closest ? e.target.closest('button') : null;
    if (!alvo) {
      return;
    }
    if (alvo.hasAttribute('data-pbl-proximo') && passoValido()) {
      mostrar(atual + 1, true);
    } else if (alvo.hasAttribute('data-pbl-voltar')) {
      mostrar(atual - 1, true);
    }
  });

  /* Enter num campo avança o passo em vez de enviar tudo cedo; só o último passo envia. */
  form.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && atual < 5 && e.target && e.target.tagName === 'INPUT' && e.target.type !== 'checkbox') {
      e.preventDefault();
      if (passoValido()) {
        mostrar(atual + 1, true);
      }
    }
  });

  form.addEventListener('submit', function (e) {
    if (atual < 5) {
      e.preventDefault();
    }
  });

  /* Força da senha: só orienta; a regra de verdade é do servidor (tamanho mínimo e senhas óbvias). */
  var senha = form.elements.namedItem('senha');
  var forca = form.querySelector('[data-pbl-forca]');
  if (senha && forca) {
    senha.addEventListener('input', function () {
      var v = senha.value;
      var n = v.length;
      var classes = (/[a-z]/.test(v) ? 1 : 0) + (/[A-Z]/.test(v) ? 1 : 0) + (/[0-9]/.test(v) ? 1 : 0) + (/[^A-Za-z0-9]/.test(v) ? 1 : 0);
      var texto = '';
      var nivel = '';
      if (n === 0) {
        texto = '';
      } else if (n < 10) {
        texto = 'Muito curta: faltam ' + (10 - n) + ' caractere(s) para o mínimo de 10.';
        nivel = 'fraca';
      } else if (n < 14 && classes < 3) {
        texto = 'Aceitável, mas dá para melhorar: use uma frase mais longa.';
        nivel = 'media';
      } else if (n < 16) {
        texto = 'Boa senha.';
        nivel = 'boa';
      } else {
        texto = 'Senha forte.';
        nivel = 'forte';
      }
      forca.textContent = texto ? 'Força da senha: ' + texto : '';
      forca.className = 'pbl-forca' + (nivel ? ' pbl-forca-' + nivel : '');
    });
  }

  var mostrarSenha = form.querySelector('[data-pbl-mostrar-senha]');
  if (mostrarSenha) {
    mostrarSenha.addEventListener('change', function () {
      ['senha', 'senha2'].forEach(function (nome) {
        var el = form.elements.namedItem(nome);
        if (el) {
          el.type = mostrarSenha.checked ? 'text' : 'password';
        }
      });
    });
  }

  mostrar(atual, atual !== 1);
}());

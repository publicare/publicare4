/* PBL4 — melhoria progressiva dos formulários do painel (ADR 0031). Arquivo próprio, sem inline, JS puro, sem biblioteca.
   Sem este script o formulário continua completo e o servidor continua sendo a autoridade: tudo aqui só espelha as regras dele.
   Nenhum dado digitado vira HTML: texto entra sempre por textContent/value, nunca por innerHTML. */
(function () {
  'use strict';

  var URL_VALIDA = /^[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*$/;
  var PESO_VALIDO = /^-?[0-9]{1,6}$/;

  function todos(raiz, seletor) {
    return Array.prototype.slice.call((raiz || document).querySelectorAll(seletor));
  }

  function criar(tag, classe, texto) {
    var el = document.createElement(tag);
    if (classe) {
      el.className = classe;
    }
    if (texto) {
      el.textContent = texto;
    }
    return el;
  }

  var form = document.querySelector('form[data-pbl-form]');
  var abasRaiz = document.querySelector('[data-pbl-abas-form]');
  var estado = { sujo: false, enviando: false };

  /* ------------------------------------------------------------ abas (um <form>, um POST) */
  var botoesDeAba = [];
  function ativarAba(chave, focar) {
    if (!abasRaiz) {
      return;
    }
    botoesDeAba.forEach(function (b) {
      var on = b.getAttribute('data-pbl-aba') === chave;
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      b.tabIndex = on ? 0 : -1;
      if (on && focar) {
        b.focus();
      }
    });
    todos(abasRaiz, '.pbl-aba-painel').forEach(function (p) {
      if (p.id === 'aba-painel-' + chave) {
        p.setAttribute('data-ativa', '');
      } else {
        p.removeAttribute('data-ativa');
      }
    });
    if (window.history && history.replaceState) {
      var url = new URL(window.location.href);
      url.searchParams.set('aba', chave);
      url.searchParams.delete('salvo');
      history.replaceState(null, '', url.toString());
    }
  }

  function abaDoElemento(el) {
    var painel = el && el.closest ? el.closest('.pbl-aba-painel') : null;
    return painel ? painel.id.replace('aba-painel-', '') : null;
  }

  function mostrarCampo(el) {
    var chave = abaDoElemento(el);
    if (chave) {
      ativarAba(chave, false);
    }
    var detalhes = el.closest ? el.closest('details:not([open])') : null;
    while (detalhes) {
      detalhes.open = true;
      detalhes = detalhes.parentElement ? detalhes.parentElement.closest('details:not([open])') : null;
    }
    var toggle = el.closest ? el.closest('[data-pbl-toggle]') : null;
    if (toggle) {
      var caixa = toggle.querySelector('input[type="checkbox"]');
      if (caixa && !caixa.checked && !toggle.hasAttribute('data-pbl-valor')) {
        caixa.checked = true;
        aplicarToggle(toggle);
      }
    }
  }

  if (abasRaiz) {
    var nav = abasRaiz.querySelector('[data-pbl-abas-nav]');
    if (nav) {
      nav.hidden = false;
      botoesDeAba = todos(nav, 'button[role="tab"]');
      botoesDeAba.forEach(function (b, i) {
        b.addEventListener('click', function () { ativarAba(b.getAttribute('data-pbl-aba'), false); });
        b.addEventListener('keydown', function (e) {
          var destino = null;
          if (e.key === 'ArrowRight') { destino = botoesDeAba[(i + 1) % botoesDeAba.length]; }
          else if (e.key === 'ArrowLeft') { destino = botoesDeAba[(i - 1 + botoesDeAba.length) % botoesDeAba.length]; }
          else if (e.key === 'Home') { destino = botoesDeAba[0]; }
          else if (e.key === 'End') { destino = botoesDeAba[botoesDeAba.length - 1]; }
          if (destino) {
            e.preventDefault();
            ativarAba(destino.getAttribute('data-pbl-aba'), true);
          }
        });
      });
    }
    ativarAba(abasRaiz.getAttribute('data-pbl-aba-inicial') || 'conteudo', false);
  }

  /* ------------------------------------------------------------ interruptores (toggle) */
  function aplicarToggle(raiz) {
    var caixa = raiz.querySelector('input[type="checkbox"]');
    var campos = raiz.querySelector('[data-pbl-toggle-campos]');
    if (caixa && campos) {
      campos.hidden = !caixa.checked;
    }
  }
  todos(document, '[data-pbl-toggle]').forEach(function (raiz) {
    var caixa = raiz.querySelector('input[type="checkbox"]');
    if (!caixa) {
      return;
    }
    // Sem JavaScript a caixa nasce marcada (campos visíveis, nada se perde); aqui ela volta ao estado de verdade.
    if (!raiz.hasAttribute('data-pbl-valor')) {
      caixa.checked = raiz.getAttribute('data-pbl-inicial') === 'ligado';
    }
    aplicarToggle(raiz);
    caixa.addEventListener('change', function () { aplicarToggle(raiz); atualizarPrevias(); });
  });

  /* ------------------------------------------------------------ contador de caracteres */
  todos(document, '[data-pbl-contador]').forEach(function (campo, n) {
    var max = parseInt(campo.getAttribute('maxlength') || '0', 10);
    var bom = parseInt(campo.getAttribute('data-pbl-limite-bom') || '0', 10);
    var limite = bom || max;
    if (!limite) {
      return;
    }
    var contador = criar('span', 'pbl-contador');
    contador.id = 'contador-' + (campo.id || n);
    campo.insertAdjacentElement('afterend', contador);
    campo.setAttribute('aria-describedby', ((campo.getAttribute('aria-describedby') || '') + ' ' + contador.id).trim());
    function atualizar() {
      var n2 = campo.value.length;
      contador.textContent = n2 + ' de ' + limite + (bom ? ' recomendados' : ' caracteres');
      contador.setAttribute('data-nivel', n2 > limite ? 'passou' : (n2 >= limite * 0.9 ? 'perto' : 'ok'));
    }
    campo.addEventListener('input', atualizar);
    atualizar();
  });

  /* ------------------------------------------------------------ validação ao vivo (espelha o servidor; nunca o substitui) */
  function mensagemDe(campo) {
    var bloco = campo.closest('.mb-3');
    return bloco ? bloco.querySelector('[data-pbl-msg]') : null;
  }
  function validar(campo) {
    var valor = (campo.value || '').trim();
    var erro = '';
    if (campo.required && valor === '') {
      erro = 'Este campo é obrigatório.';
    } else if (campo.name === 'url' && valor !== '' && !URL_VALIDA.test(valor)) {
      erro = 'Use só letras minúsculas sem acento, números e hífen, com "/" separando partes.';
    } else if (campo.name === 'peso' && !PESO_VALIDO.test(valor)) {
      erro = 'O peso precisa ser um número inteiro (exemplo: 0, 10 ou -5).';
    } else if (campo.maxLength > 0 && campo.value.length > campo.maxLength) {
      erro = 'Passou do limite de ' + campo.maxLength + ' caracteres.';
    }
    var alvo = mensagemDe(campo);
    if (alvo) {
      alvo.textContent = erro;
      if (alvo.id) {
        var ids = (campo.getAttribute('aria-describedby') || '').split(' ').filter(Boolean);
        if (erro && ids.indexOf(alvo.id) === -1) { ids.push(alvo.id); }
        if (!erro) { ids = ids.filter(function (i) { return i !== alvo.id; }); }
        if (ids.length) { campo.setAttribute('aria-describedby', ids.join(' ')); } else { campo.removeAttribute('aria-describedby'); }
      }
    }
    if (erro) {
      campo.setAttribute('aria-invalid', 'true');
    } else {
      campo.removeAttribute('aria-invalid');
    }
    return erro === '';
  }
  function validavel(campo) {
    if (campo.disabled || campo.type === 'hidden' || campo.type === 'file') { return false; }
    var toggle = campo.closest('[data-pbl-toggle-campos]');
    return !(toggle && toggle.hidden);
  }

  if (form) {
    todos(form, 'input[name="titulo"], input[name="url"], input[name="peso"], [required]').forEach(function (campo) {
      campo.addEventListener('blur', function () { validar(campo); });
      campo.addEventListener('input', function () { if (campo.getAttribute('aria-invalid') === 'true') { validar(campo); } });
    });

    /* ------------------------------------------------------------ estado salvo / não salvo / aviso ao sair */
    var rotuloEstado = form.querySelector('[data-pbl-estado]');
    var dizer = function (nome, texto) {
      if (rotuloEstado) {
        rotuloEstado.setAttribute('data-estado', nome);
        rotuloEstado.textContent = texto;
      }
    };
    if (rotuloEstado && rotuloEstado.getAttribute('data-estado') === 'alterado') {
      estado.sujo = true;
    }
    function marcarSujo() {
      if (!estado.sujo) {
        estado.sujo = true;
      }
      dizer('alterado', 'Alterações não salvas');
    }
    form.addEventListener('input', marcarSujo);
    form.addEventListener('change', marcarSujo);
    window.addEventListener('beforeunload', function (e) {
      if (!estado.sujo || estado.enviando) {
        return;
      }
      dizer('saindo', 'Há alterações não salvas. Salve antes de sair.');
      window.setTimeout(function () { if (estado.sujo && !estado.enviando) { dizer('alterado', 'Alterações não salvas'); } }, 1500);
      e.preventDefault();
      e.returnValue = '';
    });
    form.addEventListener('submit', function (e) {
      var primeiro = null;
      todos(form, 'input, textarea, select').forEach(function (campo) {
        if (validavel(campo) && !validar(campo) && primeiro === null) {
          primeiro = campo;
        }
      });
      if (primeiro) {
        e.preventDefault();
        mostrarCampo(primeiro);
        primeiro.focus();
        dizer('alterado', 'Corrija o campo marcado e salve de novo');
        return;
      }
      estado.enviando = true;
      dizer('alterado', 'Salvando…');
    });
  }

  /* ------------------------------------------------------------ resumo de erros: foco e links que abrem a aba certa */
  var resumo = document.querySelector('[data-pbl-resumo-erros]');
  if (resumo) {
    todos(resumo, 'a[href^="#"]').forEach(function (a) {
      a.addEventListener('click', function (e) {
        var alvo = document.getElementById(a.getAttribute('href').slice(1));
        if (alvo) {
          e.preventDefault();
          mostrarCampo(alvo);
          alvo.focus();
        }
      });
    });
    resumo.focus();
  }

  /* ------------------------------------------------------------ endereço e prévia de busca / rede social */
  var tituloEl = document.getElementById('campo-titulo');
  var urlEl = document.getElementById('campo-url');
  function slug(texto) {
    var s = texto.normalize ? texto.normalize('NFD').replace(/[̀-ͯ]/g, '') : texto;
    return s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80) || 'conteudo';
  }
  function enderecoAtual() {
    if (!urlEl) { return ''; }
    var v = urlEl.value.trim();
    return v !== '' ? v : (tituloEl ? slug(tituloEl.value) : '');
  }
  function atualizarEndereco() {
    todos(document, '[data-pbl-endereco-previa]').forEach(function (el) {
      var gerado = urlEl && urlEl.value.trim() === '' && tituloEl && document.querySelector('input[name="usar_url"][type="checkbox"]');
      el.textContent = (el.getAttribute('data-pbl-base') || '') + '/' + enderecoAtual() + (gerado ? ' (gerado do título)' : '');
    });
  }
  function valorDe(id) {
    var el = document.getElementById(id);
    return el ? el.value.trim() : '';
  }
  function cortar(texto, n) {
    return texto.length > n ? texto.slice(0, n - 1).trimEnd() + '…' : texto;
  }
  function atualizarPrevias() {
    todos(document, '[data-pbl-previa-busca]').forEach(function (raiz) {
      var titulo = valorDe('campo-dados-seo_titulo') || valorDe('campo-titulo');
      var texto = valorDe('campo-dados-seo_descricao') || valorDe('campo-descricao') || valorDe('campo-dados-resumo');
      var base = raiz.getAttribute('data-pbl-base') || '';
      raiz.hidden = false;
      raiz.querySelector('[data-pbl-previa-url]').textContent = base + '/' + enderecoAtual();
      raiz.querySelector('[data-pbl-previa-titulo]').textContent = cortar(titulo || 'Sem título', 60);
      raiz.querySelector('[data-pbl-previa-texto]').textContent = cortar(texto, 160);
      raiz.querySelector('[data-pbl-previa-corte]').textContent = titulo.length > 60 || texto.length > 160 ? 'Pode ser cortado nas buscas.' : '';
      // rede social: montada só com createElement/textContent
      var social = raiz.querySelector('[data-pbl-previa-social]');
      if (!social) {
        social = criar('div', 'pbl-previa-social');
        social.setAttribute('data-pbl-previa-social', '');
        raiz.appendChild(social);
      }
      while (social.firstChild) { social.removeChild(social.firstChild); }
      social.appendChild(criar('span', 'pbl-previa-rotulo', 'Como pode aparecer ao compartilhar'));
      var miniatura = document.querySelector('[data-pbl-miniatura]:not([hidden]) img');
      if (miniatura && miniatura.src) {
        var img = criar('img');
        img.alt = '';
        img.width = 72;
        img.height = 72;
        img.src = miniatura.src;
        social.appendChild(img);
      }
      social.appendChild(criar('span', 'pbl-previa-titulo', cortar(titulo || 'Sem título', 70)));
      social.appendChild(criar('span', 'pbl-previa-texto', cortar(texto, 200)));
    });
  }
  ['campo-titulo', 'campo-descricao', 'campo-url', 'campo-dados-seo_titulo', 'campo-dados-seo_descricao', 'campo-dados-resumo'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) {
      el.addEventListener('input', function () { atualizarEndereco(); atualizarPrevias(); });
    }
  });
  atualizarEndereco();
  atualizarPrevias();

  /* ------------------------------------------------------------ seletor de imagem com miniatura (blob: só nesta tela) */
  todos(document, 'input[type="file"][data-pbl-imagem]').forEach(function (entrada) {
    var bloco = entrada.closest('.mb-3');
    var area = bloco ? bloco.querySelector('[data-pbl-miniatura]') : null;
    if (!area) { return; }
    var img = area.querySelector('img');
    var nome = area.querySelector('[data-pbl-miniatura-nome]');
    var anterior = null;
    entrada.addEventListener('change', function () {
      if (anterior) { URL.revokeObjectURL(anterior); anterior = null; }
      var arquivo = entrada.files && entrada.files[0];
      if (!arquivo || arquivo.type.indexOf('image/') !== 0 || !window.URL || !URL.createObjectURL) {
        area.hidden = true;
        atualizarPrevias();
        return;
      }
      anterior = URL.createObjectURL(arquivo);
      img.src = anterior;
      nome.textContent = arquivo.name + ' (' + Math.max(1, Math.round(arquivo.size / 1024)) + ' KB)';
      area.hidden = false;
      atualizarPrevias();
    });
  });

  /* ------------------------------------------------------------ seletor com busca (select longo ganha um campo de filtro) */
  todos(document, 'select[data-pbl-seletor]').forEach(function (sel) {
    if (sel.options.length < 8) { return; }
    var busca = criar('input', 'form-control pbl-seletor-busca');
    busca.type = 'search';
    busca.placeholder = 'Buscar na lista…';
    busca.setAttribute('aria-label', 'Buscar na lista');
    sel.insertAdjacentElement('beforebegin', busca);
    busca.addEventListener('input', function () {
      var q = busca.value.trim().toLowerCase();
      todos(sel, 'option').forEach(function (o) {
        o.hidden = q !== '' && !o.selected && o.textContent.toLowerCase().indexOf(q) === -1;
      });
      todos(sel, 'optgroup').forEach(function (g) {
        g.hidden = todos(g, 'option').every(function (o) { return o.hidden; });
      });
    });
  });
})();

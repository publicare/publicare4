/* PBL4 — ações de edição nas páginas públicas (ADR 0022). Arquivo próprio, sem inline. Só age se o cookie indicador existir; quem
   decide o que cada pessoa pode fazer é o servidor (GET /_pbl/api/acoes?objetos=...), então um cookie forjado não mostra botão que
   funcione. Uma única chamada cobre todos os [data-pbl-objeto] da página: o do <body> (ações junto do <h1>) e os de cada item listado
   (o molde deixa um <span class="pbl-acoes" hidden> logo depois do título). Visitante sem cookie: nada é lido, injetado nem requisitado. */
(function () {
  'use strict';

  var corpo = document.body;
  if (!corpo || document.cookie.indexOf('pbl4_ind=1') === -1) {
    return;
  }
  var SVG = 'http://www.w3.org/2000/svg';
  var ID = /^[1-9][0-9]{0,9}$/;
  var LOTE = 100;

  var ROTULOS = {
    editar: ['Editar', 'btn btn-sm btn-primary bg-gradient', 'M12 20h9 M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z'],
    publicar: ['Publicar', 'btn btn-sm btn-success bg-gradient', 'M20 6 9 17l-5-5'],
    despublicar: ['Despublicar', 'btn btn-sm btn-warning bg-gradient', 'M8 5v14 M16 5v14'],
    mover: ['Mover', 'btn btn-sm btn-outline-secondary', 'M7 4v16 M3 8l4-4 4 4 M17 20V4 M13 16l4 4 4-4'],
    excluir: ['Excluir', 'btn btn-sm btn-danger bg-gradient', 'M3 6h18 M8 6V4h8v2 M6 6l1 14h10l1-14 M10 11v6 M14 11v6']
  };
  var ORDEM = ['editar', 'publicar', 'despublicar', 'mover', 'excluir'];

  var estado = {}; // id -> { versao, acoes, estado }
  var csrf = '';
  var alvos = []; // { id, caixa }
  var aviso = null;

  function limparIndicador() {
    document.cookie = 'pbl4_ind=; Max-Age=0; Path=/; SameSite=Lax';
  }

  function carregarCss() {
    var scripts = document.getElementsByTagName('script');
    for (var i = 0; i < scripts.length; i++) {
      var src = scripts[i].getAttribute('src') || '';
      if (src.indexOf('pbl-barra.js') !== -1) {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = src.replace('pbl-barra.js', 'pbl-barra.css');
        document.head.appendChild(link);
        return;
      }
    }
  }

  function icone(caminho) {
    var svg = document.createElementNS(SVG, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('width', '16');
    svg.setAttribute('height', '16');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    var p = document.createElementNS(SVG, 'path');
    p.setAttribute('d', caminho);
    svg.appendChild(p);
    return svg;
  }

  function dizer(texto) {
    if (!aviso) {
      aviso = document.createElement('div');
      aviso.className = 'pbl-aviso';
      aviso.setAttribute('role', 'status');
      corpo.appendChild(aviso);
    }
    aviso.textContent = texto;
    window.clearTimeout(dizer.t);
    if (texto) {
      dizer.t = window.setTimeout(function () { aviso.textContent = ''; }, 5000);
    }
  }

  function botao(nome, url, id, titulo) {
    var r = ROTULOS[nome];
    var el;
    var rotulo = r[0] + (titulo ? ': ' + titulo : '');
    if (nome === 'publicar' || nome === 'despublicar') {
      el = document.createElement('button');
      el.type = 'button';
      el.addEventListener('click', function () { enviar(nome, id, el); });
    } else {
      el = document.createElement('a');
      el.href = url;
    }
    el.className = r[1];
    el.setAttribute('aria-label', rotulo);
    el.title = r[0];
    el.appendChild(icone(r[2]));
    return el;
  }

  function nomeDoItem(raiz) {
    var t = raiz.cloneNode(true);
    var extras = t.querySelectorAll('.pbl-acoes, .so-leitor, .visually-hidden');
    for (var i = 0; i < extras.length; i++) {
      extras[i].parentNode.removeChild(extras[i]);
    }
    return (t.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 80);
  }

  function desenhar(alvo) {
    var dados = estado[alvo.id];
    var caixa = alvo.caixa;
    while (caixa.firstChild) {
      caixa.removeChild(caixa.firstChild);
    }
    if (!dados) {
      caixa.hidden = true;
      return;
    }
    ORDEM.forEach(function (nome) {
      var acao = dados.acoes[nome];
      if (acao && typeof acao.url === 'string' && acao.url.charAt(0) === '/') {
        caixa.appendChild(botao(nome, acao.url, alvo.id, alvo.titulo));
      }
    });
    caixa.hidden = !caixa.firstChild;
  }

  function redesenhar(id) {
    alvos.forEach(function (a) { if (a.id === id) { desenhar(a); } });
  }

  function enviar(nome, id, el) {
    var dados = estado[id];
    if (!dados) {
      return;
    }
    el.disabled = true;
    var f = new URLSearchParams();
    f.set('objeto', id);
    f.set('acao', nome);
    f.set('versao', String(dados.versao));
    f.set('_csrf', csrf);
    fetch('/_pbl/api/estado', { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' }, body: f })
      .then(function (r) {
        if (r.status === 401) {
          limparIndicador();
        }
        return r.json().then(function (j) { return { ok: r.ok, j: j }; });
      })
      .then(function (res) {
        if (res.ok) {
          var novo = {};
          Object.keys(dados.acoes).forEach(function (k) {
            if (k !== 'publicar' && k !== 'despublicar') { novo[k] = dados.acoes[k]; }
          });
          novo[nome === 'publicar' ? 'despublicar' : 'publicar'] = { url: '/_pbl/api/estado' };
          estado[id] = { versao: res.j.versao, estado: res.j.estado, acoes: novo };
          redesenhar(id);
          dizer(nome === 'publicar' ? 'Publicado.' : 'Tirado do ar.');
          return;
        }
        el.disabled = false;
        dizer(res.j && res.j.erro === 'conflito' ? 'Outra pessoa alterou este conteúdo; recarregue a página.' : 'Não foi possível concluir (' + (res.j && res.j.erro ? res.j.erro : 'erro') + ').');
      })
      .catch(function () {
        el.disabled = false;
        dizer('Sem conexão com o servidor.');
      });
  }

  function coletar() {
    var nos = document.querySelectorAll('[data-pbl-objeto]');
    var ids = {};
    for (var i = 0; i < nos.length; i++) {
      var no = nos[i];
      var id = no.getAttribute('data-pbl-objeto');
      if (!ID.test(id || '')) {
        continue;
      }
      var caixa;
      var titulo = '';
      if (no === corpo) {
        var h1 = document.querySelector('h1');
        caixa = document.createElement('span');
        caixa.className = 'pbl-acoes';
        caixa.hidden = true;
        if (h1) {
          h1.appendChild(caixa);
        } else {
          corpo.insertBefore(caixa, corpo.firstChild);
        }
      } else {
        caixa = no.querySelector('.pbl-acoes');
        if (!caixa) {
          continue;
        }
        titulo = nomeDoItem(no);
      }
      alvos.push({ id: id, caixa: caixa, titulo: titulo });
      ids[id] = true;
    }
    return Object.keys(ids);
  }

  function pedir(ids) {
    var pedaco = ids.slice(0, LOTE);
    return fetch('/_pbl/api/acoes?objetos=' + pedaco.join(','), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) {
        if (r.status === 401) {
          limparIndicador();
          return null;
        }
        return r.ok ? r.json() : null;
      })
      .then(function (dados) {
        if (!dados || !dados.objetos) {
          return;
        }
        csrf = dados.csrf || csrf;
        Object.keys(dados.objetos).forEach(function (k) { estado[k] = dados.objetos[k]; });
        if (ids.length > LOTE) {
          return pedir(ids.slice(LOTE));
        }
      });
  }

  var ids = coletar();
  if (!ids.length) {
    return;
  }
  pedir(ids)
    .then(function () {
      if (Object.keys(estado).length) {
        carregarCss();
        alvos.forEach(desenhar);
      }
    })
    .catch(function () { /* silencioso: a página pública segue igual */ });
}());

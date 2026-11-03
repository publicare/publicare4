/* PBL4 — barra de edição nas páginas públicas (ADR 0022). Arquivo próprio, sem inline. Só age se o cookie indicador existir; quem
   decide o que cada pessoa pode fazer é o servidor (GET /_pbl/api/acoes), então um cookie forjado não mostra botão que funcione. */
(function () {
  'use strict';

  var corpo = document.body;
  var id = corpo ? corpo.getAttribute('data-pbl-objeto') : '';
  if (!id || !/^[1-9][0-9]{0,9}$/.test(id) || document.cookie.indexOf('pbl4_ind=1') === -1) {
    return;
  }

  var ROTULOS = {
    editar: ['✎ Editar', 'btn btn-primary bg-gradient'],
    publicar: ['✓ Publicar', 'btn btn-success bg-gradient'],
    despublicar: ['⏸ Despublicar', 'btn btn-warning bg-gradient'],
    mover: ['⇅ Mover', 'btn btn-outline-secondary'],
    excluir: ['✕ Excluir', 'btn btn-danger bg-gradient']
  };
  var ORDEM = ['editar', 'publicar', 'despublicar', 'mover', 'excluir'];

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

  function montar(dados) {
    var barra = document.createElement('div');
    barra.className = 'pbl-barra';
    barra.setAttribute('role', 'toolbar');
    barra.setAttribute('aria-label', 'Edição deste conteúdo');
    var aviso = document.createElement('span');
    aviso.className = 'pbl-barra-aviso';
    aviso.setAttribute('role', 'status');

    ORDEM.forEach(function (nome) {
      var acao = dados.acoes && dados.acoes[nome];
      if (!acao || typeof acao.url !== 'string' || acao.url.charAt(0) !== '/') {
        return;
      }
      var rotulo = ROTULOS[nome];
      if (nome === 'publicar' || nome === 'despublicar') {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = rotulo[1];
        b.textContent = rotulo[0];
        b.addEventListener('click', function () {
          enviar(nome, acao.url, dados, b, aviso);
        });
        barra.appendChild(b);
      } else {
        var a = document.createElement('a');
        a.className = rotulo[1];
        a.href = acao.url;
        a.textContent = rotulo[0];
        barra.appendChild(a);
      }
    });
    if (!barra.firstChild) {
      return;
    }
    barra.appendChild(aviso);
    var titulo = document.querySelector('h1');
    if (titulo && titulo.parentNode) {
      titulo.parentNode.insertBefore(barra, titulo.nextSibling);
    } else {
      corpo.insertBefore(barra, corpo.firstChild);
    }
  }

  function enviar(nome, url, dados, botao, aviso) {
    botao.disabled = true;
    aviso.textContent = '';
    var f = new URLSearchParams();
    f.set('objeto', id);
    f.set('acao', nome);
    f.set('versao', String(dados.versao));
    f.set('_csrf', dados.csrf);
    fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' }, body: f })
      .then(function (r) {
        if (r.status === 401) {
          limparIndicador();
        }
        return r.json().then(function (j) { return { ok: r.ok, j: j }; });
      })
      .then(function (res) {
        if (res.ok) {
          aviso.textContent = nome === 'publicar' ? 'Publicado.' : 'Tirado do ar.';
          window.setTimeout(function () { window.location.reload(); }, 600);
          return;
        }
        botao.disabled = false;
        aviso.textContent = res.j && res.j.erro === 'conflito' ? 'Outra pessoa alterou este conteúdo; recarregue a página.' : 'Não foi possível concluir (' + (res.j && res.j.erro ? res.j.erro : 'erro') + ').';
      })
      .catch(function () {
        botao.disabled = false;
        aviso.textContent = 'Sem conexão com o servidor.';
      });
  }

  fetch('/_pbl/api/acoes?objeto=' + encodeURIComponent(id), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
    .then(function (r) {
      if (r.status === 401) {
        limparIndicador();
        return null;
      }
      return r.ok ? r.json() : null;
    })
    .then(function (dados) {
      if (dados && dados.acoes) {
        carregarCss();
        montar(dados);
      }
    })
    .catch(function () { /* silencioso: a página pública segue igual */ });
}());

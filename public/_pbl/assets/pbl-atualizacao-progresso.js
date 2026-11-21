/* PBL4 — andamento da atualização (/_pbl/atualizacao). Arquivo próprio, sem inline (CSP script-src 'self'; connect-src 'self').
   Consulta o endereço de [data-pbl-progresso] a cada poucos segundos e atualiza a barra, a etapa, o tempo e o resultado com
   textContent (nada vira HTML). Durante a troca do código o site responde "em manutenção" (503): isso e qualquer falha de rede
   são tratados como pausa curta — o último estado fica na tela e a consulta recomeça sozinha, com intervalo crescente até 10 s. */
(function () {
  'use strict';

  var raiz = document.querySelector('[data-pbl-progresso]');
  if (!raiz || !window.fetch) {
    return;
  }
  var url = raiz.getAttribute('data-url');
  var etapa = raiz.querySelector('[data-pbl-etapa]');
  var mensagem = raiz.querySelector('[data-pbl-mensagem]');
  var tempo = raiz.querySelector('[data-pbl-tempo]');
  var trilho = raiz.querySelector('[data-pbl-trilho]');
  var barra = raiz.querySelector('[data-pbl-barra]');
  var ok = raiz.querySelector('[data-pbl-ok]');
  var erro = raiz.querySelector('[data-pbl-erro]');
  var aviso = raiz.querySelector('[data-pbl-aviso-cron]');
  var conexao = raiz.querySelector('[data-pbl-conexao]');
  var cancelar = raiz.querySelector('[data-pbl-cancelar]');
  var recarregar = raiz.querySelector('[data-pbl-recarregar]');
  var LIMITE_MS = 60 * 60 * 1000;
  var CRON_SEM_INICIAR = 300;
  var comecou = Date.now();
  var recuo = 0;
  var ultimo = null; /* última resposta boa */
  var recebidoEm = Date.now();
  var parou = false;
  var estadoInicial = raiz.getAttribute('data-estado') || 'ocioso';

  function texto(el, valor) {
    if (el) {
      el.textContent = valor;
    }
  }

  function mostrar(el, sim) {
    if (el) {
      el.hidden = !sim;
    }
  }

  function duracao(s) {
    s = Math.max(0, Math.floor(s));
    if (s < 60) {
      return s + ' s';
    }
    var m = Math.floor(s / 60);
    if (m < 60) {
      return m + ' min ' + (s % 60) + ' s';
    }
    return Math.floor(m / 60) + ' h ' + (m % 60) + ' min';
  }

  function decorrido(d) {
    var extra = (Date.now() - recebidoEm) / 1000;
    if (d.estado === 'agendada') {
      return (d.agendada_ha_s || 0) + extra;
    }
    return d.estado === 'em_andamento' ? (d.decorrido_s || 0) + extra : (d.decorrido_s || 0);
  }

  function desenharTempo() {
    if (!ultimo) {
      return;
    }
    var d = ultimo;
    var s = decorrido(d);
    if (d.estado === 'agendada') {
      texto(tempo, 'Agendada há ' + duracao(s) + '.');
    } else if (d.estado === 'em_andamento') {
      texto(tempo, 'Tempo decorrido: ' + duracao(s) + '.');
    } else if (d.estado === 'ocioso') {
      texto(tempo, '');
    } else {
      texto(tempo, 'Durou ' + duracao(s) + '.');
    }
    mostrar(aviso, d.estado === 'agendada' && (d.cron_sem_iniciar || s >= CRON_SEM_INICIAR));
  }

  function desenhar(d) {
    ultimo = d;
    recebidoEm = Date.now();
    raiz.setAttribute('data-estado', d.estado);
    var pct = Math.max(0, Math.min(100, parseInt(d.percentual, 10) || 0));
    texto(etapa, d.etapa_nome);
    texto(mensagem, d.mensagem);
    if (trilho) {
      trilho.setAttribute('aria-valuenow', String(pct));
      trilho.setAttribute('aria-valuetext', pct + '% — ' + d.mensagem);
    }
    if (barra) {
      barra.style.width = pct + '%';
      barra.className = 'progress-bar' + (d.estado === 'falhou' ? ' bg-danger' : (d.estado === 'concluido' ? ' bg-success' : ' progress-bar-striped progress-bar-animated'));
    }
    var concluido = d.estado === 'concluido';
    var falhou = d.estado === 'falhou';
    var tipo = d.tipo === 'reverter' ? 'A volta atrás' : 'A atualização';
    texto(ok, concluido ? tipo + ' terminou sem erro. Recarregue a página para ver a versão instalada.' : '');
    mostrar(ok, concluido);
    texto(erro, falhou ? tipo + ' falhou. ' + (d.falha || '') + ' Se a falha foi depois da troca do código, o PBL4 já devolveu o estado anterior.' : '');
    mostrar(erro, falhou);
    mostrar(cancelar, d.estado === 'agendada');
    mostrar(recarregar, concluido || falhou);
    mostrar(conexao, false);
    desenharTempo();
  }

  function pausa(ms) {
    if (!parou) {
      window.setTimeout(consultar, ms);
    }
  }

  function falhaTemporaria(texto401) {
    recuo = Math.min(10000, recuo === 0 ? 2000 : recuo * 2);
    if (conexao) {
      conexao.textContent = texto401 || 'Sem resposta do site por instantes (a troca do código deixa o site em manutenção). Tentando de novo…';
      conexao.hidden = false;
    }
    pausa(recuo);
  }

  function consultar() {
    if (parou) {
      return;
    }
    if (Date.now() - comecou > LIMITE_MS) {
      parou = true;
      return;
    }
    fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
      .then(function (r) {
        if (r.status === 401 || r.status === 403 || r.redirected) {
          parou = true;
          if (conexao) {
            conexao.textContent = 'Sua sessão terminou. Entre de novo para continuar acompanhando.';
            conexao.hidden = false;
          }
          return null;
        }
        var tipo = r.headers.get('Content-Type') || '';
        if (!r.ok || tipo.indexOf('application/json') !== 0) {
          throw new Error('indisponivel');
        }
        return r.json();
      })
      .then(function (d) {
        if (d === null) {
          return;
        }
        recuo = 0;
        desenhar(d);
        if (d.estado === 'concluido' || d.estado === 'falhou') {
          parou = true;
          return;
        }
        if (d.estado === 'ocioso' && estadoInicial === 'ocioso') {
          pausa(3000);
          return;
        }
        pausa(d.estado === 'em_andamento' ? 1000 : 3000);
      })
      .catch(function () {
        falhaTemporaria(null);
      });
  }

  window.setInterval(desenharTempo, 1000);
  consultar();
}());

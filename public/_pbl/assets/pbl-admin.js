/* PBL4 — script da administração (ADR 0012, adendo de 2026-11). Arquivo próprio, sem inline; tudo dirigido por atributos data-*. */
(function () {
  'use strict';

  /** Mensagem em português para uma falha do fetch: erro de rede (TypeError) vira texto claro; o resto mantém a mensagem. */
  function mensagemDeFalha(erro) {
    if (erro instanceof TypeError) {
      return 'Sem conexão com o servidor.';
    }
    return erro && erro.message ? erro.message : 'Falha de comunicação com o servidor.';
  }

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

  /* data-pbl-abas: o servidor entrega todos os painéis (empilhados, sem JavaScript) e a aba ativa com `active show`. Aqui o script
     liga o modo de abas: mostra a barra, esconde os títulos dos painéis e põe tab-content/tab-pane (o que faz o Bootstrap esconder
     os inativos). A aba escolhida vai para ?aba= (replaceState), para sobreviver a recarregar e a novo acesso. */
  document.querySelectorAll('[data-pbl-abas]').forEach(function (raiz) {
    var nav = raiz.querySelector('[data-pbl-abas-nav]');
    var paineis = raiz.querySelector('[data-pbl-abas-paineis]');
    if (!nav || !paineis) {
      return;
    }
    nav.hidden = false;
    paineis.classList.add('tab-content');
    paineis.querySelectorAll('.pbl-aba-painel').forEach(function (p) {
      p.classList.add('tab-pane', 'fade');
    });
    paineis.querySelectorAll('.pbl-aba-titulo').forEach(function (t) {
      t.classList.add('visually-hidden');
    });
    nav.addEventListener('shown.bs.tab', function (e) {
      var chave = e.target && e.target.getAttribute('data-pbl-aba');
      if (chave && window.history && history.replaceState) {
        var url = new URL(window.location.href);
        url.searchParams.set('aba', chave);
        url.searchParams.delete('ok');
        history.replaceState(null, '', url.toString());
      }
    });
  });

  /* Campo inválido dentro de painel de aba escondido: mostra a aba, senão o navegador não consegue focar o campo. */
  document.addEventListener('invalid', function (e) {
    var painel = e.target && e.target.closest ? e.target.closest('.tab-pane:not(.active)') : null;
    var botao = painel && painel.id ? document.querySelector('[data-bs-target="#' + painel.id + '"]') : null;
    if (botao && window.bootstrap) {
      window.bootstrap.Tab.getOrCreateInstance(botao).show();
    }
  }, true);

  /* Tela Conteúdo: a caixa do cabeçalho marca ou desmarca todas as linhas da página (data-pbl-marcar-todos="ids[]"). */
  document.addEventListener('change', function (e) {
    var alvo = e.target;
    var nome = alvo && alvo.getAttribute ? alvo.getAttribute('data-pbl-marcar-todos') : null;
    if (!nome) {
      return;
    }
    document.querySelectorAll('input[type="checkbox"]').forEach(function (c) {
      if (c.name === nome) {
        c.checked = alvo.checked;
      }
    });
  });

  /* Republicar com barra de progresso (ADR 0023): form[data-pbl-republicar="url do lote"] chama o endpoint um lote por vez e
     atualiza a barra ("42 de 92 páginas"). Sem JavaScript o formulário faz o POST comum, que também trata lotes. */
  document.querySelectorAll('form[data-pbl-republicar]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!window.fetch || !window.FormData) {
        return;
      }
      e.preventDefault();
      var url = form.getAttribute('data-pbl-republicar');
      var botao = form.querySelector('button[type="submit"]');
      var area = document.querySelector('[data-pbl-republicar-progresso]');
      var barra = area ? area.querySelector('[data-pbl-barra]') : null;
      var texto = area ? area.querySelector('[data-pbl-texto]') : null;
      var trilho = area ? area.querySelector('.progress') : null;
      var csrf = form.querySelector('input[name="_csrf"]');
      var falhasSeguidas = 0;
      if (botao) {
        botao.disabled = true;
      }
      if (area) {
        area.hidden = false;
      }
      function mostrar(msg, classe) {
        if (texto) {
          texto.textContent = msg;
          texto.className = 'mt-2 mb-0' + (classe ? ' ' + classe : '');
        }
      }
      function terminar(msg, classe) {
        mostrar(msg, classe);
        if (botao) {
          botao.disabled = false;
          botao.textContent = 'Continuar';
        }
      }
      function lote(acao) {
        var dados = new FormData();
        dados.append('_csrf', csrf ? csrf.value : '');
        dados.append('acao', acao);
        fetch(url, { method: 'POST', body: dados, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
          .then(function (r) {
            return r.json().then(function (j) { return { ok: r.ok, j: j }; });
          })
          .then(function (r) {
            if (!r.ok) {
              throw new Error(r.j && r.j.erro ? r.j.erro : 'Falha ao republicar.');
            }
            falhasSeguidas = 0;
            var j = r.j;
            var pct = j.total > 0 ? Math.min(100, Math.round((j.feitas / j.total) * 100)) : 0;
            if (barra) {
              barra.style.width = (j.status === 'concluido' ? 100 : pct) + '%';
            }
            if (trilho) {
              trilho.setAttribute('aria-valuenow', String(pct));
            }
            if (j.status === 'andando') {
              mostrar(j.feitas + ' de ' + j.total + ' páginas');
              window.setTimeout(function () { lote('continuar'); }, j.ocupado ? 1000 : 0);
              return;
            }
            if (j.status === 'concluido') {
              if (botao) {
                botao.textContent = 'Republicar agora';
                botao.disabled = false;
              }
              mostrar('Pronto: ' + j.publicadas + ' página(s) publicada(s)' + (j.falhas > 0 ? ', ' + j.falhas + ' com falha' : '') + ' em ' + j.segundos + ' s.', j.falhas > 0 ? 'text-warning-emphasis' : 'text-success');
              return;
            }
            terminar('A republicação parou em ' + j.feitas + ' de ' + j.total + ' páginas' + (j.erro ? ': ' + j.erro : '.') + ' Clique em Continuar para tentar de novo.', 'text-danger');
          })
          .catch(function (erro) {
            falhasSeguidas += 1;
            if (falhasSeguidas < 3) {
              window.setTimeout(function () { lote('continuar'); }, 1500);
              return;
            }
            terminar(mensagemDeFalha(erro) + ' Clique em Continuar para retomar de onde parou.', 'text-danger');
          });
      }
      mostrar('Começando…');
      lote('iniciar');
    });
  });
})();

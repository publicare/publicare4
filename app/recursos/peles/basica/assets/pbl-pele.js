/* PBL4, pele basica: melhoria progressiva. Arquivo proprio (sem inline, sem CDN), carregado com defer e ?v=. Tudo aqui e opcional: sem
   JavaScript o site continua legivel e navegavel (o CSS so esconde algo quando <html class="js"> existe). Usa o Bootstrap local para
   recolher o menu, o carrossel, o accordion e os submenus; o resto sao efeitos leves e respeitam prefers-reduced-motion. */
(function () {
  'use strict';

  var doc = document;
  var reduz = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var bs = window.bootstrap;

  function todos(seletor, raiz) {
    return Array.prototype.slice.call((raiz || doc).querySelectorAll(seletor));
  }

  // Submenus: botao ao lado de cada item com filhos (abre por clique ou teclado; no desktop o hover/foco do CSS continua valendo).
  function submenus() {
    todos('.menu li').forEach(function (li, i) {
      var ul = null;
      for (var c = li.firstElementChild; c; c = c.nextElementSibling) {
        if (c.tagName === 'UL') { ul = c; }
      }
      var link = li.firstElementChild;
      if (!ul || !link || link.tagName !== 'A') {
        return;
      }
      var nome = (link.textContent || '').replace(/\s+/g, ' ').trim();
      var b = doc.createElement('button');
      b.type = 'button';
      b.className = 'sub-toggle';
      b.setAttribute('aria-expanded', 'false');
      b.setAttribute('aria-label', 'Mostrar submenu de ' + nome);
      b.setAttribute('data-bs-toggle', 'dropdown');
      b.setAttribute('data-bs-display', 'static');
      b.setAttribute('data-bs-auto-close', 'outside');
      ul.classList.add('dropdown-menu');
      li.classList.add('tem-sub');
      li.insertBefore(b, ul);
    });
  }

  // Carrossel do hero: indicadores com aria; sem autoplay se a pessoa pede menos movimento; so com 2+ slides.
  function carrossel() {
    todos('.carrossel').forEach(function (c) {
      var slides = todos('.carousel-item', c);
      if (slides.length < 2) {
        return;
      }
      if (reduz) {
        c.setAttribute('data-bs-ride', 'false');
      }
      var ind = doc.createElement('div');
      ind.className = 'carousel-indicators';
      slides.forEach(function (_, i) {
        var b = doc.createElement('button');
        b.type = 'button';
        b.setAttribute('data-bs-target', '#' + c.id);
        b.setAttribute('data-bs-slide-to', String(i));
        b.setAttribute('aria-label', 'Ir para o destaque ' + (i + 1) + ' de ' + slides.length);
        if (i === 0) {
          b.className = 'active';
          b.setAttribute('aria-current', 'true');
        }
        ind.appendChild(b);
      });
      c.appendChild(ind);
    });
  }

  // Voltar ao topo e cabecalho que muda ao rolar.
  function rolagem() {
    var topo = doc.querySelector('.topo');
    var voltar = doc.createElement('button');
    voltar.type = 'button';
    voltar.className = 'voltar-ao-topo';
    voltar.setAttribute('aria-label', 'Voltar ao topo');
    voltar.title = 'Voltar ao topo';
    voltar.appendChild(doc.createTextNode('↑'));
    voltar.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: reduz ? 'auto' : 'smooth' });
    });
    doc.body.appendChild(voltar);
    var ocupado = false;
    function atualizar() {
      ocupado = false;
      var y = window.pageYOffset || doc.documentElement.scrollTop || 0;
      if (topo) { topo.classList.toggle('rolou', y > 8); }
      voltar.classList.toggle('visivel', y > 600);
    }
    window.addEventListener('scroll', function () {
      if (!ocupado) {
        ocupado = true;
        window.requestAnimationFrame(atualizar);
      }
    }, { passive: true });
    atualizar();
  }

  // Cartoes aparecem suavemente ao entrar na tela. So os que ja estao abaixo da dobra ganham a classe; sem IntersectionObserver ou com
  // movimento reduzido nada e escondido.
  function revelar() {
    if (reduz || !('IntersectionObserver' in window)) {
      return;
    }
    var obs = new IntersectionObserver(function (entradas) {
      entradas.forEach(function (e) {
        if (e.isIntersecting) {
          e.target.classList.add('visivel');
          obs.unobserve(e.target);
        }
      });
    }, { rootMargin: '0px 0px -8% 0px' });
    todos('.item, .destaque, .atalhos li, .accordion-item').forEach(function (el) {
      if (el.getBoundingClientRect().top > window.innerHeight) {
        el.classList.add('revelar');
        obs.observe(el);
      }
    });
  }

  if (bs) {
    submenus();
    carrossel();
  }
  rolagem();
  revelar();
}());

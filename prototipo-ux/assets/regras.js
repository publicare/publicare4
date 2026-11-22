/* Regras puras do protótipo (sem DOM). Usadas por proto.js e testadas em tests/unit. */
(function (raiz, fabrica) {
  var api = fabrica();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else raiz.PblRegras = api;
}(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  var RE_EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

  function semAcento(t) {
    return String(t).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  }

  function urlValida(t) {
    try { var u = new URL(t); return u.protocol === 'http:' || u.protocol === 'https:'; } catch (e) { return false; }
  }

  function plural(n, um, varios) { return n + ' ' + (n === 1 ? um : varios); }

  /**
   * Valida um valor. `r` = { rotulo, obrigatorio, max, tipo, min, maxValor }.
   * Devolve '' quando válido, ou a mensagem que diz como corrigir.
   */
  function validarValor(valor, r) {
    var v = String(valor == null ? '' : valor).trim();
    var rotulo = r.rotulo || 'Este campo';
    if (!v) return r.obrigatorio ? 'Preencha “' + rotulo + '”.' : '';
    if (r.max && v.length > r.max) {
      return '“' + rotulo + '” passou do limite: apague ' + plural(v.length - r.max, 'caractere', 'caracteres') + '.';
    }
    switch (r.tipo) {
      case 'email':
        if (!RE_EMAIL.test(v)) return 'Escreva o e-mail completo, como nome@exemplo.com.br.';
        break;
      case 'telefone': {
        if (/[^\d\s()+\-.]/.test(v)) return 'Use só números, espaço, parênteses e hífen. Exemplo: (11) 3456-7890.';
        var n = v.replace(/\D/g, '').length;
        if (n < 10 || n > 13) return 'Inclua o DDD. Exemplo: (11) 3456-7890.';
        break;
      }
      case 'url':
        if (!urlValida(v)) return 'Escreva o endereço completo, começando com https://.';
        break;
      case 'urls': {
        var ruins = v.split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean).filter(function (l) { return !urlValida(l); });
        if (ruins.length) return 'Uma linha não é um endereço válido: “' + ruins[0] + '”. Use https:// no começo.';
        break;
      }
      case 'slug':
        if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(v)) return 'Use só letras minúsculas sem acento, números e hífen. Exemplo: suite-master.';
        break;
      case 'analytics':
        if (!/^[A-Za-z0-9_-]+$/.test(v)) return 'Use só letras, números, hífen e sublinhado.';
        break;
      case 'inteiro': {
        if (!/^-?\d+$/.test(v)) return 'Escreva um número inteiro.';
        var x = parseInt(v, 10);
        if (r.min != null && x < r.min) return 'O menor valor é ' + r.min + '.';
        if (r.maxValor != null && x > r.maxValor) return 'O maior valor é ' + r.maxValor + '.';
        break;
      }
      default:
    }
    return '';
  }

  /** "A saída do ar precisa ser depois da publicação." Datas no formato do datetime-local. */
  function validarIntervalo(publicar, tirar) {
    if (publicar && tirar && tirar <= publicar) return 'A saída do ar precisa ser depois da publicação.';
    return '';
  }

  /** Contador de caracteres: de 90% do limite avisa com texto e cor; passando, mostra quanto sobra. */
  function contador(tamanho, max) {
    if (tamanho > max) return { texto: 'Passou ' + (tamanho - max), nivel: 'passou' };
    if (tamanho >= Math.ceil(max * 0.9)) return { texto: 'Faltam ' + (max - tamanho), nivel: 'perto' };
    return { texto: tamanho + ' de ' + max, nivel: 'normal' };
  }

  function cortar(texto, max) {
    return texto.length > max ? texto.slice(0, max - 1).trimEnd() + '…' : texto;
  }

  function dataBr(valor) {
    if (!valor) return '';
    var p = String(valor).split('T');
    var d = p[0].split('-');
    return d[2] + '/' + d[1] + '/' + d[0] + (p[1] ? ' às ' + p[1] : '');
  }

  function slugDe(texto) {
    return semAcento(texto).replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }

  /** Filtro da lista: termo sem acento e estado (ou 'todos'). */
  function combina(item, termo, estado) {
    var okEstado = !estado || estado === 'todos' || item.estado === estado;
    var t = semAcento(termo || '').trim();
    return okEstado && (!t || semAcento(item.titulo).indexOf(t) !== -1);
  }

  return { semAcento: semAcento, urlValida: urlValida, validarValor: validarValor, validarIntervalo: validarIntervalo, contador: contador, cortar: cortar, dataBr: dataBr, slugDe: slugDe, combina: combina, RE_EMAIL: RE_EMAIL };
}));

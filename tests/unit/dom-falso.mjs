// DOM mínimo, só o que pbl-formulario.js usa (sem dependência: o repositório não tem jsdom).
// Árvore montada por código (`el(...)`), seletores CSS simples (tag, .classe, [attr], [attr=v], [attr^=v], :not([attr]), vírgula e descendente).
import vm from 'node:vm';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ESPELHADOS = { id: 'id', name: 'name', type: 'type', placeholder: 'placeholder', src: 'src', alt: 'alt', href: 'href', className: 'class' };
const BOOLEANOS = { hidden: 'hidden', required: 'required', disabled: 'disabled', open: 'open' };

function parteSimples(texto) {
  const p = { tag: null, classes: [], attrs: [], nots: [] };
  let resto = texto.replace(/:not\(\[([^\]]+)\]\)/g, (_, a) => { p.nots.push(a); return ''; });
  resto = resto.replace(/\[([a-z-]+)(\^?=)?"?([^\]"]*)"?\]/g, (_, nome, op, valor) => { p.attrs.push({ nome, op, valor }); return ''; });
  resto = resto.replace(/\.([\w-]+)/g, (_, c) => { p.classes.push(c); return ''; });
  if (resto) { p.tag = resto.toUpperCase(); }
  return p;
}
function confere(el, p) {
  if (p.tag && el.tagName !== p.tag) { return false; }
  if (!p.classes.every((c) => el.className.split(/\s+/).includes(c))) { return false; }
  if (p.nots.some((a) => el.hasAttribute(a))) { return false; }
  return p.attrs.every(({ nome, op, valor }) => {
    if (!el.hasAttribute(nome)) { return false; }
    const v = el.getAttribute(nome);
    return op === '=' ? v === valor : op === '^=' ? v.startsWith(valor) : true;
  });
}
function compilar(seletor) {
  return seletor.split(',').map((s) => s.trim().split(/\s+/).map(parteSimples));
}
function casa(el, cadeia) {
  if (!confere(el, cadeia[cadeia.length - 1])) { return false; }
  let i = cadeia.length - 2;
  for (let a = el.parentElement; a && i >= 0; a = a.parentElement) {
    if (confere(a, cadeia[i])) { i--; }
  }
  return i < 0;
}

export class Elemento {
  constructor(tag, attrs = {}) {
    this.tagName = tag.toUpperCase();
    this.attrs = new Map();
    this.children = [];
    this.parentElement = null;
    this.ouvintes = {};
    this._texto = '';
    this.value = '';
    this.checked = false;
    this.selected = false;
    this.files = null;
    this.tabIndex = 0;
    for (const [k, v] of Object.entries(attrs)) { this.setAttribute(k, v); }
    if (attrs.value !== undefined) { this.value = String(attrs.value); }
    if (attrs.checked) { this.checked = true; }
    if (attrs.selected) { this.selected = true; }
    for (const [prop, attr] of Object.entries(ESPELHADOS)) {
      Object.defineProperty(this, prop, { get: () => this.getAttribute(attr) ?? '', set: (v) => this.setAttribute(attr, v), configurable: true });
    }
    for (const [prop, attr] of Object.entries(BOOLEANOS)) {
      Object.defineProperty(this, prop, { get: () => this.hasAttribute(attr), set: (v) => (v ? this.setAttribute(attr, '') : this.removeAttribute(attr)), configurable: true });
    }
  }
  get maxLength() { return this.hasAttribute('maxlength') ? parseInt(this.getAttribute('maxlength'), 10) : -1; }
  get options() { return this.querySelectorAll('option'); }
  get firstChild() { return this.children[0] ?? null; }
  set width(v) { this.setAttribute('width', v); }
  set height(v) { this.setAttribute('height', v); }
  get textContent() { return this._texto + this.children.map((c) => c.textContent).join(''); }
  set textContent(v) { this.children.forEach((c) => { c.parentElement = null; }); this.children = []; this._texto = String(v); }
  setAttribute(k, v) { this.attrs.set(k, String(v)); if (k === 'value') { this.value = String(v); } }
  getAttribute(k) { return this.attrs.has(k) ? this.attrs.get(k) : null; }
  hasAttribute(k) { return this.attrs.has(k); }
  removeAttribute(k) { this.attrs.delete(k); }
  appendChild(c) { if (c.parentElement) { c.parentElement.removeChild(c); } c.parentElement = this; this.children.push(c); return c; }
  removeChild(c) { this.children = this.children.filter((x) => x !== c); c.parentElement = null; return c; }
  insertAdjacentElement(pos, c) {
    const pai = this.parentElement;
    if (c.parentElement) { c.parentElement.removeChild(c); }
    c.parentElement = pai;
    const i = pai.children.indexOf(this);
    pai.children.splice(pos === 'afterend' ? i + 1 : i, 0, c);
    return c;
  }
  querySelectorAll(seletor) {
    const cadeias = compilar(seletor);
    const achados = [];
    const varrer = (n) => n.children.forEach((c) => { if (cadeias.some((ch) => casa(c, ch))) { achados.push(c); } varrer(c); });
    varrer(this);
    return achados;
  }
  querySelector(seletor) { return this.querySelectorAll(seletor)[0] ?? null; }
  closest(seletor) {
    const cadeias = compilar(seletor);
    for (let e = this; e; e = e.parentElement) { if (cadeias.some((c) => casa(e, c))) { return e; } }
    return null;
  }
  addEventListener(tipo, fn) { (this.ouvintes[tipo] ??= []).push(fn); }
  focus() { this.ownerDocument.activeElement = this; }
  get ownerDocument() { return this._doc; }
}

/** Dispara um evento nos ouvintes do elemento (e, se `sobe`, nos ancestrais). Devolve o evento. */
export function emitir(alvo, tipo, extra = {}, sobe = false) {
  const evento = { type: tipo, target: alvo, defaultPrevented: false, returnValue: undefined, preventDefault() { this.defaultPrevented = true; }, ...extra };
  for (let e = alvo; e; e = sobe ? e.parentElement : null) {
    (e.ouvintes[tipo] ?? []).forEach((fn) => fn(evento));
  }
  return evento;
}

export function el(tag, attrs = {}, ...filhos) {
  const e = new Elemento(tag, attrs);
  filhos.forEach((f) => { if (typeof f === 'string') { e._texto += f; } else { e.appendChild(f); } });
  return e;
}

/**
 * Carrega pbl-formulario.js sobre a árvore `corpo` e devolve o ambiente (document, window, chamadas de URL, temporizadores).
 * `corpo` é montado dentro de <body>; `url` é o endereço da "página".
 */
export function carregar(corpo, { url = 'https://painel.exemplo.org/_pbl/objeto/7/editar?salvo=1' } = {}) {
  const raiz = el('html');
  const body = el('body');
  raiz.appendChild(body);
  (Array.isArray(corpo) ? corpo : [corpo]).forEach((c) => body.appendChild(c));
  const marcar = (n) => { n._doc = documento; n.children.forEach(marcar); };
  const janela = { ouvintes: {}, addEventListener(t, fn) { (this.ouvintes[t] ??= []).push(fn); }, location: { href: url }, historico: [] };
  janela.history = { replaceState: (_a, _b, u) => { janela.historico.push(u); janela.location.href = u; } };
  const temporizadores = [];
  janela.setTimeout = (fn, ms) => { temporizadores.push({ fn, ms }); return temporizadores.length; };
  const blobs = { criados: [], revogados: [] };
  class UrlFalsa extends URL {
    static createObjectURL(arquivo) { const u = 'blob:fake/' + (blobs.criados.length + 1); blobs.criados.push({ u, arquivo }); return u; }
    static revokeObjectURL(u) { blobs.revogados.push(u); }
  }
  janela.URL = UrlFalsa;
  const documento = {
    activeElement: null,
    querySelector: (s) => raiz.querySelector(s),
    querySelectorAll: (s) => raiz.querySelectorAll(s),
    getElementById: (id) => raiz.querySelector('[id="' + id + '"]'),
    createElement: (t) => { const e = new Elemento(t); e._doc = documento; return e; },
  };
  marcar(raiz);
  const contexto = vm.createContext({ document: documento, window: janela, URL: UrlFalsa, history: janela.history, console });
  const codigo = readFileSync(join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'public', '_pbl', 'assets', 'pbl-formulario.js'), 'utf8');
  vm.runInContext(codigo, contexto, { filename: 'pbl-formulario.js' });
  return { document: documento, window: janela, blobs, temporizadores, raiz, body };
}

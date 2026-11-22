/* DOM mínimo para testar os scripts do painel sem navegador nem dependência (mesma técnica do restante de tests/unit: node:test + node:vm).
   Cobre só o que public/_pbl/assets/pbl-formulario.js usa: árvore de elementos, atributos, seletores simples, eventos, foco e URL.createObjectURL. */
import { Script, createContext } from 'node:vm';

const VAZIOS = new Set(['input', 'img', 'br', 'hr', 'meta', 'link']);
const BOOLEANOS = ['hidden', 'required', 'disabled', 'open', 'selected', 'checked'];

/* ---------------- seletores ---------------- */
function dividirTopo(texto, separador) {
  const partes = [];
  let nivel = 0, aspas = '', atual = '';
  for (const c of texto) {
    if (aspas) { if (c === aspas) aspas = ''; }
    else if (c === '"' || c === "'") aspas = c;
    else if (c === '[' || c === '(') nivel++;
    else if (c === ']' || c === ')') nivel--;
    if (!aspas && nivel === 0 && (separador === ' ' ? /\s/.test(c) : c === separador)) {
      if (atual.trim()) partes.push(atual.trim());
      atual = '';
    } else atual += c;
  }
  if (atual.trim()) partes.push(atual.trim());
  return partes;
}

function compor(texto) {
  const c = { tag: null, id: null, classes: [], attrs: [], nots: [] };
  let resto = texto;
  const tag = /^[a-zA-Z][a-zA-Z0-9]*/.exec(resto);
  if (tag) { c.tag = tag[0].toLowerCase(); resto = resto.slice(tag[0].length); }
  while (resto) {
    let m;
    if ((m = /^#([\w-]+)/.exec(resto))) c.id = m[1];
    else if ((m = /^\.([\w-]+)/.exec(resto))) c.classes.push(m[1]);
    else if ((m = /^\[([\w-]+)(?:([\^$*]?=)"?([^"\]]*)"?)?\]/.exec(resto))) c.attrs.push({ nome: m[1], op: m[2] || null, valor: m[3] });
    else if ((m = /^:not\(([^)]*)\)/.exec(resto))) c.nots.push(compor(m[1]));
    else throw new Error('seletor não suportado pelo DOM falso: ' + texto);
    resto = resto.slice(m[0].length);
  }
  return c;
}

function confere(el, c) {
  if (c.tag && el.tagName.toLowerCase() !== c.tag) return false;
  if (c.id && el.getAttribute('id') !== c.id) return false;
  if (!c.classes.every((k) => el.className.split(/\s+/).includes(k))) return false;
  for (const a of c.attrs) {
    if (!el.hasAttribute(a.nome)) return false;
    const v = el.getAttribute(a.nome);
    if (a.op === '=' && v !== a.valor) return false;
    if (a.op === '^=' && !v.startsWith(a.valor)) return false;
    if (a.op === '$=' && !v.endsWith(a.valor)) return false;
    if (a.op === '*=' && !v.includes(a.valor)) return false;
  }
  return !c.nots.some((n) => confere(el, n));
}

const analisar = (seletor) => dividirTopo(seletor, ',').map((s) => dividirTopo(s, ' ').map(compor));

function casa(el, cadeias) {
  return cadeias.some((cadeia) => {
    if (!confere(el, cadeia[cadeia.length - 1])) return false;
    let i = cadeia.length - 2;
    for (let p = el.parentElement; i >= 0 && p; p = p.parentElement) if (confere(p, cadeia[i])) i--;
    return i < 0;
  });
}

/* ---------------- elementos ---------------- */
class Texto {
  constructor(t) { this.nodeType = 3; this.data = t; this.parentElement = null; }
  get textContent() { return this.data; }
}

export class Elemento {
  constructor(tag, documento) {
    this.tagName = tag.toUpperCase();
    this.ownerDocument = documento;
    this.attrs = new Map();
    this.childNodes = [];
    this.parentElement = null;
    this.ouvintes = {};
    this.nodeType = 1;
    this._valor = null;
    this._marcado = null;
    this._tabIndex = null;
    this.files = [];
    this.focado = 0;
  }
  get children() { return this.childNodes.filter((n) => n.nodeType === 1); }
  get firstChild() { return this.childNodes[0] || null; }
  get id() { return this.getAttribute('id') || ''; }
  set id(v) { this.setAttribute('id', v); }
  get className() { return this.getAttribute('class') || ''; }
  set className(v) { this.setAttribute('class', v); }
  get type() { return this.getAttribute('type') || (this.tagName === 'INPUT' ? 'text' : ''); }
  set type(v) { this.setAttribute('type', v); }
  get name() { return this.getAttribute('name') || ''; }
  get maxLength() { return this.hasAttribute('maxlength') ? parseInt(this.getAttribute('maxlength'), 10) : -1; }
  get tabIndex() { return this._tabIndex ?? 0; }
  set tabIndex(v) { this._tabIndex = v; this.attrs.set('tabindex', String(v)); }
  get alt() { return this.getAttribute('alt') || ''; }
  set alt(v) { this.setAttribute('alt', v); }
  get src() { return this.getAttribute('src') || ''; }
  set src(v) { this.setAttribute('src', v); }
  get width() { return Number(this.getAttribute('width')); }
  set width(v) { this.setAttribute('width', v); }
  get height() { return Number(this.getAttribute('height')); }
  set height(v) { this.setAttribute('height', v); }
  set placeholder(v) { this.setAttribute('placeholder', v); }
  get options() { return this.querySelectorAll('option'); }
  get value() {
    if (this._valor !== null) return this._valor;
    if (this.tagName === 'SELECT') { const o = this.options.find((x) => x.selected); return o ? o.getAttribute('value') ?? o.textContent : ''; }
    return this.getAttribute('value') ?? (this.tagName === 'TEXTAREA' ? this.textContent : '');
  }
  set value(v) { this._valor = String(v); }
  get checked() { return this._marcado ?? this.hasAttribute('checked'); }
  set checked(v) { this._marcado = !!v; }
  get textContent() { return this.childNodes.map((n) => n.textContent).join(''); }
  set textContent(t) { this.childNodes.forEach((n) => { n.parentElement = null; }); this.childNodes = []; if (t !== '' && t != null) this.appendChild(new Texto(String(t))); }
  hasAttribute(n) { return this.attrs.has(n) || (n === 'checked' && this._marcado === true); }
  getAttribute(n) { return this.attrs.has(n) ? this.attrs.get(n) : null; }
  setAttribute(n, v) { this.attrs.set(n, String(v)); if (n === 'tabindex') this._tabIndex = Number(v); }
  removeAttribute(n) { this.attrs.delete(n); }
  appendChild(n) { if (n.parentElement) n.parentElement.removeChild(n); n.parentElement = this; this.childNodes.push(n); return n; }
  removeChild(n) { this.childNodes = this.childNodes.filter((x) => x !== n); n.parentElement = null; return n; }
  insertAdjacentElement(pos, el) {
    const pai = this.parentElement, i = pai.childNodes.indexOf(this);
    if (el.parentElement) el.parentElement.removeChild(el);
    el.parentElement = pai;
    pai.childNodes.splice(pos === 'afterend' ? i + 1 : i, 0, el);
    return el;
  }
  querySelectorAll(seletor) {
    const cadeias = analisar(seletor), achados = [];
    const andar = (no) => { for (const f of no.children) { if (casa(f, cadeias)) achados.push(f); andar(f); } };
    andar(this);
    return achados;
  }
  querySelector(seletor) { return this.querySelectorAll(seletor)[0] || null; }
  matches(seletor) { return casa(this, analisar(seletor)); }
  closest(seletor) { const c = analisar(seletor); for (let e = this; e; e = e.parentElement) if (casa(e, c)) return e; return null; }
  focus() { this.focado++; this.ownerDocument.activeElement = this; }
  addEventListener(tipo, fn) { (this.ouvintes[tipo] ||= []).push(fn); }
  dispatchEvent(ev) {
    ev.target = ev.target || this;
    (this.ouvintes[ev.type] || []).forEach((f) => f.call(this, ev));
    return !ev.defaultPrevented;
  }
}
for (const b of BOOLEANOS) {
  if (b === 'checked') continue;
  Object.defineProperty(Elemento.prototype, b, {
    get() { return this.attrs.has(b); },
    set(v) { if (v) this.attrs.set(b, ''); else this.attrs.delete(b); },
  });
}

export function evento(tipo, extra = {}) {
  const ev = { type: tipo, defaultPrevented: false, returnValue: undefined, preventDefault() { this.defaultPrevented = true; }, ...extra };
  return ev;
}

/* ---------------- HTML mínimo (fixtures legíveis) ---------------- */
export function montar(html, documento) {
  const raiz = new Elemento('body', documento);
  const pilha = [raiz];
  const re = /<!--[\s\S]*?-->|<\/([a-zA-Z0-9]+)\s*>|<([a-zA-Z][a-zA-Z0-9]*)((?:\s+[^\s=>/]+(?:\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+))?)*)\s*\/?>|([^<]+)/g;
  let m;
  while ((m = re.exec(html))) {
    const topo = pilha[pilha.length - 1];
    if (m[1]) { while (pilha.length > 1 && pilha.pop().tagName.toLowerCase() !== m[1].toLowerCase()); }
    else if (m[2]) {
      const el = new Elemento(m[2], documento);
      for (const a of m[3].matchAll(/([^\s=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+)))?/g)) el.setAttribute(a[1], a[2] ?? a[3] ?? a[4] ?? '');
      topo.appendChild(el);
      if (!VAZIOS.has(m[2].toLowerCase())) pilha.push(el);
    } else if (m[4] && m[4].trim() !== '') topo.appendChild(new Texto(m[4].replace(/\s+/g, ' ')));
  }
  return raiz;
}

/* ---------------- ambiente do script ---------------- */
/** Carrega `codigo` (o JS do painel) sobre uma página montada de `html`; devolve o que os testes precisam para interagir. */
export function carregar(codigo, html, { url = 'https://exemplo.test/_pbl/objeto/7/editar?salvo=1', nome = 'script.js' } = {}) {
  const doc = { activeElement: null, ouvintes: {} };
  doc.body = montar(html, doc);
  doc.documentElement = new Elemento('html', doc);
  doc.createElement = (t) => new Elemento(t, doc);
  doc.getElementById = (id) => doc.body.querySelector('#' + id);
  doc.querySelector = (s) => doc.body.querySelector(s);
  doc.querySelectorAll = (s) => doc.body.querySelectorAll(s);

  const blobs = { criados: [], revogados: [] };
  class Url extends URL {
    static createObjectURL(arq) { const u = 'blob:fake/' + (blobs.criados.length + 1); blobs.criados.push({ u, arq }); return u; }
    static revokeObjectURL(u) { blobs.revogados.push(u); }
  }
  const tarefas = [];
  const janela = {
    location: { href: url },
    history: { chamadas: [], replaceState(_a, _b, u) { this.chamadas.push(u); } },
    URL: Url,
    ouvintes: {},
    addEventListener(t, f) { (this.ouvintes[t] ||= []).push(f); },
    setTimeout(f, ms) { tarefas.push({ f, ms }); return tarefas.length; },
  };
  janela.window = janela;
  const contexto = createContext({ document: doc, window: janela, history: janela.history, URL: Url, console });
  new Script(codigo, { filename: nome }).runInContext(contexto);
  return {
    doc, janela, blobs, tarefas,
    $: (s) => doc.body.querySelector(s),
    $$: (s) => doc.body.querySelectorAll(s),
    /** Dispara `beforeunload` e devolve o evento (para ver se pediu confirmação). */
    sair() { const ev = evento('beforeunload'); (janela.ouvintes.beforeunload || []).forEach((f) => f(ev)); return ev; },
    rodarTarefas() { tarefas.splice(0).forEach((t) => t.f()); },
  };
}

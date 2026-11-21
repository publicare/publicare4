import { readdirSync, readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

export const RAIZ = join(dirname(fileURLToPath(import.meta.url)), '..');
export const paginas = () => readdirSync(RAIZ).filter((f) => f.endsWith('.html')).sort();
export const ler = (arq) => readFileSync(join(RAIZ, arq), 'utf8');
export const existe = (arq) => existsSync(join(RAIZ, arq));
export const semComentarios = (html) => html.replace(/<!--[\s\S]*?-->/g, '');

export const ids = (html) => [...html.matchAll(/\sid="([^"]*)"/g)].map((m) => m[1]);
export const hrefs = (html) => [...html.matchAll(/<a\b[^>]*\shref="([^"]*)"/g)].map((m) => m[1]);
export const tags = (html, nome) => [...html.matchAll(new RegExp(`<${nome}\\b([^>]*)>`, 'g'))].map((m) => m[1]);
export const attr = (s, nome) => (s.match(new RegExp(`(?:^|\\s)${nome}="([^"]*)"`)) || [])[1];

// Contraste WCAG 2.x
const lin = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
export const luminancia = (hex) => { const n = parseInt(hex.slice(1), 16); return 0.2126 * lin(n >> 16) + 0.7152 * lin((n >> 8) & 255) + 0.0722 * lin(n & 255); };
export const contraste = (a, b) => { const [x, y] = [luminancia(a), luminancia(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };

export function tokens(css) {
  const bloco = (seletor) => { const i = css.indexOf(seletor + ' {'); const f = css.indexOf('}', i); return css.slice(i, f); };
  const ler = (b) => Object.fromEntries([...b.matchAll(/(--pbl-[a-z-]+):\s*(#[0-9a-fA-F]{6})\s*;/g)].map((m) => [m[1], m[2]]));
  const claro = ler(bloco(':root'));
  const escuro = { ...claro, ...ler(bloco('[data-bs-theme="dark"]')) };
  return { claro, escuro };
}

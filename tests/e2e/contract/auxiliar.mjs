import { readdirSync, readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const aqui = dirname(fileURLToPath(import.meta.url));
export const E2E = join(aqui, '..');
export const PROTO = join(E2E, '..', '..', 'prototipo-ux');

export const lerProto = (arq) => readFileSync(join(PROTO, arq), 'utf8');
export const protoTem = (arq) => existsSync(join(PROTO, arq.split('#')[0].split('?')[0]));
export const features = () => readdirSync(E2E).filter((f) => f.endsWith('.feature')).sort();
export const specs = () => readdirSync(join(E2E, 'steps')).filter((f) => f.endsWith('.spec.mjs')).sort();
export const lerE2e = (rel) => readFileSync(join(E2E, rel), 'utf8');
export const ids = (html) => new Set([...html.matchAll(/\sid="([^"]*)"/g)].map((m) => m[1]));

/** Cenários (e esquemas) de um .feature, em ordem. */
export function cenarios(texto) {
  return [...texto.matchAll(/^\s*(?:Cenário|Esquema do Cenário):\s*(.+)$/gm)].map((m) => m[1].trim());
}

/** Passos "Dado/Quando/E ... "texto"" por cenário, com a tela corrente. */
export function telasCitadas(texto) {
  return [...texto.matchAll(/"([\w-]+\.html)(?:[#?][^"]*)?"/g)].map((m) => m[1]);
}

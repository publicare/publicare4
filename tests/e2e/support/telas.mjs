import { execFileSync } from 'node:child_process';
import { mkdtempSync, readdirSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const AQUI = dirname(fileURLToPath(import.meta.url));
export const RAIZ = join(AQUI, '..', '..', '..');
const LER = (p) => readFileSync(p, 'utf8');

export const ler = (relativo) => LER(join(RAIZ, relativo));

let cache = null;

/** Renderiza as telas do painel (php, site descartável) uma vez por processo. Devolve { slug: html }. */
export function telas() {
  if (cache) return cache;
  const pasta = mkdtempSync(join(tmpdir(), 'pbl-telas-'));
  execFileSync('php', [join(AQUI, 'renderizar-telas.php'), pasta], { cwd: RAIZ, stdio: ['ignore', 'ignore', 'inherit'] });
  cache = Object.fromEntries(readdirSync(pasta).filter((f) => f.endsWith('.html')).map((f) => [f.replace(/\.html$/, ''), LER(join(pasta, f))]));
  return cache;
}

export const texto = (html) => html
  .replace(/<(script|style)\b[\s\S]*?<\/\1>/gi, ' ').replace(/<[^>]+>/g, ' ')
  .replace(/&nbsp;/g, ' ').replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&#0?39;/g, "'").replace(/\s+/g, ' ').trim();

/** Blocos <details class="pbl-ajuda">…</details> de uma tela. */
export const ajudas = (html) => [...html.matchAll(/<details class="pbl-ajuda"[^>]*>[\s\S]*?<\/details>/g)].map((m) => m[0]);

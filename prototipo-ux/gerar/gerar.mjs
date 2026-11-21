// Gera os HTML do protótipo. Uso: npm run gerar (na pasta prototipo-ux).
import { writeFileSync, readdirSync, unlinkSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import telas from './telas.mjs';
import guia from './guia.mjs';

const raiz = join(dirname(fileURLToPath(import.meta.url)), '..');
telas['guia.html'] = guia;
const novos = Object.keys(telas);
for (const f of readdirSync(raiz).filter((x) => x.endsWith('.html') && !novos.includes(x))) unlinkSync(join(raiz, f));
for (const [arq, html] of Object.entries(telas)) writeFileSync(join(raiz, arq), html);
console.log(`${novos.length} telas geradas`);

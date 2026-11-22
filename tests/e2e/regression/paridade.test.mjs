// Todo cenário Gherkin precisa de um teste (Playwright em steps/ ou node:test em regression/) com o mesmo título.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const E2E = join(dirname(fileURLToPath(import.meta.url)), '..');
const lista = (dir, ext) => readdirSync(join(E2E, dir)).filter((f) => f.endsWith(ext)).map((f) => readFileSync(join(E2E, dir, f), 'utf8'));
const cenarios = lista('features', '.feature').flatMap((c) => [...c.matchAll(/^\s*Cenário: (.+)$/gm)].map((m) => m[1].trim()));
const specs = [...lista('steps', '.spec.mjs'), ...lista('regression', '.test.mjs')].join('\n');

test('há cenários Gherkin', () => assert.ok(cenarios.length >= 9));
for (const t of cenarios) test(`cenário com spec: ${t}`, () => assert.ok(specs.includes(`test('${t}'`), `sem spec para “${t}”`));

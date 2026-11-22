// Regressão do CSS/telas da listagem: alvos de toque, pontos de quebra, CSP sem inline, tema.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const RAIZ = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
const css = readFileSync(join(RAIZ, 'public/_pbl/assets/pbl.css'), 'utf8');
const bloco = (re) => css.split('\n').filter((l) => re.test(l)).join('\n');

test('alvo de toque ≥ 44 px e botões com a mesma altura', () => {
  assert.match(css, /--pbl-alvo:\s*44px/);
  assert.match(bloco(/^\.pbl-lc-acao \{/), /height: var\(--pbl-alvo\)/);
  assert.match(bloco(/^\.pbl-lc-item \{/), /min-height: var\(--pbl-alvo\)/);
});

test('três formatos: cartão (<576), linha (576–991), denso (≥992)', () => {
  assert.match(css, /@media \(min-width: 576px\)/);
  assert.match(css, /@media \(min-width: 992px\)/);
  assert.match(bloco(/^\.pbl-lc \{/), /grid-template-areas: "sel icone titulo" "acoes acoes acoes"/);
  assert.match(css, /pbl-lc \{ grid-template-columns: auto auto minmax\(0, 1fr\) auto;/);
});

test('título em até 2 linhas e data sem quebra', () => {
  assert.match(bloco(/^\.pbl-lc-link \{/), /line-clamp: 2/);
  assert.match(bloco(/^\.pbl-lc-data, /), /white-space: nowrap/);
});

test('foco visível e tema por variáveis (sem cores fixas de fundo)', () => {
  assert.match(css, /\.pbl-lc-link:focus-visible[^{]*\{ outline: 3px/);
  assert.match(bloco(/^\.pbl-lc-acao \{/), /var\(--bs-tertiary-bg\)/);
  assert.match(bloco(/^\.pbl-lc-item--perigo \{/), /var\(--bs-danger-text-emphasis\)/);
});

test('o código da linha não usa estilo em linha, script nem fonte externa', () => {
  for (const f of ['app/src/Admin/LinhaDeConteudo.php', 'app/src/Admin/IconesDeClasse.php']) {
    const s = readFileSync(join(RAIZ, f), 'utf8');
    assert.doesNotMatch(s, /style="|<script|onclick|https?:\/\/fonts|@import/, f);
  }
});

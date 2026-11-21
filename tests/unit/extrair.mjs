// Extrai funções puras de prototipo-ux/assets/proto.js (IIFE sem exports) para testá-las sem navegador.
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

export const RAIZ = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'prototipo-ux');
const fonte = readFileSync(join(RAIZ, 'assets', 'proto.js'), 'utf8');

function corpoDaFuncao(nome) {
  const ini = fonte.indexOf(`function ${nome}(`);
  if (ini < 0) throw new Error(`função ${nome} não encontrada em proto.js`);
  let i = fonte.indexOf('{', ini), nivel = 0;
  for (; i < fonte.length; i++) {
    if (fonte[i] === '{') nivel++;
    else if (fonte[i] === '}' && --nivel === 0) return fonte.slice(ini, i + 1);
  }
  throw new Error(`função ${nome} sem fim`);
}

/** Carrega as funções e variáveis pedidas num contexto isolado, com `palco` (stubs) como globais. */
export function carregar(nomes, palco = {}, extras = ['var RE_EMAIL = /^[^\\s@]+@[^\\s@]+\\.[^\\s@]{2,}$/;']) {
  const re = fonte.match(/var RE_EMAIL = .*;/);
  if (!re) throw new Error('RE_EMAIL não encontrada');
  const codigo = [re[0], ...nomes.map(corpoDaFuncao), `({${nomes.join(',')}})`].join('\n');
  return vm.runInNewContext(codigo, { URL, setTimeout, clearTimeout, ...palco });
}

/** Campo fictício mínimo para validarCampo. */
export function campo({ valor = '', dataset = {}, attrs = {}, oculto = false, id = 'c', rotulo = 'Título', tipoInput = 'text' } = {}) {
  return {
    id, value: valor, dataset, type: tipoInput, disabled: false, name: id,
    hasAttribute: (n) => n === 'data-obrigatorio' ? 'obrigatorio' in dataset : n in attrs,
    getAttribute: (n) => (n in attrs ? attrs[n] : null),
    closest: () => (oculto ? { hasAttribute: () => false } : null),
    _rotulo: rotulo,
  };
}

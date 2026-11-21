// Componentes do guia visual (GUIA-VISUAL.md) como funções que devolvem HTML. Sem dependências.
export const esc = (t) => String(t ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const attrs = (o) => Object.entries(o).filter(([, v]) => v !== undefined && v !== false && v !== null)
  .map(([k, v]) => (v === true ? ` ${k}` : ` ${k}="${esc(v)}"`)).join('');

export const icone = {
  busca: '<svg class="icone" viewBox="0 0 16 16" aria-hidden="true"><circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5 14 14"/></svg>',
  externo: '<svg class="icone" viewBox="0 0 16 16" aria-hidden="true"><path d="M6 3H3v10h10v-3M9 3h4v4M13 3 7 9"/></svg>',
  aviso: '<svg class="icone" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 2 1.5 13.5h13L8 2ZM8 6.5v3.2M8 11.6v.1"/></svg>',
  ok: '<svg class="icone" viewBox="0 0 16 16" aria-hidden="true"><path d="m3 8.5 3.2 3.2L13 4.8"/></svg>',
};

/* ---- campos ---- */
export function campo(o) {
  const { id, rotulo, ajuda, valor = '', tipo = 'text', obrigatorio, max, regra, min, maxValor, prefixo, curto, medio, area, alto, linhas, opcoes, somenteLeitura, auto, placeholder, opcional } = o;
  const desc = `${ajuda ? `${id}-ajuda ` : ''}${id}-erro`;
  const comum = {
    id, name: id, 'aria-describedby': desc,
    'aria-required': obrigatorio ? 'true' : undefined,
    'data-obrigatorio': obrigatorio ? '' : undefined, 'data-max': max, 'data-tipo': regra, 'data-min': min, 'data-max-valor': maxValor,
    readonly: somenteLeitura, autocomplete: auto, placeholder,
  };
  let controle;
  if (opcoes) {
    controle = `<select${attrs({ id, name: id, 'aria-describedby': desc })}>${opcoes.map(([v, t]) => `<option value="${esc(v)}"${v === valor ? ' selected' : ''}>${esc(t)}</option>`).join('')}</select>`;
  } else if (area) {
    controle = `<textarea${attrs({ ...comum, rows: linhas || 4, class: alto ? 'alto' : undefined })}>${esc(valor)}</textarea>`;
  } else {
    const inp = `<input${attrs({ ...comum, type: tipo, value: valor, inputmode: regra === 'telefone' ? 'tel' : undefined })}>`;
    controle = prefixo ? `<div class="com-prefixo"><span aria-hidden="true">${esc(prefixo)}</span>${inp}</div>` : inp;
  }
  const contador = max ? `<span class="contador" data-contador-de="${id}" data-max="${max}">até ${max} caracteres</span>` : '';
  const classe = ['campo', curto && 'campo-curto', medio && 'campo-medio'].filter(Boolean).join(' ');
  return `<div class="${classe}">
  <div class="campo-cab"><label for="${id}">${esc(rotulo)}${opcional ? '<span class="opcional"> (opcional)</span>' : ''}</label>${contador}</div>
  ${controle}
  ${ajuda ? `<p class="ajuda" id="${id}-ajuda">${esc(ajuda)}</p>` : ''}
  <p class="erro" id="${id}-erro"></p>
</div>`;
}

export function imagem({ id, rotulo, ajuda, atual, opcional = true }) {
  return `<div class="campo">
  <div class="campo-cab"><label for="${id}">${esc(rotulo)}${opcional ? '<span class="opcional"> (opcional)</span>' : ''}</label></div>
  <div class="arquivo" data-arquivo>
    <div class="miniatura">${atual ? 'Imagem' : 'Sem imagem'}</div>
    <div class="arquivo-info">
      <strong data-nome data-vazio="Nenhuma imagem escolhida">${esc(atual || 'Nenhuma imagem escolhida')}</strong>
      <span class="ajuda">PNG, JPG ou WebP, até 2 MB. Arraste para cá ou escolha.</span>
      <div class="arquivo-botoes"><button type="button" class="btn btn--secundario btn--pequeno" data-escolher>Escolher imagem</button><button type="button" class="btn btn--discreto btn--pequeno" data-tirar>Tirar imagem</button></div>
    </div>
    <input type="file" id="${id}" name="${id}" accept="image/png,image/jpeg,image/webp" aria-describedby="${ajuda ? id + '-ajuda ' : ''}${id}-erro">
  </div>
  ${ajuda ? `<p class="ajuda" id="${id}-ajuda">${esc(ajuda)}</p>` : ''}
  <p class="erro" id="${id}-erro"></p>
</div>`;
}

export function etiquetas({ id, rotulo, ajuda, valor, sugestoes = [] }) {
  return `<div class="campo">
  <div class="campo-cab"><label for="${id}">${esc(rotulo)}<span class="opcional"> (opcional)</span></label></div>
  <div class="etiquetas" data-tags><input type="text" id="${id}" value="${esc(valor)}" aria-describedby="${id}-ajuda ${id}-erro" placeholder="Escreva e aperte Enter"><input type="hidden" name="${id}-lista"></div>
  ${sugestoes.length ? `<div class="sugestoes"><span>Já usadas:</span>${sugestoes.map((s) => `<button type="button" data-sugestao="${esc(s)}">${esc(s)}</button>`).join('')}</div>` : ''}
  <p class="ajuda" id="${id}-ajuda">${esc(ajuda)}</p>
  <p class="erro" id="${id}-erro"></p>
</div>`;
}

export function seletorDeConteudo({ id, rotulo, ajuda, opcoes }) {
  return `<div class="campo">
  <div class="campo-cab"><label for="${id}">${esc(rotulo)}</label></div>
  <div class="combo" data-combo data-opcoes="${esc(JSON.stringify(opcoes))}">
    <input type="text" id="${id}" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="${id}-lista" aria-describedby="${id}-ajuda" autocomplete="off" placeholder="Comece a digitar o nome">
    <input type="hidden" name="${id}-id">
    <ul role="listbox" id="${id}-lista" aria-label="${esc(rotulo)}" hidden></ul>
  </div>
  <p class="ajuda" id="${id}-ajuda">${esc(ajuda)}</p>
</div>`;
}

export function opcoes({ rotulo, itens }) {
  return `<fieldset class="campo"><legend class="rotulo">${esc(rotulo)}</legend><div class="opcoes">${itens.map((i) => `<label for="${i.id}"><input type="${i.tipo || 'checkbox'}" name="${i.nome || i.id}" id="${i.id}"${i.valor ? ` value="${esc(i.valor)}"` : ''}${i.marcado ? ' checked' : ''}><span>${esc(i.texto)}${i.ajuda ? `<small>${esc(i.ajuda)}</small>` : ''}</span></label>`).join('')}</div></fieldset>`;
}

/* ---- agrupamentos ---- */
export const secao = ({ titulo, desc, corpo, id }) => `<section class="secao"${id ? ` id="${id}"` : ''} aria-labelledby="${id || slug(titulo)}-t">
  <div class="secao-cab"><h2 id="${id || slug(titulo)}-t">${esc(titulo)}</h2>${desc ? `<p>${esc(desc)}</p>` : ''}</div>
  <div class="secao-corpo">${corpo}</div>
</section>`;

export const slug = (t) => String(t).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

export function opcao({ id, titulo, ajuda, ligado, campos }) {
  return `<div class="opcao">
  <div class="opcao-cab">
    <label class="opcao-texto" for="${id}"><span class="opcao-titulo">${esc(titulo)}</span><span class="opcao-ajuda" id="${id}-aj">${esc(ajuda)}</span></label>
    <span class="interruptor"><input type="checkbox" role="switch" id="${id}" name="${id}"${ligado ? ' checked' : ''} aria-describedby="${id}-aj"${campos ? ` aria-controls="${id}-campos"` : ''}><span class="trilho" aria-hidden="true"></span></span>
  </div>
  ${campos ? `<div class="opcao-campos" id="${id}-campos">${campos}</div>` : ''}
</div>`;
}

export const recolhido = ({ titulo, desc, corpo, aberto }) => `<details class="recolhido"${aberto ? ' open' : ''}><summary><span>${esc(titulo)}${desc ? `<small>${esc(desc)}</small>` : ''}</span></summary><div class="recolhido-corpo">${corpo}</div></details>`;

export const caixa = ({ tipo = '', icone: ic = 'aviso', corpo, role, id }) => `<div class="caixa${tipo ? ' caixa--' + tipo : ''}"${role ? ` role="${role}"` : ''}${id ? ` id="${id}"` : ''}>${icone[ic] || ''}<div>${corpo}</div></div>`;

export const selo = (texto, tipo = '', extra = '') => `<span class="selo${tipo ? ' selo--' + tipo : ''}"${extra}>${esc(texto)}</span>`;

export const ESTADOS = {
  privado: ['Privado', ''], publicado: ['Publicado', 'ok'], agendado: ['Agendado', 'aviso'],
  vencido: ['Vencido', 'aviso'], lixeira: ['Na lixeira', 'erro'],
};
export const seloDe = (estado) => selo(...ESTADOS[estado]);

/* ---- navegação e cabeçalho ---- */
export const migalhas = (itens) => `<nav class="migalhas" aria-label="Você está em"><ol>${itens.map(([t, h], i) => (i === itens.length - 1 ? `<li><span aria-current="page">${esc(t)}</span></li>` : `<li><a href="${h}">${esc(t)}</a></li>`)).join('')}</ol></nav>`;

export const cabecalho = ({ titulo, desc, selo: s = '', acao = '', coluna }) => `<header class="cabecalho"><div><h1>${esc(titulo)}${s}</h1>${desc ? `<p>${esc(desc)}</p>` : ''}</div>${acao ? `<div class="acoes">${acao}</div>` : ''}</header>`;

export const botao = (texto, href, tipo = 'secundario', extra = {}) => `<a class="btn btn--${tipo}" href="${href}"${attrs(extra)}>${esc(texto)}</a>`;
export const externo = (texto, href) => `<a class="btn btn--secundario" href="${href}" target="_blank" rel="noopener">${esc(texto)} ${icone.externo}<span class="so-leitor"> (abre em outra aba)</span></a>`;

export function abas(lista) {
  return `<nav class="abas" data-abas aria-label="Seções desta tela">${lista.map(([id, t], i) => `<a href="#painel-${id}"${i === 0 ? ' aria-current="page"' : ''}>${esc(t)}</a>`).join('')}</nav>`;
}
export const painel = (id, titulo, corpo) => `<section class="painel" id="painel-${id}" aria-label="${esc(titulo)}">${corpo}</section>`;

export const barra = ({ estado, texto, botoes }) => `<div class="barra-acoes"><div class="barra-acoes-miolo"><p class="estado-salvo" role="status" data-estado="${estado}">${esc(texto)}</p><div class="barra-botoes">${botoes}</div></div></div>`;

export const resumoErros = () => `<div class="caixa caixa--erro" id="resumo-erros" role="alert" tabindex="-1" hidden></div>`;

/* ---- listas ---- */
export function linha({ titulo, href, meta, fim = '', atributos = '', depois = '' }) {
  const t = href ? `<a class="item-titulo" href="${href}">${esc(titulo)}</a>` : `<span class="item-titulo">${esc(titulo)}</span>`;
  return `<div class="item"><div class="item-texto">${t}${meta ? `<span class="item-meta">${meta}</span>` : ''}</div>${fim ? `<div class="item-fim">${fim}</div>` : ''}</div>${depois}`;
}
export const linhaLink = ({ titulo, href, meta, fim = '' }) => `<a class="item" href="${href}"><div class="item-texto"><span class="item-titulo">${esc(titulo)}</span>${meta ? `<span class="item-meta">${esc(meta)}</span>` : ''}</div><div class="item-fim">${fim}<span class="seta" aria-hidden="true">›</span></div></a>`;
export const lista = (itens, classe = '') => `<ul class="lista${classe ? ' ' + classe : ''}">${itens.join('')}</ul>`;

// Peças de HTML reaproveitadas pelas telas. O resultado é HTML estático versionado (as telas funcionam sem JavaScript).
export const SITE = 'Site Exemplo';

export const MENU = [
  ['inicio', 'Início', 'inicio.html'],
  ['conteudo', 'Conteúdo', 'conteudo.html'],
  ['revisar', 'Revisar', 'revisar.html'],
  ['site', 'Site', 'configuracoes.html'],
  ['mais', 'Mais', 'mais.html'],
];

export function pagina({ arquivo, titulo, menu, migalhas = [], corpo, largo = false }) {
  const itens = MENU.map(([chave, rotulo, href]) => {
    const atual = chave === menu ? ' aria-current="page"' : '';
    const n = chave === 'revisar' ? ' <span class="contador-menu">2<span class="visualmente-oculto"> aguardando</span></span>' : '';
    return `<li><a href="${href}"${atual}>${rotulo}${n}</a></li>`;
  }).join('');
  const trilha = migalhas.length
    ? `<nav class="migalhas" aria-label="Você está em"><ol>${migalhas.map(([t, h]) => (h ? `<li><a href="${h}">${t}</a></li>` : `<li><span aria-current="page">${t}</span></li>`)).join('')}</ol></nav>`
    : '';
  return `<!doctype html>
<html lang="pt-BR" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title>${titulo} · PBL4 (protótipo)</title>
<link rel="stylesheet" href="assets/bootstrap-5.3.8/bootstrap.min.css">
<link rel="stylesheet" href="assets/proto.css">
<script src="assets/proto.js"></script>
</head>
<body data-tela="${titulo}">
<a class="pular" href="#principal">Pular para o conteúdo</a>
<header class="topo">
<div class="topo-linha"><a class="marca" href="inicio.html">PBL4 <small>(protótipo)</small></a>
<button type="button" class="btn botao-menu" aria-controls="menu-principal">Menu</button></div>
<nav id="menu-principal" aria-label="Principal"><ul>${itens}</ul></nav>
<div class="topo-ferramentas">
<a class="btn btn-confuso" href="index.html#anotacoes" data-abrir-confuso>Isto ficou confuso</a>
<button type="button" class="btn" data-tema aria-pressed="false">Tema: claro</button>
<p class="usuario">Ana · administradora · <a href="index.html">Sair</a></p>
</div>
<form class="busca-global" role="search" action="buscar.html" method="get">
<label for="busca-global">Buscar</label>
<input type="search" id="busca-global" name="q" autocomplete="off" aria-describedby="busca-ajuda">
<button type="submit" class="btn btn-topo">Buscar</button>
<span id="busca-ajuda" class="visualmente-oculto">Tecla barra (/) leva o foco até aqui.</span>
</form>
</header>
<main id="principal" class="${largo ? 'largo' : ''}" tabindex="-1">
${trilha}
${corpo}
</main>
</body>
</html>
`;
}

export const selo = (estado) => {
  const m = { publicado: ['Publicado', 'selo-publicado'], privado: ['Privado', 'selo-privado'], agendado: ['Agendado', 'selo-agendado'], lixeira: ['Na lixeira', 'selo-lixeira'] }[estado];
  return `<span class="selo ${m[1]}">${m[0]}</span>`;
};

const attrs = (o = {}) => Object.entries(o).filter(([, v]) => v !== false && v !== undefined).map(([k, v]) => (v === true ? ` ${k}` : ` ${k}="${v}"`)).join('');

export function campo({ id, rotulo, ajuda, tipo = 'text', valor = '', obrigatorio = false, max, curto = false, extra = {}, readonly = false, desabilitado = false }) {
  const desc = ajuda ? ` aria-describedby="${id}-ajuda"` : '';
  const a = attrs({ ...(obrigatorio ? { 'data-obrigatorio': true } : {}), ...(max ? { 'data-max': max } : {}), ...extra, ...(readonly ? { readonly: true } : {}), ...(desabilitado ? { disabled: true } : {}) });
  return `<div class="campo${curto ? ' campo-curto' : ''}">
<label for="${id}">${rotulo}${obrigatorio ? '<span class="obrig">(obrigatório)</span>' : ''}</label>
${ajuda ? `<p class="ajuda" id="${id}-ajuda">${ajuda}</p>` : ''}
<input class="form-control" type="${tipo}" id="${id}" name="${id}" value="${valor}"${desc}${a}>
</div>`;
}

export function area({ id, rotulo, ajuda, valor = '', linhas = 3, obrigatorio = false, max, extra = {} }) {
  const a = attrs({ ...(obrigatorio ? { 'data-obrigatorio': true } : {}), ...(max ? { 'data-max': max } : {}), ...extra });
  return `<div class="campo">
<label for="${id}">${rotulo}${obrigatorio ? '<span class="obrig">(obrigatório)</span>' : ''}</label>
${ajuda ? `<p class="ajuda" id="${id}-ajuda">${ajuda}</p>` : ''}
<textarea class="form-control" id="${id}" name="${id}" rows="${linhas}"${ajuda ? ` aria-describedby="${id}-ajuda"` : ''}${a}>${valor}</textarea>
</div>`;
}

export function selecao({ id, rotulo, ajuda, opcoes, curto = false }) {
  return `<div class="campo${curto ? ' campo-curto' : ''}">
<label for="${id}">${rotulo}</label>
${ajuda ? `<p class="ajuda" id="${id}-ajuda">${ajuda}</p>` : ''}
<select class="form-select" id="${id}" name="${id}"${ajuda ? ` aria-describedby="${id}-ajuda"` : ''}>${opcoes.map(([v, t, s]) => `<option value="${v}"${s ? ' selected' : ''}>${t}</option>`).join('')}</select>
</div>`;
}

export function marcar({ id, rotulo, ajuda, marcado = false, extra = {} }) {
  return `<div class="campo"><div class="form-check"><input class="form-check-input" type="checkbox" id="${id}" name="${id}"${marcado ? ' checked' : ''}${ajuda ? ` aria-describedby="${id}-ajuda"` : ''}${attrs(extra)}><label class="form-check-label" for="${id}">${rotulo}</label></div>${ajuda ? `<p class="ajuda" id="${id}-ajuda">${ajuda}</p>` : ''}</div>`;
}

export function opcoesRadio({ nome, legenda, ajuda, itens }) {
  return `<fieldset class="grupo"><legend class="rotulo">${legenda}</legend>${ajuda ? `<p class="ajuda">${ajuda}</p>` : ''}${itens.map(([v, t, marcado, idExtra]) => `<div class="form-check"><input class="form-check-input" type="radio" name="${nome}" id="${nome}-${v}" value="${v}"${marcado ? ' checked' : ''}><label class="form-check-label" for="${nome}-${v}">${t}</label></div>`).join('')}</fieldset>`;
}

export const detalhes = ({ titulo, corpo, aberto = false }) =>
  `<details class="divulga"${aberto ? ' open' : ''}><summary>${titulo}</summary><div class="divulga-corpo">${corpo}</div></details>`;

export const saibaMais = (texto) => `<details class="saiba"><summary>Saiba mais</summary><p class="ajuda">${texto}</p></details>`;

export function imagem({ id, rotulo, ajuda, atual = '' }) {
  return `<div class="campo" data-seletor-imagem data-atual="${atual}">
<label for="${id}">${rotulo}</label>
<p class="ajuda" id="${id}-ajuda">${ajuda || 'PNG, JPG ou WebP, até 2 MB. Você também pode soltar a imagem aqui.'}</p>
<div class="zona-imagem">
<img class="miniatura" alt="" hidden>
<p class="nome-arquivo">${atual || 'Escolha uma imagem'}</p>
<input class="form-control" type="file" id="${id}" name="${id}" accept="image/png,image/jpeg,image/webp" aria-describedby="${id}-ajuda">
<button type="button" class="btn btn-outline-secondary mt-2" data-tirar-imagem hidden>Tirar esta imagem</button>
</div></div>`;
}

export function tags({ id, rotulo, valor }) {
  return `<div class="campo" data-tags="10"><label for="${id}">${rotulo}</label>
<p class="ajuda" id="${id}-ajuda">Palavras separadas por vírgula; até 10. Aperte Enter para adicionar.</p>
<input class="form-control" type="text" id="${id}" name="${id}" value="${valor}" aria-describedby="${id}-ajuda"></div>`;
}

export function seletorConteudo({ id, rotulo, ajuda, atual = '' }) {
  const ops = [['', 'Nenhum'], ['2', 'Quartos › Suíte Master'], ['3', 'Quartos › Quarto Standard'], ['4', 'Quartos › Quarto Família'], ['5', 'Eventos › Noite de Jazz'], ['6', 'Eventos › Feira de Inverno'], ['8', 'Contato']];
  return `<div class="campo" data-seletor-conteudo><label for="${id}">${rotulo}</label>
<p class="ajuda" id="${id}-ajuda">${ajuda}</p>
<select class="form-select" id="${id}" name="${id}" aria-describedby="${id}-ajuda">${ops.map(([v, t]) => `<option value="${v}"${v === atual ? ' selected' : ''}>${t}</option>`).join('')}</select></div>`;
}

export function previas({ titulos, descricoes, imagemId, url }) {
  return `<div class="previa previa-busca" aria-label="Prévia nos resultados de busca">
<p class="p-url">${url}</p><p class="p-titulo" data-espelha="${titulos}" data-limite="60" data-vazio="Título da página"></p>
<p class="p-desc" data-espelha="${descricoes}" data-limite="160" data-vazio="A descrição aparece aqui."></p></div>
<p class="ajuda" data-corte-de="${descricoes}" data-limite="160"></p>
<div class="previa previa-social" aria-label="Prévia ao compartilhar em rede social">
<img alt="" data-imagem-de="${imagemId}" hidden><div class="p-corpo"><p class="p-titulo" data-espelha="${titulos}" data-vazio="Título da página"></p>
<p class="p-desc" data-espelha="${descricoes}" data-limite="100" data-vazio="A descrição aparece aqui."></p></div></div>`;
}

export function abas(lista, rotulo) {
  return `<nav class="abas" aria-label="${rotulo}"><ul>${lista.map(([id, t]) => `<li><a href="#painel-${id}" data-aba="${id}">${t}<span class="marca-aba"></span></a></li>`).join('')}</ul></nav>`;
}
export const painel = (id, titulo, corpo, nome = titulo) =>
  `<section id="painel-${id}" class="painel" data-painel data-nome="${nome}" aria-labelledby="titulo-${id}"><h2 id="titulo-${id}">${titulo}</h2>${corpo}<div class="status" role="status" data-status></div></section>`;

export const barraAcoes = ({ estadoInicial, cancelar, publicar = true }) => `<div class="barra-acoes">
<p class="estado-salvamento" role="status">${estadoInicial}</p>
<div class="grupo-botoes"><a class="cancelar" href="${cancelar}">Cancelar</a>
${publicar ? '<button type="submit" class="btn btn-outline-primary" data-salvar data-acao="publicar">Publicar</button>' : ''}
<button type="submit" class="btn btn-primary" data-salvar data-acao="salvar">Salvar</button></div></div>`;

export const resumoErros = () => '<div class="resumo-erros aviso-erro" role="alert" tabindex="-1" hidden></div>';

export const confirmaSenha = (id) => campo({ id, rotulo: 'Sua senha', tipo: 'password', ajuda: 'Você tem 10 minutos depois de confirmar. Pode colar a senha.', extra: { autocomplete: 'current-password', 'data-msg-exige': 'Digite sua senha para confirmar.' } });

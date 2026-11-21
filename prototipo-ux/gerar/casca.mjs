// Casca comum a todas as telas: menu de 5 itens, migalhas, busca, tema e "Isto ficou confuso".
import { esc, icone, migalhas } from './ui.mjs';

export const MENU = [
  ['inicio', 'Início', 'inicio.html'],
  ['conteudo', 'Conteúdo', 'conteudo.html'],
  ['revisar', 'Revisar', 'revisar.html'],
  ['site', 'Site', 'configuracoes.html'],
  ['mais', 'Mais', 'mais.html'],
];

export function pagina({ titulo, tela, ativo, trilha, corpo, comBarra = false, largura = 'lista' }) {
  const menu = MENU.map(([id, t, h]) => `<li><a href="${h}"${id === ativo ? ' aria-current="page"' : ''}>${esc(t)}${id === 'revisar' ? '<span class="contagem" data-contagem-revisar>2</span>' : ''}</a></li>`).join('');
  return `<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>${esc(titulo)} · Painel PBL4 (protótipo)</title>
<script src="assets/tema.js"></script>
<link rel="stylesheet" href="assets/proto.css">
</head>
<body data-tela="${esc(tela)}">
<a class="pular" href="#principal">Pular para o conteúdo</a>
<div class="app">
  <header class="topo">
    <button type="button" class="btn btn--secundario btn--pequeno btn-menu" aria-controls="lateral" aria-expanded="true">Menu</button>
    <a class="btn confuso" href="index.html#anotacoes" data-confuso>Isto ficou confuso</a>
    ${migalhas(trilha)}
  </header>
  <aside class="lateral" id="lateral" aria-label="Menu do painel">
    <a class="marca" href="inicio.html">Site Exemplo <small>protótipo</small></a>
    <form class="busca" role="search" action="buscar.html" method="get">
      ${icone.busca}
      <label class="so-leitor" for="busca-global">Buscar conteúdo</label>
      <input type="search" id="busca-global" name="q" placeholder="Buscar conteúdo" autocomplete="off" data-ignorar>
      <kbd aria-hidden="true">/</kbd>
    </form>
    <nav class="menu-principal" id="menu-principal" aria-label="Principal"><ul>${menu}</ul></nav>
    <div class="pessoa">
      <div><strong>Ana</strong><span class="pequeno suave">Administradora</span></div>
      <div class="acoes-pessoa"><button type="button" class="btn btn--discreto btn--pequeno" data-tema-alternar>Tema escuro</button> <a class="btn btn--discreto btn--pequeno" href="index.html">Sair</a></div>
    </div>
  </aside>
  <main id="principal" class="principal${comBarra ? ' com-barra' : ''}" tabindex="-1">
    <div class="tela${largura === 'form' ? ' tela--form' : ''}">
${corpo}
    </div>
  </main>
</div>
<dialog id="dlg-confuso" aria-labelledby="dlg-confuso-t">
  <form method="dialog">
    <h2 id="dlg-confuso-t">O que ficou confuso?</h2>
    <p class="suave">Tela: <strong data-tela-nome></strong></p>
    <div class="campo">
      <div class="campo-cab"><label for="confuso-texto">Conte em poucas palavras</label><span class="contador" data-contador-confuso>0 de 300</span></div>
      <textarea id="confuso-texto" rows="4" aria-describedby="confuso-texto-ajuda confuso-texto-erro"></textarea>
      <p class="ajuda" id="confuso-texto-ajuda">Fica só neste aparelho. Não escreva nomes, e-mails ou senhas reais.</p>
      <p class="erro" id="confuso-texto-erro"></p>
    </div>
    <div class="barra-botoes"><button type="button" class="btn btn--discreto" data-cancelar>Cancelar</button><button type="button" class="btn btn--principal" data-anotar>Anotar</button></div>
  </form>
</dialog>
<p class="caixa caixa--ok toast" id="toast" role="status" hidden></p>
<script src="assets/regras.js"></script>
<script src="assets/proto.js"></script>
</body>
</html>
`;
}

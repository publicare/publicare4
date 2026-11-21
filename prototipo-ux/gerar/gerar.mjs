// Gera as telas estáticas de prototipo-ux/. Uso: node prototipo-ux/gerar/gerar.mjs  (os .html gerados ficam versionados).
import { writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { pagina, selo, campo, area, selecao, marcar, detalhes, abas, painel, confirmaSenha, resumoErros, SITE } from './componentes.mjs';
import { telaEdicao } from './telas-edicao.mjs';

const destino = join(dirname(fileURLToPath(import.meta.url)), '..');
const telas = {};
const M_INICIO = ['Início', 'inicio.html'];

/* ---------- Roteiro ---------- */
telas['index.html'] = pagina({
  titulo: 'Roteiro de teste', menu: '', migalhas: [['Roteiro']],
  corpo: `<h1>Roteiro de teste</h1>
<p>Este é um <strong>protótipo</strong> do novo painel do PBL4: dados fictícios do “${SITE}”, nada é gravado de verdade. Teste os 4 caminhos abaixo, de preferência no celular. Sempre que algo travar ou parecer estranho, toque em <strong>Isto ficou confuso</strong> (no topo de qualquer tela) e escreva uma frase.</p>
<ol class="roteiro lista">
<li class="cartao"><h2>1. Trocar o texto de um quarto e publicar</h2>
<ol class="passos"><li>Em <a href="inicio.html">Início</a>, toque em “Continuar: Suíte Master”.</li><li>Toque em “Editar”.</li><li>Troque o título e o texto. Veja o contador e o aviso “Alterações não salvas”.</li><li>Toque em “Salvar”. Depois, na aba Publicação, ou na barra, toque em “Publicar”.</li></ol>
<p><a class="btn btn-primary" href="inicio.html">Começar pelo Início</a></p></li>
<li class="cartao"><h2>2. Criar um evento com data de saída</h2>
<ol class="passos"><li>Em <a href="conteudo-eventos.html">Conteúdo › Eventos</a>, toque em “Criar conteúdo” e escolha “Evento”.</li><li>Preencha título e data do evento.</li><li>Na aba Publicação, ligue “Agendar publicação” e coloque “Tirar do ar” <em>antes</em> de “Publicar a partir de”. Veja o erro.</li><li>Corrija e toque em “Publicar”.</li></ol>
<p><a class="btn btn-primary" href="conteudo-eventos.html">Começar pelos Eventos</a></p></li>
<li class="cartao"><h2>3. Configurar o contato do site</h2>
<ol class="passos"><li>No menu, toque em “Site”.</li><li>Abra a aba “Contato”.</li><li>Digite um e-mail errado e saia do campo. Corrija. Preencha telefone e redes sociais.</li><li>Toque em “Salvar esta aba”.</li></ol>
<p><a class="btn btn-primary" href="configuracoes.html#painel-contato">Começar em Configurações</a></p></li>
<li class="cartao"><h2>4. Achar algo apagado</h2>
<ol class="passos"><li>Toque no campo “Buscar” (ou aperte a tecla /) e digite “Suíte”.</li><li>Ache “Suíte Antiga”, que está na lixeira.</li><li>Toque em “Recuperar” e confirme.</li></ol>
<p><a class="btn btn-primary" href="buscar.html?q=Su%C3%ADte">Começar pela busca</a></p></li>
</ol>
<p class="aviso-bloco">Fora deste protótipo: Domínios, Redirecionamentos, Tipos de conteúdo e Tarefas agendadas.</p>
<section class="cartao" id="anotacoes" aria-labelledby="t-anot"><h2 id="t-anot">Seus pontos de confusão (<span id="total-confuso">0</span>)</h2>
<p>As anotações ficam só neste aparelho. Ao terminar, copie o resumo e envie para quem pediu o teste.</p>
<p><a class="btn btn-confuso-pagina btn-outline-primary" href="#anotacoes" data-abrir-confuso>Isto ficou confuso</a></p>
<div class="campo"><label for="resumo-confuso">Resumo para copiar</label>
<textarea id="resumo-confuso" class="form-control resumo" readonly rows="8">Este navegador está sem JavaScript: as anotações precisam dele. Ligue o JavaScript para anotar e copiar o resumo.</textarea></div>
<div class="acoes"><button type="button" class="btn btn-primary" id="copiar-resumo">Copiar resumo</button><button type="button" class="btn btn-outline-danger" id="apagar-anotacoes">Apagar anotações</button></div>
<p role="status" id="status-resumo" class="ajuda"></p></section>
<p class="ajuda">Todas as telas: <a href="inicio.html">Início</a> · <a href="conteudo.html">Conteúdo</a> · <a href="revisar.html">Revisar</a> · <a href="configuracoes.html">Site</a> · <a href="mais.html">Mais</a>.</p>`,
});

/* ---------- Início ---------- */
telas['inicio.html'] = pagina({
  titulo: 'Início', menu: 'inicio', migalhas: [['Início']],
  corpo: `<h1>Início</h1>
<p>Olá, Ana. Você é administradora deste site.</p>
<p class="aviso-bloco"><a href="revisar.html">2 envios aguardam sua revisão</a></p>
<section class="cartao destaque" aria-labelledby="t-cont"><h2 id="t-cont">Continuar de onde parou</h2>
<p><strong>Suíte Master</strong> · editado há 2 dias</p>
<div class="acoes"><a class="btn btn-primary" href="ver.html">Continuar: Suíte Master</a><a class="btn btn-outline-secondary" href="ver.html" target="_blank" rel="noopener">Ver o site ↗<span class="visualmente-oculto"> (abre em nova aba)</span></a></div></section>
<section aria-labelledby="t-site"><h2 id="t-site">${SITE}</h2>
<div class="acoes"><a class="btn btn-outline-primary" href="configuracoes.html">Configurações</a><a class="btn btn-outline-primary" href="mais.html">Mais (avançado)</a></div>
<div class="grade-cartoes">
<a class="cartao cartao-link" href="conteudo.html"><strong>Quartos</strong><span>3 itens · você administra</span></a>
<a class="cartao cartao-link" href="conteudo-eventos.html"><strong>Eventos</strong><span>2 itens · Bruno edita</span></a>
<a class="cartao cartao-link" href="conteudo-novidades.html"><strong>Novidades</strong><span>1 item</span></a></div></section>`,
});

/* ---------- Conteúdo ---------- */
function listaConteudo({ arquivo, titulo, secao, itens, estrutura }) {
  const li = itens.map(([t, estado, quando, filhos, href]) => `<li class="item" data-item data-titulo="${t}" data-estado="${estado}">
<div><span class="item-titulo">${t}</span> ${selo(estado)}</div><div class="item-meta">Alterado ${quando} · ${filhos}</div>
<div class="item-acoes"><a class="btn btn-outline-primary" href="${href[0]}">Editar<span class="visualmente-oculto"> ${t}</span></a><a class="btn btn-outline-secondary" href="${href[1]}">Abrir<span class="visualmente-oculto"> ${t}</span></a></div></li>`).join('');
  return pagina({
    arquivo, titulo, menu: 'conteudo', migalhas: [M_INICIO, [SITE, 'conteudo.html'], [secao]],
    corpo: `<h1>${secao} <span class="ajuda">(${itens.filter((i) => i[1] !== 'lixeira').length} itens)</span></h1>
<div class="acoes"><a class="btn btn-primary" href="criar.html">Criar conteúdo</a></div>
<details class="divulga estrutura"><summary>Estrutura do site</summary><div class="divulga-corpo"><ul>
<li><a href="conteudo.html">Quartos</a><ul><li><a href="ver.html">Suíte Master</a></li><li><a href="ver.html">Quarto Standard</a></li><li><a href="ver.html">Quarto Família</a></li></ul></li>
<li><a href="conteudo-eventos.html">Eventos</a><ul><li><a href="editar-evento.html">Novo evento</a></li><li><a href="conteudo-eventos.html">Feira de Inverno</a></li></ul></li>
<li><a href="conteudo-novidades.html">Novidades</a><ul><li><a href="editar.html">Inverno no Sítio</a></li></ul></li></ul></div></details>
<details class="divulga"><summary>Filtros e busca</summary><div class="divulga-corpo"><form data-filtros action="${arquivo}" method="get">
<div class="campo"><label for="filtro-q">Buscar por título</label><input class="form-control" type="search" id="filtro-q" name="q" autocomplete="off"></div>
<div class="campo campo-curto"><label for="filtro-estado">Estado</label><select class="form-select" id="filtro-estado" name="estado"><option value="todos" selected>Todos</option><option value="privado">Privados</option><option value="publicado">Publicados</option><option value="agendado">Agendados</option><option value="vencido">Vencidos</option><option value="lixeira">Lixeira</option></select></div>
<button type="submit" class="btn btn-outline-primary">Filtrar</button></form></div></details>
<p role="status" data-contagem class="ajuda"></p>
<ul class="lista" data-lista-conteudo>${li}</ul>
<p data-sem-resultado hidden>Nada encontrado com esses filtros. Limpe a busca ou escolha “Todos”.</p>
<p class="aviso-ok" ${itens.length ? 'hidden' : ''}>Esta seção ainda não tem conteúdo.</p>
<nav aria-label="Páginas" class="acoes"><span class="btn btn-outline-secondary disabled" aria-disabled="true">◂ Anterior</span><span class="align-self-center">Página 1 de 1</span><span class="btn btn-outline-secondary disabled" aria-disabled="true">Próxima ▸</span></nav>`,
  });
}
telas['conteudo.html'] = listaConteudo({ arquivo: 'conteudo.html', titulo: 'Conteúdo', secao: 'Quartos', itens: [
  ['Suíte Master', 'privado', 'há 2 dias', '0 filhos', ['editar-pagina.html', 'ver.html']],
  ['Quarto Standard', 'publicado', 'há 3 semanas', '0 filhos', ['editar-pagina.html', 'ver.html']],
  ['Quarto Família', 'publicado', 'há 1 mês', '0 filhos', ['editar-pagina.html', 'ver.html']],
  ['Suíte Antiga', 'lixeira', 'há 4 meses', '0 filhos', ['editar-pagina.html', 'ver.html']],
] });
telas['conteudo-eventos.html'] = listaConteudo({ arquivo: 'conteudo-eventos.html', titulo: 'Conteúdo: Eventos', secao: 'Eventos', itens: [
  ['Feira de Inverno', 'agendado', 'há 5 dias', '0 filhos', ['editar-evento.html', 'ver.html']],
  ['Jantar de Boas-vindas', 'publicado', 'há 2 meses', '0 filhos', ['editar-evento.html', 'ver.html']],
] });
telas['conteudo-novidades.html'] = listaConteudo({ arquivo: 'conteudo-novidades.html', titulo: 'Conteúdo: Novidades', secao: 'Novidades', itens: [
  ['Inverno no Sítio', 'publicado', 'há 1 semana', '0 filhos', ['editar.html', 'ver.html']],
] });

/* ---------- Ver conteúdo ---------- */
telas['ver.html'] = pagina({
  titulo: 'Ver conteúdo', menu: 'conteudo', migalhas: [M_INICIO, [SITE, 'conteudo.html'], ['Quartos', 'conteudo.html'], ['Suíte Master']],
  corpo: `<h1>Suíte Master</h1>
<p>${selo('privado')} Versão 7 · alterado há 2 dias por Ana</p>
<div class="acoes"><a class="btn btn-primary" href="editar-pagina.html">Editar<span class="visualmente-oculto"> Suíte Master</span></a>
<a class="btn btn-outline-secondary" href="inicio.html" target="_blank" rel="noopener">Ver no site ↗<span class="visualmente-oculto"> (abre em nova aba)</span></a></div>
<div data-escopo><div class="status" role="status" data-status></div>
<details class="divulga"><summary>Mais ações</summary><div class="divulga-corpo acoes">
<a class="btn btn-outline-secondary" href="criar.html">Criar dentro</a>
<button type="button" class="btn btn-outline-secondary" data-msg="Mover: no painel real você escolhe o destino aqui.">Mover</button>
<button type="button" class="btn btn-outline-secondary" data-msg="Copiado (simulação).">Copiar</button>
<button type="button" class="btn btn-outline-secondary" data-msg="Histórico: no painel real mostra as versões 1 a 7.">Histórico</button>
<button type="button" class="btn btn-outline-danger" data-msg="Mandado para a lixeira (simulação). Você acha em Conteúdo, filtro Lixeira.">Mandar para a lixeira</button></div></details></div>
<section class="cartao" aria-labelledby="t-campos"><h2 id="t-campos">O que está escrito</h2>
<dl class="campos-lista"><dt>Título</dt><dd>Suíte Master</dd><dt>Descrição</dt><dd>Suíte com varanda, vista para o jardim e café da manhã incluso.</dd><dt>Texto</dt><dd>A Suíte Master tem 38 m², cama king, varanda com rede e banheira.</dd><dt>Imagem de capa</dt><dd>capa-suite.jpg</dd><dt>Endereço</dt><dd>/quartos/suite-master</dd><dt>Sai do ar</dt><dd>Sem prazo</dd></dl></section>
<section class="cartao" aria-labelledby="t-env"><h2 id="t-env">Enviar para revisão</h2><p class="ajuda">Para quem escreve e não publica. Enviar não coloca no ar.</p>
<div data-escopo><button type="button" class="btn btn-outline-primary" data-msg="Enviado para revisão. Ainda não está no ar.">Enviar para revisão</button><div class="status" role="status" data-status></div></div></section>
<section aria-labelledby="t-filhos"><h2 id="t-filhos">Dentro desta página</h2><p>Nada dentro deste conteúdo ainda.</p><a class="btn btn-outline-secondary" href="criar.html">Criar dentro</a></section>`,
});

/* ---------- Criar ---------- */
telas['criar.html'] = pagina({
  titulo: 'Criar conteúdo', menu: 'conteudo', migalhas: [M_INICIO, [SITE, 'conteudo.html'], ['Quartos', 'conteudo.html'], ['Novo']],
  corpo: `<h1>O que você quer criar?</h1><p>O tipo decide quais campos aparecem. Você pode mudar o texto depois, mas não o tipo.</p>
<div class="grade-cartoes">
<a class="cartao cartao-link" href="editar-pagina.html"><strong>Página</strong><span>Texto fixo, como um quarto ou “Sobre nós”.</span></a>
<a class="cartao cartao-link" href="editar.html"><strong>Notícia</strong><span>Novidade com data e imagem.</span></a>
<a class="cartao cartao-link" href="editar-evento.html"><strong>Evento</strong><span>Tem data, local, vagas e pode sair do ar sozinho.</span></a></div>`,
});

/* ---------- Edição ---------- */
telas['editar-pagina.html'] = telaEdicao('pagina');
telas['editar.html'] = telaEdicao('noticia');
telas['editar-evento.html'] = telaEdicao('evento');

/* ---------- Busca ---------- */
const achados = [
  ['Suíte Master', 'Página', 'privado', 'Quartos › Suíte Master', 'ver.html'],
  ['Suíte Antiga', 'Página', 'lixeira', 'Quartos › Suíte Antiga', null],
  ['Quarto Standard', 'Página', 'publicado', 'Quartos › Quarto Standard', 'ver.html'],
  ['Quarto Família', 'Página', 'publicado', 'Quartos › Quarto Família', 'ver.html'],
  ['Feira de Inverno', 'Evento', 'agendado', 'Eventos › Feira de Inverno', 'ver.html'],
  ['Inverno no Sítio', 'Notícia', 'publicado', 'Novidades › Inverno no Sítio', 'editar.html'],
];
telas['buscar.html'] = pagina({
  titulo: 'Busca', menu: '', migalhas: [M_INICIO, ['Busca']],
  corpo: `<h1>Busca</h1>
<noscript><p class="aviso-bloco">Sem JavaScript, a busca mostra todos os conteúdos de exemplo.</p></noscript>
<p role="status" data-contagem>Todos os conteúdos (${achados.length}).</p>
<div data-escopo><div class="status" role="status" data-status></div>
<ul class="lista" data-resultados>${achados.map(([t, tipo, e, caminho, href], i) => `<li class="item" data-resultado>
<div><span class="item-titulo">${t}</span> ${selo(e)}</div><div class="item-meta">${tipo} · ${caminho}</div>
<div class="item-acoes">${e === 'lixeira'
    ? `<details class="saiba"><summary>Recuperar<span class="visualmente-oculto"> ${t}</span></summary><div class="aviso-bloco"><p>Recuperar “${t}” como Privado? Ele não vai ao ar sozinho.</p><button type="button" class="btn btn-primary" data-msg="Recuperado como Privado. Ele está em Quartos.">Confirmar recuperação</button></div></details>`
    : `<a class="btn btn-outline-primary" href="${href}">Abrir<span class="visualmente-oculto"> ${t}</span></a>`}</div></li>`).join('')}</ul></div>
<p data-sem-resultado hidden>Nenhum conteúdo com “<span data-termo></span>”. <a href="buscar.html">Limpar</a></p>`,
});

/* ---------- Configurações ---------- */
const nomesAbas = [['identidade', 'Identidade'], ['busca', 'Busca e compartilhamento'], ['contato', 'Contato'], ['rodape', 'Rodapé e métricas'], ['comportamento', 'Comportamento']];
const salvarAba = (nome) => `<div class="acoes"><button type="submit" class="btn btn-primary" data-salvar data-acao="painel">Salvar esta aba</button></div>`;
telas['configuracoes.html'] = pagina({
  titulo: 'Configurações do site', menu: 'site', migalhas: [M_INICIO, [SITE, 'conteudo.html'], ['Configurações']],
  corpo: `<h1>Configurações de ${SITE}</h1>
<p class="aviso-bloco">Busca ligada · Feeds RSS ligados · Analytics: não definido</p>
<form data-salvavel action="configuracoes.html" method="get" data-abas>
${resumoErros()}
${abas(nomesAbas, 'Partes das configurações')}
${painel('identidade', 'Identidade',
    campo({ id: 'nome-site', rotulo: 'Nome do site', valor: SITE, obrigatorio: true, max: 120 })
    + campo({ id: 'slogan', rotulo: 'Slogan', valor: 'Descanso no meio do verde', max: 200 })
    + selecao({ id: 'idioma', rotulo: 'Idioma', curto: true, opcoes: [['pt-BR', 'Português (Brasil)', true], ['en', 'English'], ['es-AR', 'Español (Argentina)']] })
    + imagemSimples('logo', 'Logo', 'logo-exemplo.png') + imagemSimples('icone', 'Ícone (favicon)', '')
    + detalhes({ titulo: 'Opções avançadas', corpo: imagemSimples('imagem-abertura', 'Imagem de abertura', '') })
    + salvarAba(), 'Identidade')}
${painel('busca', 'Busca e compartilhamento',
    area({ id: 'descricao-padrao', rotulo: 'Descrição padrão (SEO)', ajuda: 'Usada quando uma página não tem descrição própria.', max: 300, valor: 'Sítio de descanso com quartos, café colonial e eventos.' })
    + campo({ id: 'titulo-padrao', rotulo: 'Título padrão', max: 120, valor: SITE })
    + campo({ id: 'sufixo-titulo', rotulo: 'Sufixo do título', max: 60, valor: '· Site Exemplo', ajuda: 'Vem depois do título de cada página.' })
    + imagemSimples('imagem-padrao', 'Imagem padrão para compartilhamento', '')
    + marcar({ id: 'indexar-site', rotulo: 'Permitir indexação por buscadores', marcado: true })
    + `<div class="previa previa-busca"><p class="p-url">exemplo.com.br</p><p class="p-titulo" data-espelha="titulo-padrao" data-limite="60"></p><p class="p-desc" data-espelha="descricao-padrao" data-limite="160"></p></div>`
    + salvarAba(), 'Busca e compartilhamento')}
${painel('contato', 'Contato',
    campo({ id: 'email', rotulo: 'E-mail de contato', tipo: 'email', ajuda: 'Aparece no rodapé e no formulário de contato.', extra: { 'data-tipo': 'email', autocomplete: 'email', inputmode: 'email' } })
    + campo({ id: 'telefone', rotulo: 'Telefone', tipo: 'tel', ajuda: 'Com DDD. Ex.: (11) 3456-7890.', curto: true, extra: { 'data-tipo': 'telefone', autocomplete: 'tel' } })
    + area({ id: 'endereco-site', rotulo: 'Endereço', max: 300, linhas: 3 })
    + area({ id: 'redes', rotulo: 'Redes sociais', ajuda: 'Um endereço por linha, começando por https://.', extra: { 'data-tipo': 'urls' }, linhas: 3 })
    + salvarAba(), 'Contato')}
${painel('rodape', 'Rodapé e métricas',
    area({ id: 'rodape-texto', rotulo: 'Rodapé', ajuda: 'Texto simples com links.', linhas: 4, max: 1000, valor: '© Site Exemplo. Todos os direitos reservados.' })
    + campo({ id: 'analytics', rotulo: 'Identificador de analytics', ajuda: 'Só letras, números, hífen e sublinhado. Vazio = sem métricas.', extra: { 'data-tipo': 'analytics' }, curto: true })
    + salvarAba(), 'Rodapé e métricas')}
${painel('comportamento', 'Comportamento',
    marcar({ id: 'busca-ligada', rotulo: 'Busca ligada', marcado: true, ajuda: 'Mostra a caixa de busca no site.' })
    + campo({ id: 'itens-pagina', rotulo: 'Itens por página nas listagens', tipo: 'number', valor: '10', curto: true, ajuda: 'De 1 a 50.', extra: { 'data-tipo': 'inteiro', min: 1, max: 50, inputmode: 'numeric' } })
    + campo({ id: 'profundidade', rotulo: 'Profundidade do menu', tipo: 'number', valor: '0', curto: true, ajuda: 'De 0 a 5. 0 usa o valor global.', extra: { 'data-tipo': 'inteiro', min: 0, max: 5, inputmode: 'numeric' } })
    + detalhes({ titulo: 'Opções avançadas', corpo: marcar({ id: 'rss-ligado', rotulo: 'Feeds RSS ligados', marcado: true }) + campo({ id: 'rss-itens', rotulo: 'Itens por feed RSS', tipo: 'number', valor: '20', curto: true, ajuda: 'De 1 a 100.', extra: { 'data-tipo': 'inteiro', min: 1, max: 100, inputmode: 'numeric' } }) })
    + salvarAba(), 'Comportamento')}
<div class="barra-acoes"><p class="estado-salvamento" role="status">Sem alterações</p><div class="grupo-botoes"><a class="cancelar" href="inicio.html">Voltar ao Início</a></div></div>
</form>`,
});
function imagemSimples(id, rotulo, atual) {
  return `<div class="campo" data-seletor-imagem data-atual="${atual}"><label for="${id}">${rotulo}</label><p class="ajuda" id="${id}-ajuda">PNG, JPG ou WebP, até 2 MB.</p><div class="zona-imagem"><img class="miniatura" alt="" hidden><p class="nome-arquivo">${atual || 'Escolha uma imagem'}</p><input class="form-control" type="file" id="${id}" name="${id}" accept="image/png,image/jpeg,image/webp" aria-describedby="${id}-ajuda"><button type="button" class="btn btn-outline-secondary mt-2" data-tirar-imagem hidden>Tirar esta imagem</button></div></div>`;
}

/* ---------- Revisar ---------- */
telas['revisar.html'] = pagina({
  titulo: 'Revisar', menu: 'revisar', migalhas: [M_INICIO, ['Revisar']],
  corpo: `<h1>Revisar</h1>
<div data-abas>${abas([['pendentes', 'Pendentes de revisão'], ['envios', 'Meus envios']], 'Revisões')}
<div data-escopo>
${painel('pendentes', 'Pendentes de revisão', `<ul class="lista">
<li class="item"><div><span class="item-titulo">Café da manhã novo</span> ${selo('privado')}</div><div class="item-meta">Notícia · enviada por Carla há 1 dia</div><div class="item-acoes"><a class="btn btn-outline-secondary" href="editar.html">Ler<span class="visualmente-oculto"> Café da manhã novo</span></a><button type="button" class="btn btn-primary" data-msg="Aprovado e publicado.">Aprovar<span class="visualmente-oculto"> Café da manhã novo</span></button></div></li>
<li class="item"><div><span class="item-titulo">Pacote de Natal</span> ${selo('privado')}</div><div class="item-meta">Página · enviada por Bruno há 3 horas</div><div class="item-acoes"><a class="btn btn-outline-secondary" href="editar-pagina.html">Ler<span class="visualmente-oculto"> Pacote de Natal</span></a><button type="button" class="btn btn-primary" data-msg="Aprovado e publicado.">Aprovar<span class="visualmente-oculto"> Pacote de Natal</span></button></div></li></ul>
${detalhes({ titulo: 'Devolver um envio', corpo: area({ id: 'motivo', rotulo: 'Motivo da devolução', ajuda: 'A pessoa vai ver este texto no topo da edição.', linhas: 3, extra: { 'data-msg-exige': 'Escreva o motivo para devolver.' } }) + '<button type="button" class="btn btn-outline-danger" data-exige="motivo" data-msg="Devolvido com o motivo. A pessoa foi avisada.">Devolver</button>' })}`)}
${painel('envios', 'Meus envios', `<ul class="lista">
<li class="item"><div class="aviso-erro"><strong>Devolvido:</strong> faltou a foto do café. Mande outra e envie de novo.</div><div><span class="item-titulo">Cardápio de inverno</span> ${selo('privado')}</div><div class="item-acoes"><a class="btn btn-primary" href="editar.html">Corrigir<span class="visualmente-oculto"> Cardápio de inverno</span></a></div></li>
<li class="item"><div><span class="item-titulo">Dia dos Namorados</span> ${selo('privado')}</div><div class="item-meta">Enviado há 2 dias · aguardando revisão. Ainda não está no ar.</div><div class="item-acoes"><a class="btn btn-outline-secondary" href="ver.html">Abrir</a></div></li></ul>`)}
</div></div>`,
});

/* ---------- Mais ---------- */
telas['mais.html'] = pagina({
  titulo: 'Mais', menu: 'mais', migalhas: [M_INICIO, ['Mais']],
  corpo: `<h1>Mais</h1><p>Coisas que você usa de vez em quando.</p>
<div class="grade-cartoes">
<a class="cartao cartao-link" href="contas.html"><strong>Contas</strong><span>Quem pode entrar e o que cada pessoa pode fazer.</span></a>
<a class="cartao cartao-link" href="peles.html"><strong>Peles e moldes</strong><span>A aparência do site inteiro.</span></a>
<a class="cartao cartao-link" href="atualizacao.html"><strong>Atualização</strong><span>Versão atual e nova versão do PBL4.</span></a>
<a class="cartao cartao-link" href="copias.html"><strong>Cópias de segurança</strong><span>Criar e restaurar cópias.</span></a></div>`,
});
const migMais = (t) => [M_INICIO, ['Mais', 'mais.html'], [t]];

telas['contas.html'] = pagina({
  titulo: 'Contas', menu: 'mais', migalhas: migMais('Contas'),
  corpo: `<h1>Contas</h1>
<ul class="lista"><li class="item"><div><span class="item-titulo">Ana</span> ${selo('publicado')}</div><div class="item-meta">Administradora</div><div class="item-acoes"><a class="btn btn-outline-secondary" href="#conta-carla">Ver Ana</a></div></li>
<li class="item"><div><span class="item-titulo">Bruno</span> ${selo('publicado')}</div><div class="item-meta">Editor da seção Eventos</div><div class="item-acoes"><a class="btn btn-outline-secondary" href="#conta-carla">Ver Bruno</a></div></li>
<li class="item"><div><span class="item-titulo">Carla</span> ${selo('publicado')}</div><div class="item-meta">Autora externa</div><div class="item-acoes"><a class="btn btn-outline-primary" href="#conta-carla">Editar Carla</a></div></li></ul>
<section id="conta-carla" aria-labelledby="t-carla"><h2 id="t-carla">Conta de Carla</h2>
<form data-salvavel action="contas.html" method="get" data-abas>${resumoErros()}
${abas([['dados', 'Dados'], ['senha', 'Senha'], ['situacao', 'Situação'], ['papeis', 'Papéis']], 'Partes da conta')}
${painel('dados', 'Dados', campo({ id: 'conta-nome', rotulo: 'Nome', valor: 'Carla Souza', obrigatorio: true, max: 80 }) + campo({ id: 'conta-email', rotulo: 'E-mail', tipo: 'email', valor: 'carla@exemplo.com.br', extra: { 'data-tipo': 'email' } }) + '<div class="acoes"><button type="submit" class="btn btn-primary" data-salvar data-acao="painel">Salvar esta aba</button></div>')}
${painel('senha', 'Senha', campo({ id: 'conta-senha', rotulo: 'Nova senha', tipo: 'password', ajuda: 'Pelo menos 12 caracteres. Pode colar.', extra: { autocomplete: 'new-password' } }) + '<div class="acoes"><button type="submit" class="btn btn-primary" data-salvar data-acao="painel">Salvar esta aba</button></div>')}
${painel('situacao', 'Situação', marcar({ id: 'conta-ativa', rotulo: 'Conta ativa', marcado: true, ajuda: 'Desmarque para impedir a entrada sem apagar a conta.' }) + '<div class="acoes"><button type="submit" class="btn btn-primary" data-salvar data-acao="painel">Salvar esta aba</button></div>')}
${painel('papeis', 'Papéis', selecao({ id: 'conta-papel', rotulo: 'Papel', curto: true, opcoes: [['autor', 'Autora (envia para revisão)', true], ['editor', 'Editora'], ['admin', 'Administradora']] }) + '<div class="acoes"><button type="submit" class="btn btn-primary" data-salvar data-acao="painel">Salvar esta aba</button></div>')}
</form></section>
${detalhes({ titulo: 'Nova conta', corpo: `<form action="contas.html" method="get">${campo({ id: 'nova-nome', rotulo: 'Nome' })}${campo({ id: 'nova-email', rotulo: 'E-mail', tipo: 'email' })}<button type="submit" class="btn btn-primary">Criar conta</button></form>` })}`,
});

telas['peles.html'] = pagina({
  titulo: 'Peles e moldes', menu: 'mais', migalhas: migMais('Peles'),
  corpo: `<h1>Peles</h1><p class="aviso-bloco">Trocar a pele muda a aparência de todo o site desta parte. Você confirma com a senha.</p>
<div data-escopo><div class="status" role="status" data-status></div>
<form action="peles.html" method="get"><fieldset class="grupo"><legend class="rotulo">Pele do site</legend>
<div class="form-check"><input class="form-check-input" type="radio" name="pele" id="pele-padrao" value="padrao" checked><label class="form-check-label" for="pele-padrao">Padrão (em uso)</label></div>
<div class="form-check"><input class="form-check-input" type="radio" name="pele" id="pele-praia" value="praia"><label class="form-check-label" for="pele-praia">Praia</label></div>
<div class="form-check"><input class="form-check-input" type="radio" name="pele" id="pele-escura" value="escura"><label class="form-check-label" for="pele-escura">Escura</label></div></fieldset>
${confirmaSenha('senha-pele')}
<button type="submit" class="btn btn-primary" data-msg="Pele trocada." data-exige="senha-pele">Trocar pele</button></form></div>`,
});

telas['atualizacao.html'] = pagina({
  titulo: 'Atualização', menu: 'mais', migalhas: migMais('Atualização'),
  corpo: `<h1>Atualização</h1>
<dl class="campos-lista"><dt>Versão atual</dt><dd>4.1.0</dd><dt>Versão disponível</dt><dd>4.2.0 (fictícia)</dd></dl>
<div data-escopo><form action="atualizacao.html" method="get">${confirmaSenha('senha-atualizacao')}
<button type="submit" class="btn btn-primary" data-progresso="barra-atualizacao" data-alvo="status-atualizacao" data-exige="senha-atualizacao">Atualizar agora</button>
<progress id="barra-atualizacao" max="100" value="0" aria-label="Progresso da atualização" hidden></progress></form>
<div class="status" role="status" id="status-atualizacao" data-status></div></div>`,
});

telas['copias.html'] = pagina({
  titulo: 'Cópias de segurança', menu: 'mais', migalhas: migMais('Cópias de segurança'),
  corpo: `<h1>Cópias de segurança</h1>
<div data-escopo><div class="status" role="status" data-status></div>
<div class="acoes"><button type="button" class="btn btn-primary" data-espera="1200" data-espera-msg="Criando cópia…" data-msg="Cópia criada às {hora}.">Criar cópia</button></div>
<ul class="lista"><li class="item"><div><span class="item-titulo">Cópia de 14/11/2026 03:00</span></div><div class="item-meta">12 MB · automática</div></li>
<li class="item"><div><span class="item-titulo">Cópia de 07/11/2026 03:00</span></div><div class="item-meta">11 MB · automática</div></li></ul>
<section class="cartao" aria-labelledby="t-rest"><h2 id="t-rest">Restaurar a cópia de 14/11/2026</h2>
<div class="aviso-erro"><p><strong>O que será perdido:</strong></p><ul><li>Tudo o que foi escrito depois de 14/11/2026 03:00.</li><li>As contas criadas depois dessa data.</li></ul></div>
${marcar({ id: 'entendo', rotulo: 'Entendo que isto apaga o que veio depois da cópia' })}
${confirmaSenha('senha-copia')}
<button type="button" class="btn btn-outline-danger" data-exige-marcado="entendo" data-exige="senha-copia" data-msg="Cópia restaurada (simulação).">Restaurar esta cópia</button></section></div>`,
});

for (const [arq, html] of Object.entries(telas)) writeFileSync(join(destino, arq), html);
console.log(`${Object.keys(telas).length} telas geradas.`);

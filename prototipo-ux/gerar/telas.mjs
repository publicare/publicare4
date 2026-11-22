import { pagina } from './casca.mjs';
import { blocoLigavel, campo, secao, opcao, recolhido, caixa, selo, seloDe, cabecalho, botao, externo, abas, painel, lista, linha, linhaLink, esc, icone, imagem, seletorDeConteudo, opcoes, barra, resumoErros, ESTADOS } from './ui.mjs';
import { SECOES, ITENS, nomeSecao, OPCOES_CONTEUDO } from './dados.mjs';
import { formularioEdicao, formularioConfig } from './formularios.mjs';

const T = {}; // arquivo -> html

/* ---------- Roteiro ---------- */
T['index.html'] = pagina({
  titulo: 'Roteiro de teste', tela: 'Roteiro', ativo: null, trilha: [['Roteiro', 'index.html']],
  corpo: `${cabecalho({ titulo: 'Roteiro de teste', desc: 'Quatro tarefas curtas no novo painel. Faça uma de cada vez e diga onde travou.' })}
<section aria-labelledby="como-t"><h2 id="como-t" class="so-leitor">Como testar</h2>
<p class="suave">Tudo aqui é de mentira: nada é salvo de verdade e os dados são do “Site Exemplo”. Sempre que algo parecer confuso, toque em <strong>Isto ficou confuso</strong>, no alto da tela.</p></section>
<section class="secao-tela" aria-labelledby="tarefas-t"><h2 id="tarefas-t">As tarefas</h2>
<ol class="passos">
  <li><h3>Trocar o texto de um quarto e publicar</h3><p>Entre na Suíte Master, mude o texto, salve e publique.</p><a class="btn btn--principal" href="inicio.html">Começar pelo Início</a></li>
  <li><h3>Criar um evento com data de saída</h3><p>Crie um evento e faça ele sair do ar sozinho depois da data. Tente errar a data de propósito.</p><a class="btn btn--secundario" href="conteudo-eventos.html">Começar por Eventos</a></li>
  <li><h3>Configurar o contato do site</h3><p>Troque o e-mail e o telefone. Tente um e-mail incompleto e veja o aviso.</p><a class="btn btn--secundario" href="configuracoes.html">Começar por Site</a></li>
  <li><h3>Achar algo que foi apagado</h3><p>Use a busca (ou a tecla <kbd>/</kbd>) para achar a “Suíte Antiga” e trazê-la de volta.</p><a class="btn btn--secundario" href="buscar.html?q=Su%C3%ADte">Começar pela busca</a></li>
</ol></section>
<section class="secao-tela" id="anotacoes" aria-labelledby="notas-t"><h2 id="notas-t">Suas anotações</h2>
<p>O que você marcar como confuso fica só neste aparelho. Ao terminar, copie e mande para quem pediu o teste.</p>
<p id="resumo-vazio" class="suave">Nada anotado ainda.</p>
<div class="campo" data-so-com-notas hidden><div class="campo-cab"><label for="resumo-confusoes">Resumo para copiar</label></div><textarea id="resumo-confusoes" class="resumo-texto" readonly rows="6"></textarea></div>
<p class="barra-botoes" data-so-com-notas hidden><button type="button" class="btn btn--secundario" id="copiar-resumo">Copiar resumo</button><button type="button" class="btn btn--discreto" id="apagar-confusoes">Apagar anotações</button></p>
<p class="pequeno" id="resumo-msg" role="status"></p></section>
<section class="secao-tela" aria-labelledby="fora-t"><h2 id="fora-t">Bom saber</h2>
<ul class="lista">
  <li><div class="item"><div class="item-texto"><span class="item-titulo">Fora desta rodada</span><span class="item-meta">Domínios, redirecionamentos, tipos de conteúdo e tarefas agendadas.</span></div></div></li>
  <li><a class="item" href="guia.html"><div class="item-texto"><span class="item-titulo">Guia visual</span><span class="item-meta">Cores, letras, espaços e componentes usados nas telas.</span></div><span class="seta" aria-hidden="true">›</span></a></li>
</ul></section>`,
});

/* ---------- Início ---------- */
T['inicio.html'] = pagina({
  titulo: 'Início', tela: 'Início', ativo: 'inicio', trilha: [['Início', 'inicio.html']],
  corpo: `${cabecalho({ titulo: 'Início', desc: 'Olá, Ana. Você é administradora do Site Exemplo.', acao: botao('Criar conteúdo', 'criar.html', 'principal') })}
<section aria-labelledby="fazer-t"><h2 id="fazer-t">Para fazer</h2>
${lista([
    `<li>${linhaLink({ titulo: '2 envios esperam a sua revisão', href: 'revisar.html', meta: 'Nova vista da varanda e Cardápio de inverno' })}</li>`,
    `<li>${linhaLink({ titulo: 'Continuar: Suíte Master', href: 'editar-pagina.html', meta: 'Você mexeu nela há 2 dias. Ainda está privada.' })}</li>`,
  ], 'lista--link')}</section>
<section class="secao-tela" aria-label="Seções do site">${blocoLigavel({ id: 'bloco-secoes', titulo: 'Seções do site', ajuda: 'Atalho para cada parte do site.', corpo: lista(SECOES.map((s) => `<li>${linhaLink({ titulo: s.nome, href: s.arquivo, meta: `${ITENS.filter((i) => i.secao === s.id && i.estado !== 'lixeira').length} itens · ${s.desc}` })}</li>`), 'lista--link') })}
${blocoLigavel({ id: 'bloco-atalhos', titulo: 'Atalhos', ajuda: 'Os dois lugares que você mais abre fora do conteúdo.', corpo: `<p><a href="configuracoes.html">Configurações do site</a> · <a href="site-exemplo.html" target="_blank" rel="noopener">Ver o site ${icone.externo}<span class="so-leitor"> (abre em outra aba)</span></a></p>` })}</section>`,
});

/* ---------- Conteúdo ---------- */
T['conteudo.html'] = pagina({
  titulo: 'Conteúdo', tela: 'Conteúdo', ativo: 'conteudo', trilha: [['Início', 'inicio.html'], ['Conteúdo', 'conteudo.html']],
  corpo: `${cabecalho({ titulo: 'Conteúdo', desc: 'Tudo o que está no site, por seção.', acao: botao('Criar conteúdo', 'criar.html', 'principal') })}
<section aria-labelledby="sec-t"><h2 id="sec-t" class="so-leitor">Seções</h2>
${lista(SECOES.map((s) => `<li>${linhaLink({ titulo: s.nome, href: s.arquivo, meta: s.desc, fim: `<span class="item-meta">${ITENS.filter((i) => i.secao === s.id && i.estado !== 'lixeira').length} itens</span>` })}</li>`), 'lista--link')}</section>
<section class="secao-tela" aria-label="Mexidos por último">${blocoLigavel({ id: 'bloco-recentes', titulo: 'Mexidos por último', ajuda: 'Os cinco conteúdos que mudaram mais recentemente.', corpo: `${lista(ITENS.filter((i) => i.estado !== 'lixeira').slice(0, 5).map((i) => `<li>${linha({ titulo: i.titulo, href: 'ver.html', meta: `${nomeSecao(i.secao)} · ${i.tipo} · ${i.quando}`, fim: seloDe(i.estado) })}</li>`))}
<p class="pequeno suave">Procurando algo apagado? Abra uma seção e escolha “Na lixeira”, ou use a busca.</p>` })}</section>`,
});

const linhaItem = (i) => {
  const lix = i.estado === 'lixeira';
  const id = `rec-${i.titulo.toLowerCase().replace(/[^a-z]+/g, '-')}`;
  const fim = `${seloDe(i.estado)}${lix ? `<button type="button" class="btn btn--secundario btn--pequeno" data-revela="${id}" aria-expanded="false" aria-controls="${id}">Recuperar</button>` : `<a class="btn btn--discreto btn--pequeno" href="${i.editar}">Editar<span class="so-leitor"> ${esc(i.titulo)}</span></a>`}`;
  const depois = lix ? `<p class="item-meta" data-msg role="status" hidden></p><div class="confirmacao" id="${id}" data-confirmacao hidden><p>Recuperar “${esc(i.titulo)}”? Ela volta como <strong>Privada</strong>: ninguém vê até você publicar.</p><p><button type="button" class="btn btn--principal" data-efeito="recuperar">Recuperar como privada</button> <button type="button" class="btn btn--discreto" data-fecha="${id}">Cancelar</button></p></div>` : '';
  return `<li data-item data-linha data-titulo="${esc(i.titulo)}" data-estado="${i.estado}">${linha({ titulo: i.titulo, href: lix ? '' : 'ver.html', meta: `${esc(nomeSecao(i.secao))} · ${i.tipo} · ${i.quando}`, fim, depois })}</li>`;
};
const filtros = `<div class="filtro-busca"><div class="filtros" data-filtros role="group" aria-label="Filtrar por estado">
  <button type="button" data-estado="todos" aria-pressed="true">Todos</button><button type="button" data-estado="privado" aria-pressed="false">Privados</button><button type="button" data-estado="publicado" aria-pressed="false">Publicados</button><button type="button" data-estado="agendado" aria-pressed="false">Agendados</button><button type="button" data-estado="vencido" aria-pressed="false">Vencidos</button><button type="button" data-estado="lixeira" aria-pressed="false">Na lixeira</button></div>
  <div class="campo campo-curto"><label class="so-leitor" for="filtro-texto">Filtrar pelo título</label><input type="search" id="filtro-texto" data-filtro-texto data-ignorar placeholder="Filtrar pelo título" autocomplete="off"></div></div>`;

for (const s of SECOES) {
  const itens = ITENS.filter((i) => i.secao === s.id);
  T[s.arquivo] = pagina({
    titulo: s.nome, tela: `Conteúdo › ${s.nome}`, ativo: 'conteudo', trilha: [['Início', 'inicio.html'], ['Conteúdo', 'conteudo.html'], [s.nome, s.arquivo]],
    corpo: `${cabecalho({ titulo: s.nome, desc: s.desc, acao: botao(`Criar em ${s.nome}`, s.novo, 'principal') })}
${filtros}
<p class="resultado-contagem" data-contagem role="status"></p>
${lista(itens.map(linhaItem))}
<div class="vazio" data-vazio hidden>Nada com esse filtro. Escolha “Todos” para ver tudo de novo.</div>`,
  });
}

/* ---------- Ver ---------- */
T['ver.html'] = pagina({
  titulo: 'Suíte Master', tela: 'Ver conteúdo', ativo: 'conteudo', trilha: [['Início', 'inicio.html'], ['Conteúdo', 'conteudo.html'], ['Quartos', 'conteudo-quartos.html'], ['Suíte Master', 'ver.html']],
  corpo: `${cabecalho({ titulo: 'Suíte Master', selo: seloDe('privado'), desc: 'Página · versão 7 · alterada há 2 dias por Ana', acao: `${externo('Ver no site', 'site-exemplo.html')}${botao('Editar', 'editar-pagina.html', 'principal')}` })}
<section aria-labelledby="dados-t"><h2 id="dados-t" class="so-leitor">Dados</h2>
<dl class="dados"><dt>Título</dt><dd>Suíte Master</dd><dt>Texto</dt><dd>A Suíte Master tem 42 m², cama king e varanda com vista para o jardim. Café da manhã incluso.</dd><dt>Endereço</dt><dd class="mono">/quartos/suite-master</dd><dt>Tags</dt><dd>hotel, suíte</dd><dt>Publicação</dt><dd>Ainda não publicada</dd></dl></section>
<section class="secao-tela" aria-label="Dentro desta página">${blocoLigavel({ id: 'bloco-dentro', titulo: 'Dentro desta página', ajuda: 'Conteúdos que ficam dentro desta página.', corpo: '<p>Nada dentro desta página ainda.</p><p><a href="criar.html">Criar conteúdo aqui dentro</a></p>' })}</section>
<section class="secao-tela" aria-labelledby="rev-t" data-linha><h2 id="rev-t">Revisão</h2><p>Quem não publica diretamente envia para a revisão de alguém. <span class="selo" data-estado-revisao>Fora da revisão</span></p>
<p><button type="button" class="btn btn--secundario" data-efeito="enviar">Enviar para revisão</button></p><p class="item-meta" data-msg role="status" hidden></p></section>`,
});
T['site-exemplo.html'] = pagina({
  titulo: 'Site Exemplo (simulação)', tela: 'Site Exemplo (simulação)', ativo: null, trilha: [['Início', 'inicio.html'], ['Site Exemplo (simulação)', 'site-exemplo.html']],
  corpo: `${caixa({ tipo: '', icone: 'aviso', corpo: '<p><strong>Isto simula o site público.</strong> O protótipo não publica nada de verdade. Feche esta aba para voltar ao painel.</p>' })}
<article><h1>Suíte Master</h1><p class="suave">Site Exemplo · Quartos</p><p>A Suíte Master tem 42 m², cama king e varanda com vista para o jardim. Café da manhã incluso.</p></article>`,
});

/* ---------- Criar ---------- */
T['criar.html'] = pagina({
  titulo: 'Criar conteúdo', tela: 'Criar conteúdo', ativo: 'conteudo', trilha: [['Início', 'inicio.html'], ['Conteúdo', 'conteudo.html'], ['Criar conteúdo', 'criar.html']],
  corpo: `${cabecalho({ titulo: 'Criar conteúdo', desc: 'O que você quer criar? O tipo define quais campos aparecem.' })}
${lista([
    `<li>${linhaLink({ titulo: 'Página', href: 'novo-pagina.html', meta: 'Texto que fica no site por muito tempo, como um quarto ou “Sobre nós”.' })}</li>`,
    `<li>${linhaLink({ titulo: 'Notícia', href: 'novo-noticia.html', meta: 'Uma novidade, com imagem e data, que entra na lista de novidades.' })}</li>`,
    `<li>${linhaLink({ titulo: 'Evento', href: 'novo-evento.html', meta: 'Algo com dia, hora e local. Pode sair do ar sozinho depois.' })}</li>`,
  ], 'lista--link')}`,
});

/* ---------- Edição ---------- */
const trilhaEdicao = (secao, titulo, ultimo) => [['Início', 'inicio.html'], ['Conteúdo', 'conteudo.html'], [nomeSecao(secao), SECOES.find((s) => s.id === secao).arquivo], ...(titulo ? [[titulo, 'ver.html']] : []), [ultimo, '#']];
const edicao = (arquivo, { tipo, novo, titulo, secao, estadoConteudo = 'privado', versao, rotulo }) => {
  T[arquivo] = pagina({
    titulo: novo ? rotulo : `Editar ${titulo}`, tela: novo ? rotulo : `Editar ${titulo}`, ativo: 'conteudo', comBarra: true, largura: 'form',
    trilha: trilhaEdicao(secao, novo ? null : titulo, novo ? rotulo : 'Editar'),
    corpo: `${cabecalho({ titulo: novo ? rotulo : titulo, selo: seloDe(estadoConteudo).replace('class="selo', 'data-selo-estado class="selo'), desc: novo ? 'Preencha o essencial. O resto fica nas outras abas.' : `${{ pagina: 'Página', noticia: 'Notícia', evento: 'Evento' }[tipo]} · versão ${versao}` })}
${formularioEdicao({ tipo, novo, titulo, estadoConteudo, versao })}`,
  });
};
edicao('editar-pagina.html', { tipo: 'pagina', titulo: 'Suíte Master', secao: 'quartos', versao: 7 });
edicao('editar-noticia.html', { tipo: 'noticia', titulo: 'Nova vista da varanda', secao: 'novidades', versao: 3 });
edicao('editar-evento.html', { tipo: 'evento', titulo: 'Noite de jazz', secao: 'eventos', versao: 4, estadoConteudo: 'publicado' });
edicao('novo-pagina.html', { tipo: 'pagina', novo: true, rotulo: 'Nova página', secao: 'quartos' });
edicao('novo-noticia.html', { tipo: 'noticia', novo: true, rotulo: 'Nova notícia', secao: 'novidades' });
edicao('novo-evento.html', { tipo: 'evento', novo: true, rotulo: 'Novo evento', secao: 'eventos' });

/* ---------- Busca ---------- */
T['buscar.html'] = pagina({
  titulo: 'Busca', tela: 'Busca', ativo: 'conteudo', trilha: [['Início', 'inicio.html'], ['Busca', 'buscar.html']],
  corpo: `${cabecalho({ titulo: 'Busca', desc: 'Acha títulos de qualquer seção, inclusive o que está na lixeira.' })}
<form role="search" action="buscar.html" method="get" class="filtro-busca"><div class="campo"><div class="campo-cab"><label for="filtro-texto">O que você procura?</label></div><input type="search" id="filtro-texto" name="q" data-filtro-texto data-ignorar autocomplete="off"></div></form>
<div class="filtros" data-filtros role="group" aria-label="Filtrar por estado"><button type="button" data-estado="todos" aria-pressed="true">Todos</button><button type="button" data-estado="privado" aria-pressed="false">Privados</button><button type="button" data-estado="publicado" aria-pressed="false">Publicados</button><button type="button" data-estado="lixeira" aria-pressed="false">Na lixeira</button></div>
<p class="resultado-contagem" data-contagem role="status"></p>
${lista(ITENS.map(linhaItem))}
<div class="vazio" data-vazio hidden>Nenhum conteúdo com esse nome. Tente outra palavra ou confira a escrita.</div>`,
});

/* ---------- Site (configurações) ---------- */
T['configuracoes.html'] = pagina({
  titulo: 'Configurações do site', tela: 'Site › Configurações', ativo: 'site', comBarra: true, largura: 'form',
  trilha: [['Início', 'inicio.html'], ['Site', 'configuracoes.html']],
  corpo: `${cabecalho({ titulo: 'Configurações do site', desc: 'Busca ligada · Feeds RSS ligados · Métricas não definidas' })}
${formularioConfig()}`,
});

/* ---------- Revisar ---------- */
const pend = (titulo, meta, id) => `<li data-linha>${linha({ titulo, href: 'ver.html', meta, fim: `${selo('Aguardando', 'aviso')}<button type="button" class="btn btn--secundario btn--pequeno" data-efeito="aprovar">Aprovar<span class="so-leitor"> ${esc(titulo)}</span></button><button type="button" class="btn btn--discreto btn--pequeno" data-revela="dev-${id}" aria-expanded="false" aria-controls="dev-${id}">Devolver<span class="so-leitor"> ${esc(titulo)}</span></button>`, depois: `<p class="item-meta" data-msg role="status" hidden></p>` })}
<div class="confirmacao" id="dev-${id}" data-confirmacao hidden>${campo({ id: `motivo-${id}`, rotulo: 'Por que está devolvendo?', area: true, linhas: 3, ajuda: 'Quem enviou vê este texto no alto da edição.' }).replace('<textarea', '<textarea data-exigir="Escreva o motivo para quem enviou."')}<p><button type="button" class="btn btn--principal" data-efeito="devolver">Devolver com este motivo</button> <button type="button" class="btn btn--discreto" data-fecha="dev-${id}">Cancelar</button></p></div></li>`;
T['revisar.html'] = pagina({
  titulo: 'Revisar', tela: 'Revisar', ativo: 'revisar', trilha: [['Início', 'inicio.html'], ['Revisar', 'revisar.html']],
  corpo: `${cabecalho({ titulo: 'Revisar', desc: 'O que alguém enviou para você aprovar antes de ir ao ar.' })}
${abas([['pendentes', 'Para revisar'], ['envios', 'Meus envios']])}
${painel('pendentes', 'Para revisar', `<h2 class="so-leitor">Para revisar</h2>${lista([pend('Nova vista da varanda', 'Notícia · enviada por Carla há 3 horas', 'varanda'), pend('Cardápio de inverno', 'Página · enviada por Bruno ontem', 'cardapio')])}`)}
${painel('envios', 'Meus envios', `<h2 class="so-leitor">Meus envios</h2>${lista([
    `<li>${linha({ titulo: 'Promoção de inverno', href: 'ver.html', meta: 'Notícia · enviada há 2 dias', fim: selo('Em revisão', 'aviso') })}</li>`,
    `<li>${linha({ titulo: 'Horário da recepção', href: 'editar-pagina.html', meta: 'Página · enviada há 4 dias', fim: `${selo('Devolvido', 'erro')}<a class="btn btn--secundario btn--pequeno" href="editar-pagina.html">Corrigir</a>`, depois: `<div class="caixa caixa--aviso">${icone.aviso}<div><p><strong>Motivo da devolução, por Bruno:</strong></p><p>O horário de sábado está errado. Confira com a recepção e envie de novo.</p></div></div>` })}</li>`,
  ])}<p class="pequeno suave">Enviar para revisão não publica. O conteúdo só vai ao ar quando alguém aprova.</p>`)}`,
});

/* ---------- Mais ---------- */
T['mais.html'] = pagina({
  titulo: 'Mais', tela: 'Mais', ativo: 'mais', trilha: [['Início', 'inicio.html'], ['Mais', 'mais.html']],
  corpo: `${cabecalho({ titulo: 'Mais', desc: 'Pessoas, aparência e cuidados com o painel. Coisas que você mexe de vez em quando.' })}
${lista([
    ['Contas', 'contas.html', 'Quem entra no painel e o que cada pessoa pode fazer.'],
    ['Aparência do site', 'peles.html', 'Trocar a pele (o visual) do site.'],
    ['Atualização', 'atualizacao.html', 'Ver se há versão nova do painel e atualizar.'],
    ['Cópias de segurança', 'copias.html', 'Guardar o site hoje e voltar a um dia anterior se precisar.'],
  ].map(([t, h, m]) => `<li>${linhaLink({ titulo: t, href: h, meta: m })}</li>`), 'lista--link')}`,
});

const trilhaMais = (t, h) => [['Início', 'inicio.html'], ['Mais', 'mais.html'], [t, h]];
T['contas.html'] = pagina({
  titulo: 'Contas', tela: 'Mais › Contas', ativo: 'mais', trilha: trilhaMais('Contas', 'contas.html'),
  corpo: `${cabecalho({ titulo: 'Contas', desc: 'Quem pode entrar no painel.', acao: botao('Nova conta', 'conta.html', 'principal') })}
${lista([['Ana', 'Administradora · todo o site', 'ativa', 'Entrou hoje'], ['Bruno', 'Editor · só Eventos', 'ativa', 'Entrou ontem'], ['Carla', 'Autora · só Novidades', 'ativa', 'Entrou há 5 dias'], ['Dário', 'Técnico · todo o site', 'pausada', 'Nunca entrou']].map(([n, p, e, q]) => `<li>${linha({ titulo: n, meta: `${p} · ${q}`, fim: e === 'ativa' ? selo('Ativa', 'ok') : selo('Pausada') })}</li>`))}`,
});
T['conta.html'] = pagina({
  titulo: 'Nova conta', tela: 'Mais › Contas › Nova conta', ativo: 'mais', comBarra: true, largura: 'form', trilha: [['Início', 'inicio.html'], ['Mais', 'mais.html'], ['Contas', 'contas.html'], ['Nova conta', 'conta.html']],
  corpo: `${cabecalho({ titulo: 'Nova conta', desc: 'A pessoa recebe um convite por e-mail.' })}
<form class="formulario" data-edicao="geral" action="#" novalidate>${resumoErros()}
${secao({ titulo: 'Quem é', corpo: campo({ id: 'nome-conta', rotulo: 'Nome', obrigatorio: true, max: 80, auto: 'off' }) + campo({ id: 'email-conta', rotulo: 'E-mail', tipo: 'email', regra: 'email', obrigatorio: true, ajuda: 'O convite vai para este endereço.' }) })}
${secao({ titulo: 'O que pode fazer', desc: 'Dá para mudar depois.', corpo: campo({ id: 'papel', rotulo: 'Papel', opcoes: [['editor', 'Editor: cria e publica'], ['autor', 'Autora: cria e envia para revisão'], ['admin', 'Administradora: tudo']], valor: 'autor', ajuda: 'Autoras só publicam depois que alguém aprova.' })
      + opcao({ id: 'so-secao', titulo: 'Limitar a uma seção do site', ligado: false, ajuda: 'A pessoa só vê e edita o que está dentro da seção escolhida.', campos: seletorDeConteudo({ id: 'secao-conta', rotulo: 'Seção', ajuda: 'Digite duas letras ou mais para buscar.', opcoes: OPCOES_CONTEUDO }) })
      + opcao({ id: 'senha-agora', titulo: 'Definir a senha agora', ligado: false, ajuda: 'Sem isto, a pessoa escolhe a senha pelo convite.', campos: campo({ id: 'senha-conta', rotulo: 'Senha provisória', tipo: 'password', auto: 'new-password', max: 64, ajuda: 'Simulação: não use uma senha real. Dá para colar.' }) }) })}
${barra({ estado: 'alterado', texto: 'Ainda não criada', botoes: '<a class="btn btn--discreto" href="contas.html">Voltar</a><button type="button" class="btn btn--principal" data-acao="salvar">Criar conta</button>' })}</form>`,
});

const peleLinha = (nome, desc, emUso, id) => `<li data-linha${emUso ? ' data-em-uso' : ''}>${linha({ titulo: nome, meta: desc, fim: emUso ? selo('Em uso', 'ok') : `<button type="button" class="btn btn--secundario btn--pequeno" data-revela="pele-${id}" aria-expanded="false" aria-controls="pele-${id}">Usar esta pele<span class="so-leitor">: ${esc(nome)}</span></button>`, depois: `<p class="item-meta" data-msg role="status" hidden></p>` })}
<div class="confirmacao" id="pele-${id}" data-confirmacao hidden><p>Isto muda a aparência de todo o site. Você pode voltar à anterior quando quiser.</p>${campo({ id: `senha-pele-${id}`, rotulo: 'Sua senha', tipo: 'password', auto: 'current-password', ajuda: 'Simulação: escreva qualquer coisa. Você tem 10 minutos depois disso.' }).replace('<input', '<input data-exigir="Confirme com a sua senha."')}<p><button type="button" class="btn btn--principal" data-efeito="pele">Trocar a pele</button> <button type="button" class="btn btn--discreto" data-fecha="pele-${id}">Cancelar</button></p></div></li>`;
T['peles.html'] = pagina({
  titulo: 'Aparência do site', tela: 'Mais › Aparência do site', ativo: 'mais', trilha: trilhaMais('Aparência do site', 'peles.html'),
  corpo: `${cabecalho({ titulo: 'Aparência do site', desc: 'A pele define cores e fontes. O conteúdo não muda.' })}
${lista([peleLinha('Clássica', 'Fundo claro, letras escuras, discreta.', true, 'classica'), peleLinha('Verão', 'Tons quentes, bom para pousadas.', false, 'verao'), peleLinha('Escura', 'Fundo escuro, letras claras.', false, 'escura')])}
<p class="pequeno suave">Para usar outro visual só numa página, abra a página e vá em Avançado.</p>`,
});
T['atualizacao.html'] = pagina({
  titulo: 'Atualização', tela: 'Mais › Atualização', ativo: 'mais', largura: 'form', trilha: trilhaMais('Atualização', 'atualizacao.html'),
  corpo: `${cabecalho({ titulo: 'Atualização', desc: 'Mantenha o painel em dia.' })}
<dl class="dados"><dt>Instalada</dt><dd>Versão 4.0.2</dd><dt>Disponível</dt><dd>Versão 4.1.0 (números de exemplo)</dd></dl>
<section class="secao-tela" aria-labelledby="atz-t"><h2 id="atz-t">Atualizar agora</h2><p>Antes de atualizar, o painel guarda uma cópia de segurança. O site continua no ar.</p>
<div class="secao-corpo">${campo({ id: 'senha-atualizar', rotulo: 'Sua senha', tipo: 'password', auto: 'current-password', curto: false, medio: true, ajuda: 'Simulação: escreva qualquer coisa.' })}
<p><button type="button" class="btn btn--principal" data-atualizar>Atualizar para 4.1.0</button></p>
<progress max="100" value="0" aria-label="Andamento da atualização" hidden></progress><p id="atualizar-msg" role="status" class="suave"></p></div></section>`,
});
const copia = (n, meta, id) => `<li data-linha>${linha({ titulo: n, meta, fim: `<button type="button" class="btn btn--secundario btn--pequeno" data-revela="rest-${id}" aria-expanded="false" aria-controls="rest-${id}">Restaurar<span class="so-leitor"> ${esc(n)}</span></button>`, depois: '<p class="item-meta" data-msg role="status" hidden></p>' })}
<div class="confirmacao" id="rest-${id}" data-confirmacao hidden><p><strong>O que será perdido</strong></p><ul><li>Tudo o que mudou depois desta cópia: 14 páginas editadas e 2 contas novas.</li><li>Não dá para desfazer.</li></ul>
${opcoes({ rotulo: 'Confirmação', itens: [{ id: `ciente-${id}`, texto: 'Entendi que isso não pode ser desfeito' }] }).replace(`id="ciente-${id}"`, `id="ciente-${id}" data-exigir="Marque para confirmar."`)}<p class="erro" id="ciente-${id}-erro"></p>
${campo({ id: `senha-rest-${id}`, rotulo: 'Sua senha', tipo: 'password', auto: 'current-password', ajuda: 'Simulação: escreva qualquer coisa.' }).replace('<input', '<input data-exigir="Confirme com a sua senha."')}<p><button type="button" class="btn btn--perigo" data-efeito="restaurar">Restaurar esta cópia</button> <button type="button" class="btn btn--discreto" data-fecha="rest-${id}">Cancelar</button></p></div></li>`;
T['copias.html'] = pagina({
  titulo: 'Cópias de segurança', tela: 'Mais › Cópias de segurança', ativo: 'mais', trilha: trilhaMais('Cópias de segurança', 'copias.html'),
  corpo: `${cabecalho({ titulo: 'Cópias de segurança', desc: 'Uma foto do site num dia. Dá para voltar a ela.', acao: '<button type="button" class="btn btn--principal" data-nova-copia>Criar cópia agora</button>' })}
<ul class="lista" id="lista-copias">${copia('Cópia automática de ontem', '03:00 · 38 MB · feita pelo painel', 'ontem')}${copia('Cópia de 12/11', '14:10 · 37 MB · feita por Ana', 'doze')}</ul>
<div class="vazio" data-vazio hidden>Nenhuma cópia ainda. Crie a primeira.</div>`,
});

export default T;

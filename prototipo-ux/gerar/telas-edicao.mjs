import { pagina, selo, campo, area, selecao, marcar, opcoesRadio, detalhes, imagem, tags, seletorConteudo, previas, abas, painel, barraAcoes, resumoErros, SITE } from './componentes.mjs';

const VARIANTES = {
  pagina: {
    arquivo: 'editar-pagina.html', titulo: 'Editar página', nome: 'Suíte Master', slug: 'suite-master', estado: 'privado', versao: 7, tipoNome: 'Página',
    valorTitulo: 'Suíte Master', valorDesc: 'Suíte com varanda, vista para o jardim e café da manhã incluso.', tagsValor: 'hospedagem, família', secao: 'Quartos', secaoHref: 'conteudo.html', cancelar: 'ver.html',
  },
  noticia: {
    arquivo: 'editar.html', titulo: 'Editar notícia', nome: 'Inverno no Sítio', slug: 'inverno-no-sitio', estado: 'publicado', versao: 3, tipoNome: 'Notícia',
    valorTitulo: 'Inverno no Sítio', valorDesc: 'Programação de lareira, fondue e passeios para a temporada.', tagsValor: 'inverno, gastronomia', secao: 'Novidades', secaoHref: 'conteudo-novidades.html', cancelar: 'conteudo-novidades.html',
  },
  evento: {
    arquivo: 'editar-evento.html', titulo: 'Novo evento', nome: 'Novo evento', slug: '', estado: 'privado', versao: 0, tipoNome: 'Evento',
    valorTitulo: '', valorDesc: '', tagsValor: '', secao: 'Eventos', secaoHref: 'conteudo-eventos.html', cancelar: 'conteudo-eventos.html',
  },
};

function camposDoTipo(tipo, v) {
  if (tipo === 'pagina') {
    return area({ id: 'texto', rotulo: 'Texto', ajuda: 'Pode usar negrito, listas e links. O texto aparece na página do site.', linhas: 8, valor: 'A Suíte Master tem 38 m², cama king, varanda com rede e banheira. Café da manhã servido no quarto sob pedido.', max: 4000 })
      + imagem({ id: 'imagem-capa', rotulo: 'Imagem de capa', atual: 'capa-suite.jpg' })
      + seletorConteudo({ id: 'pagina-relacionada', rotulo: 'Página relacionada', ajuda: 'Digite 2 letras do nome para buscar. Aparece como “Veja também”.', atual: '4' });
  }
  if (tipo === 'noticia') {
    return area({ id: 'texto', rotulo: 'Texto da notícia', ajuda: 'Texto simples com negrito, listas e links.', linhas: 8, valor: 'Neste inverno, o sítio abre a lareira todas as noites a partir das 18h. Reserve pelo telefone ou pelo site.', max: 4000 })
      + imagem({ id: 'imagem', rotulo: 'Imagem', atual: 'lareira.webp' });
  }
  return campo({ id: 'data-evento', rotulo: 'Data e hora do evento', tipo: 'datetime-local', ajuda: 'Horário de Brasília.', curto: true, obrigatorio: true })
    + campo({ id: 'local', rotulo: 'Local', ajuda: 'Ex.: Salão principal.', max: 120 })
    + campo({ id: 'vagas', rotulo: 'Vagas', tipo: 'number', ajuda: 'Deixe vazio se não houver limite.', curto: true, extra: { min: 1, max: 500, inputmode: 'numeric', 'data-tipo': 'inteiro' } })
    + campo({ id: 'link-inscricao', rotulo: 'Link de inscrição', tipo: 'url', ajuda: 'Endereço completo, começando por https://.', extra: { 'data-tipo': 'url', inputmode: 'url' } })
    + area({ id: 'texto', rotulo: 'Texto do evento', ajuda: 'Programação, valores e o que levar.', linhas: 6, max: 4000 })
    + seletorConteudo({ id: 'pagina-relacionada', rotulo: 'Página com mais informações', ajuda: 'Digite 2 letras do nome para buscar.' });
}

export function telaEdicao(tipo) {
  const v = VARIANTES[tipo];
  const imgSeo = 'imagem-compartilhar';
  const novo = tipo === 'evento';
  const estadoTexto = novo ? 'Ainda não salvo' : 'Sem alterações · versão ' + v.versao;
  const painelConteudo = painel('conteudo', 'Conteúdo',
    `<div class="cartao"><h3 class="h5">Identificação</h3>`
    + campo({ id: 'titulo', rotulo: 'Título', ajuda: 'Aparece na página e no menu.', valor: v.valorTitulo, obrigatorio: true, max: 120 })
    + area({ id: 'descricao', rotulo: 'Descrição', ajuda: 'Uma ou duas frases sobre o conteúdo.', valor: v.valorDesc, max: 300 })
    + `<div class="campo"><label for="endereco">Endereço (URL)</label><p class="ajuda" id="endereco-ajuda">Só letras minúsculas sem acento, números e hífen.</p>
<div class="input-group"><span class="input-group-text" id="endereco-prefixo">/${v.secao.toLowerCase()}/</span><input class="form-control" id="endereco" name="endereco" value="${v.slug}" data-tipo="slug" aria-describedby="endereco-ajuda endereco-prefixo"></div></div>`
    + tags({ id: 'tags', rotulo: 'Tags', valor: v.tagsValor })
    + `</div><div class="cartao"><h3 class="h5">${v.tipoNome}</h3>${camposDoTipo(tipo, v)}</div>`
    + detalhes({
      titulo: 'Busca e compartilhamento (SEO)',
      corpo: campo({ id: 'seo-titulo', rotulo: 'Título para buscadores', ajuda: 'Até 60 caracteres. Vazio usa o título.', max: 60 })
        + area({ id: 'seo-descricao', rotulo: 'Descrição para buscadores', ajuda: 'Até 160 caracteres. Vazio usa a descrição.', max: 160, linhas: 3 })
        + imagem({ id: imgSeo, rotulo: 'Imagem para compartilhamento' })
        + marcar({ id: 'indexar', rotulo: 'Permitir indexação por buscadores', marcado: true, ajuda: 'Desmarque para esconder esta página do Google.' })
        + `<h4 class="h6">Como vai aparecer</h4>` + previas({ titulos: 'seo-titulo,titulo', descricoes: 'seo-descricao,descricao', imagemId: imgSeo, url: `exemplo.com.br › ${v.secao.toLowerCase()} › ${v.slug || 'novo'}` }),
    }));
  const saida = tipo === 'evento'
    ? campo({ id: 'tirar-do-ar', rotulo: 'Tirar do ar a partir de', tipo: 'datetime-local', ajuda: 'Horário de Brasília. Vazio = sem prazo. Útil para eventos que acabam.', curto: true, extra: { 'data-depois-de': 'publicar-em' } })
    : campo({ id: 'tirar-do-ar', rotulo: 'Tirar do ar a partir de', tipo: 'datetime-local', ajuda: 'Horário de Brasília. Vazio = sem prazo.', curto: true, extra: { 'data-depois-de': 'publicar-em' } });
  const painelPublicacao = painel('publicacao', 'Publicação',
    opcoesRadio({ nome: 'estado', legenda: 'Estado', ajuda: 'Salvar não muda o estado. “Agendado” é calculado pelas datas.', itens: [['privado', 'Privado: só quem tem conta vê', v.estado === 'privado'], ['publicado', 'Publicado: aparece no site', v.estado === 'publicado']] })
    + `<p>Estado agora: <span class="selo" data-selo-estado></span></p>`
    + marcar({ id: 'agendar', rotulo: 'Agendar publicação', ajuda: 'Ligue para escolher quando entra e quando sai do ar.', marcado: false, extra: { 'data-alterna': 'bloco-datas' } })
    + `<div id="bloco-datas" class="cartao"><div class="colunas-2">`
    + campo({ id: 'publicar-em', rotulo: 'Publicar a partir de', tipo: 'datetime-local', ajuda: 'Horário de Brasília. Vazio = assim que publicado.', curto: true })
    + saida + `</div></div>`
    + campo({ id: 'peso', rotulo: 'Peso', tipo: 'number', valor: '10', ajuda: 'Menor aparece primeiro nas listas e no menu.', curto: true, extra: { 'data-tipo': 'inteiro', min: 0, max: 999, inputmode: 'numeric' } })
    + detalhes({ titulo: 'Menu do site', corpo: marcar({ id: 'no-menu', rotulo: 'Mostrar no menu do site', ajuda: 'Padrão: não.' }) }));
  const painelAvancado = painel('avancado', 'Avançado',
    `<p class="aviso-bloco">Só vale para esta ${tipo === 'pagina' ? 'página' : 'tela'}, ou para o que estiver dentro dela. Não muda o resto do site.</p>`
    + selecao({ id: 'pele', rotulo: 'Pele', ajuda: 'A pele é a “roupa” do site.', opcoes: [['herdar', 'Herdar (Padrão)', true], ['praia', 'Praia'], ['escura', 'Escura']] })
    + selecao({ id: 'molde', rotulo: 'Aparência (molde)', ajuda: 'Automático escolhe pelo tipo de conteúdo.', opcoes: [['auto', 'Automático (pela classe)', true], ['coluna', 'Coluna única'], ['galeria', 'Com galeria']] }));
  const corpo = `<h1>${novo ? 'Novo evento' : v.nome}</h1>
<p>${tipo === 'evento' ? 'Eventos' : v.tipoNome} em ${v.secao} · <span class="selo ${v.estado === 'privado' ? 'selo-privado' : 'selo-publicado'}" data-selo-estado>${v.estado === 'privado' ? 'Privado' : 'Publicado'}</span></p>
<div class="edicao"><form data-salvavel data-versao="${v.versao}" action="${v.cancelar}" method="get" data-abas>
${resumoErros()}
${abas([['conteudo', 'Conteúdo'], ['publicacao', 'Publicação'], ['avancado', 'Avançado']], 'Partes da edição')}
${painelConteudo}${painelPublicacao}${painelAvancado}
${barraAcoes({ estadoInicial: estadoTexto, cancelar: v.cancelar, publicar: v.estado === 'privado' })}
</form>
<aside class="lateral cartao" aria-label="Resumo da publicação"><h2>Publicação</h2>
<p>Estado: <span class="selo" data-selo-estado></span></p>
<p>Endereço: <code>/${v.secao.toLowerCase()}/${v.slug || 'novo'}</code></p>
<p>${novo ? 'Ainda não salvo.' : 'Versão ' + v.versao + ' · alterado há 2 dias por Ana'}</p>
<p class="ajuda">Depois de salvar, o aviso aparece nesta mesma tela. Não existe página de prévia à parte.</p></aside></div>`;
  return pagina({
    arquivo: v.arquivo, titulo: v.titulo, menu: 'conteudo', largo: true,
    migalhas: [['Início', 'inicio.html'], [SITE, 'conteudo.html'], [v.secao, v.secaoHref], ...(novo ? [['Novo evento']] : [[v.nome, tipo === 'pagina' ? 'ver.html' : v.secaoHref], ['Editar']])],
    corpo,
  });
}

export const ARQUIVOS_EDICAO = { 'editar-pagina.html': 'pagina', 'editar.html': 'noticia', 'editar-evento.html': 'evento' };

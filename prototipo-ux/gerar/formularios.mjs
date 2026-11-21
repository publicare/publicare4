// Telas de edição (página, notícia, evento) e Configurações: mesmas seções, mesmos toggles, mesma barra.
import { campo, imagem, etiquetas, opcao, opcoes, secao, recolhido, caixa, abas, painel, barra, resumoErros, esc, selo, icone, seloDe } from './ui.mjs';
import { TAGS_USADAS } from './dados.mjs';

const previaBusca = ({ fontesTitulo, fontesDesc, sufixo = '', endereco, prefixo }) => `<div class="previa" data-previa data-fontes-titulo="${fontesTitulo}" data-fontes-desc="${fontesDesc}"${sufixo ? ` data-fontes-sufixo="${sufixo}"` : ''}${endereco ? ` data-fontes-endereco="${endereco}"` : ''} data-url-prefixo="${esc(prefixo)}">
  <span class="previa-rotulo">Como pode aparecer no Google</span>
  <span class="previa-url"></span><span class="previa-titulo"></span><span class="previa-texto"></span>
  <span class="previa-corte" hidden>Pode ser cortado nos resultados.</span>
</div>`;

const quando = (extra = {}) => {
  const { agendar, validade } = extra;
  return secao({
    titulo: 'Quando aparece no site', desc: 'Sem nada ligado, vale a partir do momento em que você publicar.',
    corpo: opcao({
      id: 'agendar', titulo: 'Agendar publicação', ligado: !!agendar, ajuda: 'Escolha o dia e a hora em que o conteúdo passa a aparecer. Até lá, ele fica privado.',
      campos: campo({ id: 'publicar-em', rotulo: 'Publicar a partir de', tipo: 'datetime-local', valor: agendar || '', curto: true, ajuda: 'Horário de Brasília.' }),
    }) + opcao({
      id: 'validade', titulo: 'Ter data de validade', ligado: !!validade, ajuda: 'O conteúdo sai do ar sozinho na data escolhida. Bom para eventos e promoções.',
      campos: campo({ id: 'tirar-do-ar', rotulo: 'Tirar do ar a partir de', tipo: 'datetime-local', valor: validade || '', curto: true, ajuda: 'Horário de Brasília. Precisa ser depois da publicação.' }),
    }),
  });
};

const estado = (e) => secao({
  titulo: 'Estado', desc: 'Salvar guarda o rascunho. Só Publicar põe no ar.',
  corpo: `<div><p>${seloDe(e).replace('class="selo', 'data-selo-estado class="selo')} <span data-estado-texto>${e === 'privado' ? 'Só quem tem conta vê.' : 'Já está visível no site.'}</span></p>
  <p class="ajuda" data-so-privado${e === 'privado' ? '' : ' hidden'}>Quando estiver pronto, use “Publicar” na barra de baixo.</p>
  <p data-so-publicado${e === 'privado' ? ' hidden' : ''}><button type="button" class="btn btn--secundario" data-acao="tirar">Tirar do ar</button></p></div>`,
});

const menuEOrdem = (nome) => secao({
  titulo: 'Menu e ordem', desc: 'Onde este conteúdo aparece nos menus e listas do site.',
  corpo: opcao({
    id: 'menu', titulo: 'Mostrar no menu do site', ajuda: 'Cria um link para este conteúdo no menu principal do site.', ligado: false,
    campos: campo({ id: 'menu-nome', rotulo: 'Nome no menu', valor: nome, max: 30, ajuda: 'Curto, de preferência uma palavra.', medio: true }),
  }) + campo({ id: 'peso', rotulo: 'Posição nas listas', tipo: 'number', valor: '10', regra: 'inteiro', min: 0, maxValor: 99, curto: true, ajuda: 'Número menor aparece primeiro. De 0 a 99.' }),
});

const seo = (prefixo, titulo = '', desc = '') => secao({
  titulo: 'Buscadores e redes sociais', desc: 'Por padrão usamos o título e o resumo acima.',
  corpo: opcao({
    id: 'seo', titulo: 'Personalizar para buscadores (SEO)', ligado: !!(titulo || desc),
    ajuda: 'Escreva um título e uma descrição só para o Google e para quando alguém compartilhar o link.',
    campos: campo({ id: 'seo-titulo', rotulo: 'Título para buscadores', valor: titulo, max: 60, ajuda: 'Até 60 caracteres aparecem inteiros.' })
      + campo({ id: 'seo-desc', rotulo: 'Descrição para buscadores', area: true, linhas: 3, valor: desc, max: 160, ajuda: 'Uma ou duas frases. Até 160 caracteres.' })
      + imagem({ id: 'seo-imagem', rotulo: 'Imagem ao compartilhar', ajuda: 'Se ficar vazio, usamos a imagem do conteúdo.' })
      + previaBusca({ fontesTitulo: 'seo-titulo titulo', fontesDesc: 'seo-desc resumo', endereco: 'endereco', prefixo }),
  }) + opcoes({ rotulo: 'Buscadores', itens: [{ id: 'indexar', texto: 'Deixar os buscadores encontrarem este conteúdo', ajuda: 'Desmarque para esconder do Google. Quem tem o link ainda abre.', marcado: true }] }),
});

const avancado = (itensAvancados, extra = '') => `${secao({
  titulo: 'Aparência', desc: 'O visual herdado do site é o certo para quase tudo.',
  corpo: opcao({
    id: 'visual', titulo: 'Usar outro visual só nesta página', ligado: false,
    ajuda: 'Troque a pele e o molde apenas aqui. O resto do site não muda.',
    campos: campo({ id: 'pele', rotulo: 'Pele', opcoes: [['herdar', 'Herdar do site (Clássica)'], ['verao', 'Verão'], ['escura', 'Escura']], valor: 'herdar', medio: true })
      + campo({ id: 'molde', rotulo: 'Molde', opcoes: [['auto', 'Automático, pelo tipo'], ['simples', 'Página simples'], ['larga', 'Largura total']], valor: 'auto', medio: true, ajuda: 'Vale para este conteúdo e para o que estiver dentro dele.' }),
  }),
})}${itensAvancados}${secao({
  titulo: 'Remover', desc: 'Nada some de vez: a lixeira guarda por 30 dias.',
  corpo: `<div><button type="button" class="btn btn--perigo" data-revela="lixeira-confirma" aria-expanded="false" aria-controls="lixeira-confirma">Mandar para a lixeira</button>
  <div class="confirmacao" id="lixeira-confirma" hidden><p>Este conteúdo sai do site e vai para a lixeira. Dá para recuperar em Conteúdo, filtrando por “Na lixeira”.</p><p><a class="btn btn--perigo" href="conteudo-quartos.html?estado=lixeira">Sim, mandar para a lixeira</a> <button type="button" class="btn btn--discreto" data-fecha="lixeira-confirma">Cancelar</button></p></div></div>`,
})}${extra}`;

const historico = () => `<section class="secao" aria-labelledby="hist-t"><div class="secao-cab"><h2 id="hist-t">Histórico</h2><p>As versões anteriores ficam guardadas.</p></div><div class="secao-corpo">${recolhido({
  titulo: 'Ver as últimas versões', corpo: `<ul class="lista"><li><div class="item"><div class="item-texto"><span class="item-titulo">Versão 7</span><span class="item-meta">há 2 dias · Ana · ajuste no texto</span></div></div></li><li><div class="item"><div class="item-texto"><span class="item-titulo">Versão 6</span><span class="item-meta">há 3 semanas · Bruno · nova foto</span></div></div></li></ul>` })}</div></section>`;

/** Tela de edição. tipo: pagina | noticia | evento. novo: formulário em branco. */
export function formularioEdicao({ tipo, novo, titulo, versao = 7, estadoConteudo = 'privado' }) {
  const t = novo ? '' : titulo;
  const pref = tipo === 'evento' ? 'exemplo.com.br/eventos/' : tipo === 'noticia' ? 'exemplo.com.br/novidades/' : 'exemplo.com.br/quartos/';
  const slug = novo ? '' : titulo.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-');
  const rotuloTexto = tipo === 'noticia' ? 'Texto da notícia' : 'Texto';
  const essencial = {
    pagina: campo({ id: 'titulo', rotulo: 'Título', obrigatorio: true, max: 80, valor: t, ajuda: 'Aparece no topo da página e nas listas.' })
      + campo({ id: 'texto', rotulo: rotuloTexto, area: true, alto: true, valor: novo ? '' : 'A Suíte Master tem 42 m², cama king e varanda com vista para o jardim. Café da manhã incluso.', ajuda: 'Negrito, listas e links funcionam como no editor de sempre.' })
      + imagem({ id: 'imagem', rotulo: 'Imagem de capa', atual: novo ? '' : 'suite-master.jpg' }),
    noticia: campo({ id: 'titulo', rotulo: 'Título', obrigatorio: true, max: 80, valor: t, ajuda: 'Diga o que aconteceu, em poucas palavras.' })
      + campo({ id: 'resumo', rotulo: 'Resumo', area: true, linhas: 3, max: 200, valor: novo ? '' : 'A varanda ganhou vista livre para o jardim depois da poda.', ajuda: 'Aparece nas listas e quando alguém compartilha.', opcional: true })
      + campo({ id: 'texto', rotulo: rotuloTexto, area: true, alto: true, valor: novo ? '' : 'Depois de três meses de obra, a varanda está aberta para os hóspedes.', ajuda: 'Negrito, listas e links funcionam como no editor de sempre.' })
      + imagem({ id: 'imagem', rotulo: 'Imagem', atual: novo ? '' : 'varanda.jpg' }),
    evento: campo({ id: 'titulo', rotulo: 'Nome do evento', obrigatorio: true, max: 80, valor: t, ajuda: 'Como as pessoas vão chamar o evento.' })
      + campo({ id: 'resumo', rotulo: 'Resumo', area: true, linhas: 3, max: 200, valor: novo ? '' : 'Trio de jazz no salão, com entrada gratuita.', ajuda: 'Uma ou duas frases para a lista de eventos.', opcional: true })
      + imagem({ id: 'imagem', rotulo: 'Imagem', atual: novo ? '' : 'jazz.jpg' }),
  }[tipo];
  const evento = tipo === 'evento' ? secao({
    titulo: 'Quando e onde', desc: 'O que as pessoas precisam para chegar.',
    corpo: campo({ id: 'data-evento', rotulo: 'Data e hora do evento', tipo: 'datetime-local', obrigatorio: true, valor: novo ? '' : '2027-12-12T20:00', curto: true, ajuda: 'Horário de Brasília.' })
      + campo({ id: 'local', rotulo: 'Local', valor: novo ? '' : 'Salão principal', max: 120, ajuda: 'Nome do lugar ou endereço curto.', opcional: true })
      + opcao({ id: 'vagas-ligado', titulo: 'Limitar o número de vagas', ligado: false, ajuda: 'Mostra “vagas limitadas” e o número no evento.', campos: campo({ id: 'vagas', rotulo: 'Quantas vagas', tipo: 'number', regra: 'inteiro', min: 1, maxValor: 5000, curto: true, ajuda: 'De 1 a 5.000.' }) })
      + opcao({ id: 'inscricao', titulo: 'Pedir inscrição por um link', ligado: false, ajuda: 'Mostra um botão “Quero ir” que leva para o endereço que você escolher.', campos: campo({ id: 'inscricao-url', rotulo: 'Endereço da inscrição', tipo: 'url', regra: 'url', placeholder: 'https://', ajuda: 'Começa com https://.' }) }),
  }) : '';
  const organizacao = secao({
    titulo: 'Endereço e organização', desc: 'Como este conteúdo é encontrado.',
    corpo: campo({ id: 'endereco', rotulo: 'Endereço na internet', prefixo: '/', valor: slug.replace(/^-|-$/g, ''), regra: 'slug', max: 60, ajuda: 'Só letras minúsculas, números e hífen. Preenchido pelo título.', obrigatorio: true })
      + etiquetas({ id: 'tags', rotulo: 'Tags', valor: novo ? '' : (tipo === 'pagina' ? 'hotel, suíte' : 'novidades'), sugestoes: TAGS_USADAS, ajuda: 'Palavras que ajudam a achar o conteúdo. Até 10; separe com Enter ou vírgula.' }),
  });
  const abaConteudo = painel('conteudo', 'Conteúdo', secao({ titulo: 'O essencial', desc: 'O que quem visita vai ler.', corpo: essencial }) + evento + organizacao + seo(pref, '', ''));
  const abaPublicacao = painel('publicacao', 'Publicação', estado(estadoConteudo) + quando(tipo === 'evento' && !novo ? { validade: '2027-12-13T02:00' } : {}) + menuEOrdem(titulo || ''));
  const abaAvancado = painel('avancado', 'Avançado', avancado(novo ? '' : historico(), novo ? '' : secao({ titulo: 'Informações', corpo: `<dl class="dados"><dt>Criado por</dt><dd>Ana, há 8 meses</dd><dt>Identificador</dt><dd class="mono">142</dd></dl>` })));
  const estadoBarra = novo ? ['alterado', 'Ainda não salvo'] : ['salvo', `Tudo salvo · versão ${versao}`];
  const botoes = `<a class="btn btn--discreto" href="${{ pagina: 'conteudo-quartos.html', noticia: 'conteudo-novidades.html', evento: 'conteudo-eventos.html' }[tipo]}">Voltar</a>
    <button type="button" class="btn btn--secundario" data-acao="salvar">Salvar rascunho</button>
    <button type="button" class="btn btn--principal" data-acao="publicar" data-so-privado>Publicar</button>`;
  const botoesPublicado = estadoConteudo === 'publicado' ? `<a class="btn btn--discreto" href="conteudo.html">Voltar</a><button type="button" class="btn btn--principal" data-acao="salvar">Salvar alterações</button>` : null;
  return `<form class="formulario" data-edicao="geral" data-versao="${novo ? 0 : versao}" data-estado-conteudo="${estadoConteudo}" action="#" novalidate>
${resumoErros()}
${abas([['conteudo', 'Conteúdo'], ['publicacao', 'Publicação'], ['avancado', 'Avançado']])}
${abaConteudo}
${abaPublicacao}
${abaAvancado}
${barra({ estado: estadoBarra[0], texto: estadoBarra[1], botoes: botoesPublicado || botoes })}
</form>`;
}

/** Configurações do site: 5 abas, cada uma salva sozinha. */
export function formularioConfig() {
  const identidade = painel('identidade', 'Identidade',
    secao({ titulo: 'Nome e idioma', desc: 'Como o site se apresenta.', corpo:
      campo({ id: 'nome', rotulo: 'Nome do site', obrigatorio: true, max: 120, valor: 'Site Exemplo', ajuda: 'Aparece no topo e na aba do navegador.' })
      + campo({ id: 'slogan', rotulo: 'Frase de apoio', max: 200, valor: 'Hospedagem tranquila, perto de tudo', opcional: true, ajuda: 'Uma linha que explica o site.' })
      + campo({ id: 'idioma', rotulo: 'Idioma', opcoes: [['pt-BR', 'Português (Brasil)'], ['en', 'English'], ['es-AR', 'Español (Argentina)']], valor: 'pt-BR', medio: true }) })
    + secao({ titulo: 'Imagens', desc: 'Logo e ícone da aba do navegador.', corpo:
      imagem({ id: 'logo', rotulo: 'Logo', atual: 'logo-exemplo.png' }) + imagem({ id: 'favicon', rotulo: 'Ícone da aba', ajuda: 'Quadrado, de 64 por 64 pixels ou mais.' })
      + opcao({ id: 'abertura-ligado', titulo: 'Usar imagem de abertura', ligado: false, ajuda: 'Uma imagem grande no topo da página inicial.', campos: imagem({ id: 'abertura', rotulo: 'Imagem de abertura', opcional: false }) }) }));
  const buscadores = painel('buscadores', 'Buscadores',
    secao({ titulo: 'Título e descrição padrão', desc: 'Usados quando um conteúdo não tem os seus.', corpo:
      campo({ id: 'titulo-padrao', rotulo: 'Título padrão', max: 120, valor: 'Site Exemplo', ajuda: 'Aparece no Google quando a página não define outro.' })
      + campo({ id: 'sufixo', rotulo: 'Final do título', max: 60, valor: '· Site Exemplo', opcional: true, ajuda: 'Colado depois do título de cada página.' })
      + campo({ id: 'desc-padrao', rotulo: 'Descrição padrão', area: true, linhas: 3, max: 300, valor: 'Pousada tranquila com café da manhã e quartos para toda a família.', ajuda: 'Uma ou duas frases sobre o site.' })
      + previaBusca({ fontesTitulo: 'titulo-padrao', fontesDesc: 'desc-padrao', sufixo: 'sufixo', prefixo: 'exemplo.com.br' }) })
    + secao({ titulo: 'Compartilhamento', desc: 'Como o link aparece em redes sociais e mensagens.', corpo:
      imagem({ id: 'imagem-padrao', rotulo: 'Imagem padrão', ajuda: 'Usada quando o conteúdo não tem imagem.' })
      + opcao({ id: 'indexar-site', titulo: 'Deixar os buscadores encontrarem o site', ligado: true, ajuda: 'Desligue só se o site ainda estiver em construção.' }) }));
  const contato = painel('contato', 'Contato',
    secao({ titulo: 'Como falar com vocês', desc: 'Aparece na página de contato e no rodapé.', corpo:
      campo({ id: 'email', rotulo: 'E-mail de contato', tipo: 'email', regra: 'email', valor: 'contato@siteexemplo.com.br', auto: 'email', max: 120, ajuda: 'Receba as mensagens do site aqui.' })
      + campo({ id: 'telefone', rotulo: 'Telefone', tipo: 'tel', regra: 'telefone', valor: '(11) 3456-7890', auto: 'tel', curto: true, opcional: true, ajuda: 'Com DDD.' })
      + campo({ id: 'endereco-site', rotulo: 'Endereço', area: true, linhas: 3, max: 300, valor: 'Rua das Palmeiras, 120, Centro', opcional: true, ajuda: 'Como chegar, em poucas linhas.' }) })
    + secao({ titulo: 'Redes sociais', desc: 'Links que aparecem no rodapé.', corpo:
      opcao({ id: 'redes-ligado', titulo: 'Mostrar redes sociais no site', ligado: false, ajuda: 'Ícones de links no rodapé, um para cada endereço.', campos: campo({ id: 'redes', rotulo: 'Endereços das redes', area: true, linhas: 3, regra: 'urls', placeholder: 'https://', ajuda: 'Um endereço por linha, começando com https://.' }) }) }));
  const rodape = painel('rodape', 'Rodapé e métricas',
    secao({ titulo: 'Rodapé', desc: 'O texto no fim de todas as páginas.', corpo:
      opcao({ id: 'rodape-ligado', titulo: 'Usar rodapé personalizado', ligado: false, ajuda: 'Sem isto, o site mostra o rodapé padrão com nome e contato.', campos: campo({ id: 'rodape', rotulo: 'Texto do rodapé', area: true, linhas: 4, max: 500, ajuda: 'Texto simples, com links se precisar.' }) }) })
    + secao({ titulo: 'Métricas', desc: 'Saber quantas pessoas visitam o site.', corpo:
      opcao({ id: 'analytics-ligado', titulo: 'Medir as visitas', ligado: false, ajuda: 'Liga a contagem de visitas do serviço de métricas que você usa.', campos: campo({ id: 'analytics', rotulo: 'Identificador da métrica', regra: 'analytics', max: 40, medio: true, placeholder: 'G-XXXXXXXX', ajuda: 'Letras, números, hífen e sublinhado.' }) }) }));
  const comportamento = painel('comportamento', 'Comportamento',
    secao({ titulo: 'Busca e listas', desc: 'Como o site se comporta para quem visita.', corpo:
      opcao({ id: 'busca-ligada', titulo: 'Busca no site', ligado: true, ajuda: 'Mostra o campo de busca para os visitantes.' })
      + campo({ id: 'itens-pagina', rotulo: 'Itens por página nas listas', tipo: 'number', regra: 'inteiro', min: 1, maxValor: 50, valor: '10', curto: true, ajuda: 'De 1 a 50.' }) })
    + recolhido({ titulo: 'Opções avançadas', desc: 'Feeds RSS e profundidade do menu.', corpo:
      opcao({ id: 'rss-ligado', titulo: 'Publicar feeds RSS', ligado: true, ajuda: 'Deixa leitores de notícias acompanharem o site.', campos: campo({ id: 'rss-itens', rotulo: 'Itens por feed', tipo: 'number', regra: 'inteiro', min: 1, maxValor: 100, valor: '20', curto: true, ajuda: 'De 1 a 100.' }) })
      + campo({ id: 'profundidade', rotulo: 'Profundidade do menu', tipo: 'number', regra: 'inteiro', min: 0, maxValor: 5, valor: '0', curto: true, ajuda: 'De 0 a 5. Zero usa o valor padrão do painel.' }) }));
  return `<form class="formulario" data-edicao="abas-independentes" action="#" novalidate>
${resumoErros()}
${abas([['identidade', 'Identidade'], ['buscadores', 'Buscadores'], ['contato', 'Contato'], ['rodape', 'Rodapé'], ['comportamento', 'Comportamento']])}
${identidade}${buscadores}${contato}${rodape}${comportamento}
${barra({ estado: 'salvo', texto: 'Tudo salvo', botoes: '<a class="btn btn--discreto" href="inicio.html">Voltar</a><button type="button" class="btn btn--principal" data-acao="salvar" data-rotulo-salvar>Salvar identidade</button>' })}
</form>`;
}

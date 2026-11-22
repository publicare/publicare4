// Dados fictícios do "Site Exemplo". Nenhum dado de cliente real.
export const SECOES = [
  { id: 'quartos', nome: 'Quartos', arquivo: 'conteudo-quartos.html', desc: 'As páginas que apresentam cada quarto.', tipo: 'Página', novo: 'novo-pagina.html' },
  { id: 'eventos', nome: 'Eventos', arquivo: 'conteudo-eventos.html', desc: 'Noites, feiras e festas com data marcada.', tipo: 'Evento', novo: 'novo-evento.html' },
  { id: 'novidades', nome: 'Novidades', arquivo: 'conteudo-novidades.html', desc: 'Notícias curtas sobre o que muda no site.', tipo: 'Notícia', novo: 'novo-noticia.html' },
];

export const ITENS = [
  { secao: 'quartos', titulo: 'Suíte Master', tipo: 'Página', estado: 'privado', quando: 'há 2 dias', editar: 'editar-pagina.html' },
  { secao: 'quartos', titulo: 'Suíte Família', tipo: 'Página', estado: 'publicado', quando: 'há 1 semana', editar: 'editar-pagina.html' },
  { secao: 'quartos', titulo: 'Quarto Solteiro', tipo: 'Página', estado: 'publicado', quando: 'há 1 mês', editar: 'editar-pagina.html' },
  { secao: 'quartos', titulo: 'Suíte Antiga', tipo: 'Página', estado: 'lixeira', quando: 'apagada há 3 semanas', editar: 'editar-pagina.html' },
  { secao: 'eventos', titulo: 'Noite de jazz', tipo: 'Evento', estado: 'publicado', quando: 'há 3 dias', editar: 'editar-evento.html' },
  { secao: 'eventos', titulo: 'Feira de inverno', tipo: 'Evento', estado: 'agendado', quando: 'há 5 dias', editar: 'editar-evento.html' },
  { secao: 'eventos', titulo: 'Festa de Natal do ano passado', tipo: 'Evento', estado: 'vencido', quando: 'há 11 meses', editar: 'editar-evento.html' },
  { secao: 'novidades', titulo: 'Reforma do restaurante', tipo: 'Notícia', estado: 'publicado', quando: 'há 2 semanas', editar: 'editar-noticia.html' },
  { secao: 'novidades', titulo: 'Nova vista da varanda', tipo: 'Notícia', estado: 'privado', quando: 'há 3 horas', editar: 'editar-noticia.html' },
  { secao: 'novidades', titulo: 'Promoção de inverno', tipo: 'Notícia', estado: 'publicado', quando: 'há 1 mês', editar: 'editar-noticia.html' },
];
export const nomeSecao = (id) => SECOES.find((s) => s.id === id).nome;
export const TAGS_USADAS = ['hotel', 'suíte', 'inverno', 'gastronomia'];
export const OPCOES_CONTEUDO = ITENS.filter((i) => i.estado !== 'lixeira').map((i, k) => ({ id: String(100 + k), titulo: i.titulo, caminho: `${nomeSecao(i.secao)} › ${i.titulo}` }));

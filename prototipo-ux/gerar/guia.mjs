import { pagina } from './casca.mjs';
import { campo, secao, opcao, recolhido, selo, cabecalho, botao, esc, barra, linha, lista, caixa } from './ui.mjs';

const cores = [['fundo', 'Fundo da página'], ['superficie', 'Superfície'], ['suave', 'Faixa suave'], ['linha', 'Linha fina'], ['borda', 'Borda de controle'], ['texto', 'Texto'], ['texto-2', 'Texto de apoio'], ['acao', 'Laranja da marca'], ['acao-suave', 'Laranja suave'], ['azul', 'Botão principal'], ['amarelo', 'Botão de atenção'], ['erro', 'Erro'], ['aviso', 'Aviso'], ['ok', 'Certo']];
const anti = [
  ['Grade de cartões iguais com ícone, título e texto', 'Início é lista de tarefas; Mais e Conteúdo são listas de linhas.'],
  ['Gradiente, sombra, brilho', 'Nenhum. Só linha de 1 px.'],
  ['Roxo ou azul-néon', 'Branco e laranja da marca; azul, amarelo e vermelho só nos botões, conforme a ação.'],
  ['Emoji como ícone', 'Nenhum emoji. Cinco ícones de traço, só onde ajudam.'],
  ['Tudo centralizado', 'Tudo à esquerda, numa grade só.'],
  ['Bordas muito redondas', '6 px nos controles e 4 px nos selos.'],
  ['Texto de enchimento', '“Olá, Ana.” e o que há para fazer.'],
  ['Ícone sem função no menu', 'O menu é só texto.'],
  ['Selos coloridos demais', 'Quatro tons, sempre com a palavra.'],
  ['Botões genéricos (“Enviar”, “OK”)', 'Verbos da tarefa: “Publicar”, “Salvar rascunho”, “Recuperar como privada”.'],
];

export default pagina({
  titulo: 'Guia visual', tela: 'Guia visual', ativo: null, trilha: [['Roteiro', 'index.html'], ['Guia visual', 'guia.html']],
  corpo: `${cabecalho({ titulo: 'Guia visual', desc: 'Os blocos que montam todas as telas. Use o botão “Tema escuro” no menu para ver os dois temas.' })}
<section class="exemplo" aria-labelledby="g-cores"><h2 id="g-cores">Cores</h2><p class="suave">Branco e laranja da marca. Azul, amarelo e vermelho aparecem nos botões, conforme a ação.</p><div class="amostras">${cores.map(([c, n]) => `<div class="amostra"><i class="c-${c}"></i><span>${n}<code>--pbl-${c}</code></span></div>`).join('')}</div></section>
<section class="exemplo" aria-labelledby="g-tipo"><h2 id="g-tipo">Letras</h2><p class="suave">Fonte do sistema. Quatro tamanhos e dois pesos.</p>
<div class="escala"><div class="escala-linha"><span class="t-ajuda">28/36 · 600</span><span class="t-h1">Título da tela</span></div><div class="escala-linha"><span class="t-ajuda">18/28 · 600</span><span class="t-h2">Título de seção</span></div><div class="escala-linha"><span class="t-ajuda">16/24 · 400</span><span class="t-corpo">Texto, campos e botões. A Suíte Master tem varanda com vista.</span></div><div class="escala-linha"><span class="t-ajuda">14/20 · 400</span><span class="t-ajuda">Ajuda, datas e detalhes. Nunca menor que isso.</span></div></div></section>
<section class="exemplo" aria-labelledby="g-esp"><h2 id="g-esp">Espaço</h2><p class="suave">Tudo em múltiplos de 4 px.</p>
<div class="espaco">${[4, 8, 12, 16, 24, 32, 48].map((n) => `<div><i class="e-${n}"></i>${n}</div>`).join('')}</div>
<p class="suave">Entre campos 24 · entre seções 64 (32 + 32 com linha) · margem lateral 16 no celular e 32 no computador · formulário com no máximo 680 px.</p></section>
<section class="exemplo" aria-labelledby="g-btn"><h2 id="g-btn">Botões e selos</h2><p class="barra-botoes"><button type="button" class="btn btn--principal">Publicar</button><button type="button" class="btn btn--secundario">Salvar rascunho</button><button type="button" class="btn btn--discreto">Voltar</button><button type="button" class="btn btn--aviso">Tirar do ar</button><button type="button" class="btn btn--perigo">Mandar para a lixeira</button></p>
<p class="barra-botoes">${selo('Privado')}${selo('Publicado', 'ok')}${selo('Agendado', 'aviso')}${selo('Na lixeira', 'erro')}</p>
<p class="suave">Um botão principal por tela. O selo sempre diz o estado em palavras.</p></section>
<section class="exemplo" aria-labelledby="g-campo"><h2 id="g-campo">Campo</h2><p class="suave">Rótulo em cima, ajuda embaixo, erro com ícone e texto.</p><div class="moldura secao-corpo">
${campo({ id: 'ex-titulo', rotulo: 'Título', obrigatorio: true, max: 60, valor: 'Suíte Master', ajuda: 'Aparece no topo da página e nas listas.' })}
${campo({ id: 'ex-email', rotulo: 'E-mail de contato', tipo: 'email', regra: 'email', valor: 'contato@siteexemplo', ajuda: 'Receba as mensagens do site aqui.' }).replace('<p class="erro" id="ex-email-erro"></p>', '<p class="erro" id="ex-email-erro">Escreva o e-mail completo, como nome@exemplo.com.br.</p>').replace('<input id="ex-email"', '<input aria-invalid="true" id="ex-email"')}
</div></section>
<section class="exemplo" aria-labelledby="g-tog"><h2 id="g-tog">Interruptor</h2><p class="suave">O opcional fica escondido até ligar. O que você escreveu não some ao desligar.</p><div class="moldura">
${opcao({ id: 'ex-agendar', titulo: 'Agendar publicação', ligado: false, ajuda: 'Escolha o dia e a hora em que o conteúdo passa a aparecer.', campos: campo({ id: 'ex-quando', rotulo: 'Publicar a partir de', tipo: 'datetime-local', curto: true, ajuda: 'Horário de Brasília.' }) })}
${opcao({ id: 'ex-validade', titulo: 'Ter data de validade', ligado: true, ajuda: 'O conteúdo sai do ar sozinho na data escolhida.', campos: campo({ id: 'ex-saida', rotulo: 'Tirar do ar a partir de', tipo: 'datetime-local', curto: true, valor: '2027-12-13T02:00' }) })}</div></section>
<section class="exemplo" aria-labelledby="g-sec"><h2 id="g-sec">Seção e recolhido</h2><div class="moldura">${secao({ titulo: 'O essencial', desc: 'Uma linha que diz para que serve o grupo.', corpo: campo({ id: 'ex-resumo', rotulo: 'Resumo', opcional: true, ajuda: 'Aparece nas listas.' }) })}
${recolhido({ titulo: 'Opções avançadas', desc: 'Só abra se souber o que faz.', corpo: campo({ id: 'ex-prof', rotulo: 'Profundidade do menu', tipo: 'number', curto: true, valor: '0' }) })}</div></section>
<section class="exemplo" aria-labelledby="g-aba"><h2 id="g-aba">Abas</h2><nav class="abas" aria-label="Exemplo de abas"><a href="#g-aba" aria-current="page">Conteúdo</a><a href="#g-aba">Publicação <span class="marca-aba">•1<span class="so-leitor"> erro</span></span></a><a href="#g-aba">Avançado</a></nav></section>
<section class="exemplo" aria-labelledby="g-lista"><h2 id="g-lista">Lista</h2>${lista([`<li>${linha({ titulo: 'Suíte Master', href: 'ver.html', meta: 'Quartos · Página · há 2 dias', fim: selo('Privado') + '<a class="btn btn--discreto btn--pequeno" href="editar-pagina.html">Editar</a>' })}</li>`, `<li>${linha({ titulo: 'Noite de jazz', href: 'ver.html', meta: 'Eventos · Evento · há 3 dias', fim: selo('Publicado', 'ok') + '<a class="btn btn--discreto btn--pequeno" href="editar-evento.html">Editar</a>' })}</li>`])}</section>
<section class="exemplo" aria-labelledby="g-barra"><h2 id="g-barra">Barra de ações</h2><p class="suave">Fica no pé da tela de edição. À esquerda, o estado. À direita, o que fazer.</p><div class="moldura">${barra({ estado: 'alterado', texto: 'Alterações não salvas', botoes: '<a class="btn btn--discreto" href="conteudo.html">Voltar</a><button type="button" class="btn btn--secundario">Salvar rascunho</button><button type="button" class="btn btn--principal">Publicar</button>' })}</div></section>
<section class="exemplo" aria-labelledby="g-anti"><h2 id="g-anti">O que denuncia “cara de IA” e o que fizemos</h2>
<table class="tabela-anti"><thead><tr><th scope="col">Sinal</th><th scope="col">Nesta versão</th></tr></thead><tbody>${anti.map(([a, b]) => `<tr><td>${esc(a)}</td><td>${esc(b)}</td></tr>`).join('')}</tbody></table></section>`,
});

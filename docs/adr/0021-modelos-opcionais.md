# ADR 0021 — Modelos opcionais de conteúdo (evento, faq, destaque, bloco) e demo

Status: aceito (2026-11-02). Complementa o [ADR 0015](0015-classes-fixas-e-pacote-minimo.md) (pacote mínimo de classes) e o [ADR 0016](0016-menu-busca-e-paginas-dinamicas.md) (moldes da pele básica).

## Contexto

O pacote mínimo (pasta, página, arquivo, imagem, link, notícia, formulário) cobre um site simples, mas quase todo site precisa de uma **agenda de eventos**, de **perguntas frequentes**, de **destaques na página inicial** e de **trechos reutilizáveis** (aviso, rodapé). Colocar tudo no pacote mínimo o incharia; deixar cada instalação criar na mão é trabalho repetido. Além disso, as instalações antigas não ganham classes novas sozinhas (pendência da fase 5l).

## Decisão

1. **Catálogo no código** (`Pbl\Conteudo\Modelos`): quatro modelos, cada um com nome, descrição, classes e moldes. Cada modelo se ativa **individualmente**; ativar é **idempotente** e **transacional** (as classes e os exemplos entram juntos ou nada entra).
   - `evento`: classes `agenda` (contêiner) e `evento` (resumo, corpo, `inicio`, `fim`, local, imagem, link de inscrição opcional, SEO).
   - `faq`: `perguntas` (contêiner) e `faq` (o **título** é a pergunta; `resposta` html; `categoria` opcional; a ordem vem do peso).
   - `destaque`: `destaque` (imagem, texto curto, destino interno **ou** externo, nova aba).
   - `bloco`: `bloco` (HTML reutilizável).
2. **Contêineres com molde próprio.** O molde é escolhido pelo nome da classe do objeto exibido (sem molde específico, `padrao`). Para a lista "próximos e passados" e a sanfona agrupada por categoria, cada modelo traz uma classe contêiner (`agenda`, `perguntas`) e o molde dela, em vez de lógica condicional no `padrao`.
3. **Moldes na pele básica.** Novos: `agenda`, `evento`, `perguntas`, `faq`, `destaque`, `bloco` e os trechos `faixa_destaques` (até 3 destaques por peso na página inicial), `bloco_aviso` (faixa de aviso no topo, quando existe o bloco `bloco-aviso`) e `bloco_rodape` (rodapé, quando existe `bloco-rodape`). `topo`, `padrao`, `item` e `estilo` incluem esses trechos; **sem o modelo ativo eles não produzem nada** (`localizar` de classe inexistente devolve vazio). "Próximos" e "passados" comparam `inicio` com a data de **publicação** (macros `%ANO/%MES/%DIA`): ao republicar, a divisão se atualiza (quem usa o cron `bin/pbl publicar` ou `agendados` mantém a página em dia).
4. **Instalações antigas.** Ativar copia os moldes novos que faltam para `dados/peles/basica/`. Os quatro moldes existentes que mudaram são **atualizados só se ainda forem byte a byte uma versão distribuída anteriormente** (lista de SHA-256 no código); se a pessoa os personalizou, nada é sobrescrito e a ativação devolve um aviso com o que incluir à mão. Em pele que não é a básica, só um aviso aponta [a documentação](../usuario/modelos-e-demo.md).
5. **Onde se ativa.** (a) Instalador web (caixas de seleção) e CLI `bin/pbl instalar --modelos=evento,faq` (ou `todos`); (b) tela `/_pbl/modelos` (administrador global, CSRF, auditoria `modelo.ativar`); (c) CLI `bin/pbl modelos listar|ativar <nome> [--site=ID] [--sem-exemplos]`. Os exemplos (uma agenda com 2 eventos, uma página de perguntas com 2 perguntas, uma pasta Destaques com 1 destaque, um bloco `bloco-rodape`) só entram se **todas** as classes do modelo estavam faltando; endereços já ocupados nunca são sobrescritos.
6. **Demo.** `bin/pbl demo-popular [--site=ID] [--forcar] [--sem-publicar]` ativa os quatro modelos e popula o site com o "Instituto Exemplo" (fictício): home com texto formatado, 3 destaques com imagens geradas por GD, Institucional em 3 níveis, 8 notícias com datas variadas (uma agendada e um rascunho), agenda com eventos futuros e passados, 8 perguntas em 2 categorias, documentos (TXT e PDF mínimo) e galeria de 6 imagens, links úteis. Idempotente (cada conteúdo é identificado pelo endereço) e **recusa** sites com mais de 30 conteúdos sem `--forcar`. É ferramenta de desenvolvimento e demonstração, não de produção.

## Consequências

- Endereços (`url_amigavel`) são únicos por site e planos: os exemplos usam nomes como `agenda`, `perguntas-frequentes`, `destaques`, `blocos`; se a pessoa já tem uma página com esse endereço, o exemplo é pulado (e o modelo funciona do mesmo jeito).
- A pele básica fica maior (mais arquivos), mas sem custo de execução quando o modelo não está ativo.
- Imagens do demo usam a fonte embutida do GD (sem FreeType), então o texto delas não tem acento.
- Fora de escopo: editor visual dos moldes novos, importação de eventos (iCal), inscrições em eventos e ordenação por categoria definida pela pessoa.

- **Endereços em `href` (revisão de segurança):** `<@ecourl@>` aceita só `http(s)://` ou `/caminho`; `destaque.destino_externo` e `evento.link_inscricao` recusam outros esquemas na gravação. O tipo `link` mantém a regra própria (inclui mailto/tel) aplicada na gravação.

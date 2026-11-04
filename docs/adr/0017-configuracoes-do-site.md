# ADR 0017 — Configurações do site

Status: aceito e implementado (2026-11-02, fase 5m). Complementa o [ADR 0015](0015-classes-fixas-e-pacote-minimo.md) (SEO) e o [ADR 0016](0016-menu-busca-e-paginas-dinamicas.md) (menu e busca).

## Contexto

Nome, idioma, textos de SEO, contato, rodapé, busca e profundidade do menu estavam espalhados pelo código, fixos nos moldes ou em variáveis de ambiente globais. O conversor PBL3 marcava `config` para revisão porque o molde não tinha onde ler valores do site.

## Decisão

### 1. Catálogo no código, valores no banco
`Pbl\Conteudo\ConfigDoSite` é a lista fechada de chaves (tipo, padrão, rótulo, ajuda e validação). Os valores ficam na tabela `site_config` (`site_id`, `chave`, `valor` em JSON, `atualizado_em`; chave primária `site_id` + `chave`), criada pela migração 17, **idempotente** (confere a tabela antes de criar; o `Migrador` guarda só a maior versão aplicada) e aditiva ([ADR 0006](0006-atualizador-automatico.md)). Chave fora do catálogo é rejeitada; valor vazio apaga a linha e volta ao padrão. Nada aqui é segredo: tudo pode aparecer no HTML público.

Chaves: `nome`, `slogan`, `idioma`, `descricao`, `imagem_padrao`, `titulo_padrao`, `titulo_sufixo`, `icone`, `email_contato`, `telefone`, `endereco`, `redes_sociais`, `analytics`, `rodape`, `busca_ligada`, `itens_por_pagina`, `menu_profundidade`, `rss_ligado`, `rss_itens`, `robots_indexar`.

### 2. Validação por tipo
Texto (tamanho máximo, sem caracteres de controle), idioma (`pt-BR`, `en`...), e-mail, telefone, inteiro com faixa, booleano, lista de endereços `http`/`https` (até 10; `javascript:` e outros esquemas são recusados), arquivo (número de uma **imagem do mesmo site**, conferido pelo repositório) e HTML (`HtmlSeguro`, como o resto do conteúdo). **Analytics guarda só o identificador** (`[A-Za-z0-9_-]{3,40}`), nunca um script. Se qualquer chave for recusada, nada é gravado.

### 3. Leitura nos moldes
`%SITE.config.chave` lê o valor da configuração do site do objeto corrente (só chaves do catálogo; chave desconhecida é erro de sintaxe do molde). Booleano vira `1`/`0`; lista vira linhas. A saída segue as regras de sempre: `eco` escapa, `ecocru` não (use `ecocru` só no `rodape`, que já foi limpo na gravação). O conversor PBL3 troca `<@config nome="x"@>` com chave do catálogo por `<@eco %SITE.config.x@>`; nome calculado ou fora do catálogo vai para revisão.

A pele básica usa os valores quando existem: `lang`, título padrão e sufixo, descrição e imagem padrão (como último recurso depois do SEO da página), favicon, `noindex` quando `robots_indexar` é 0, rodapé, caixa de busca só com `busca_ligada`, e `itens_por_pagina` na busca e nas listagens de pasta. `#site_nome` passa a ser a configuração `nome`, se houver. Com `busca_ligada` desligada, `/busca` responde 404. Sem nenhuma configuração gravada, a saída é a mesma de antes.

### 4. Menu
`menu_profundidade` (1 a 5; 0 = usar o teto global) vale por site e **nunca passa** do teto global `PBL_MENU_PROFUNDIDADE`. O snapshot do menu guarda a profundidade usada; mudar a configuração invalida o snapshot pelo mesmo mecanismo de hash.

### 5. Cache
Toda página publicada depende das configurações. Alterar qualquer chave republica o site inteiro pelo mesmo caminho do menu: até 300 objetos, na hora; acima disso, o site fica marcado como pendente para `bin/pbl publicar`/`agendados`. Se a republicação falhar, a configuração continua salva e a tela avisa.

### 6. Administração
`/_pbl/site/{id}/configuracoes`: só quem administra o site (a mesma regra de redirecionamentos e peles), **sem exigir modo elevado**; origem e CSRF conferidos; edição otimista por `versao` (hash do que está gravado; 409 se outra pessoa salvou antes); erros por campo (422); auditoria `site.config` com o `site_id` e **só os nomes** das chaves alteradas, nunca os valores; sem JavaScript.

### 7. Instalador
Grava `nome` (nome do site informado) e `idioma` (`pt-BR`).

## Consequências

- Novas chaves exigem mudar o catálogo (código), o que mantém a lista pública revisada; por isso não há "configuração livre".
- Imagem padrão e ícone referenciam arquivos por número; o arquivo só é servido enquanto o objeto dono estiver publicado e vigente ([ADR 0014](0014-upload-de-arquivos.md)), então a imagem precisa ficar num conteúdo publicado.
- Fora desta fase: `canonical` absoluto, `sitemap.xml` e `robots.txt` (pendências da 5l), uso de `analytics`, `email_contato`, `telefone`, `endereco`, `slogan` e `redes_sociais` na pele básica (os valores já ficam disponíveis a qualquer pele por `%SITE.config.*`), histórico de configurações e importação/exportação.

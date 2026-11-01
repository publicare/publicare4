# Changelog

Todas as mudanças relevantes do PBL4 ficam aqui. O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o projeto usa [Versionamento Semântico](https://semver.org/lang/pt-BR/) (regras em [docs/versionamento.md](docs/versionamento.md)). Como publicar uma versão: [docs/releases.md](docs/releases.md).

Enquanto a versão for `0.x`, o projeto ainda não promete estabilidade: mudanças que quebram podem entrar em versões MINOR e aparecem em **Alterado**.

## [Não lançado]

> Primeira versão pública prevista: **0.1.0** (núcleo mínimo: dados, linguagem de moldes, segurança básica, instalador e atualizador).

### Adicionado
- Estrutura do projeto: PHP 8.2+, SQLite por padrão (MySQL e PostgreSQL previstos), ambiente de desenvolvimento em container, testes (PHPUnit) e análise estática (PHPStan nível 8). (#3)
- Instalação por linha de comando (`bin/pbl instalar`) com site de exemplo e pele básica; publicação das páginas (`bin/pbl publicar`) em cache e entrega a partir do cache. (#4)
- Modelo de conteúdo: classes definidas sem código, objetos em árvore, dados validados pela classe, índice de propriedades, vários sites e domínios. (#4)
- **Linguagem de moldes PBL4**: expressões (`#campo`, `$variavel`, `%MACRO`), `eco`/`ecocru`, `var`, `se`/`senao`, `repetir`, `filhos`, `estende`/`bloco` (herança de layout), `incluir`, comentários e escape de HTML por padrão. Referência em [docs/linguagem-pbl4.md](docs/linguagem-pbl4.md). (#6)
- Comandos de consulta nos moldes: `localizar`, `aleatorio`, `localizaraleatorio`, `usarobjeto`, `temfilho`, `naolocalizado`. (#7)
- **Conversor de moldes PBL3 → PBL4** (`bin/pbl converter-moldes`) com relatório do que precisa de revisão. (#8)
- Metadados do objeto (`peso`, `descricao`, `data_publicacao`, `data_validade`) e ordenação por vários campos, inclusive por propriedade indexável. Objetos fora da vigência não aparecem. (#8)
- **Referências entre conteúdos**: novos tipos de propriedade `ref` (aponta para outro objeto **do mesmo site**, opcionalmente de uma classe), `data` e `booleano`, todos validados ao salvar. (#15)
- **Motor de consulta único** (`Consulta` + `MotorDeConsulta`): o portal, os moldes e, depois, o admin e a API consultam conteúdo pelo mesmo caminho. Filtra por coluna do objeto, propriedade indexável ou **campo de um objeto referenciado** (`autor.cargo`), com operadores `=`, `!=`, `<`, `>`, `<=`, `>=` e `contem`, até três condições, contagem para paginação e visão de admin (`apenasVisiveis: false`). (#15)
- **Ordenar pelo objeto referenciado** (`ordem="autor.titulo"`) numa única consulta, **sem tabela temporária**; nulos sempre por último, igual em qualquer banco. (#15)
- Nos moldes: `#autor.titulo` (campo do objeto referenciado), `expandir="autor"` (carrega as referências de uma lista em lote), `#temfilhos`, e em `localizar` os parâmetros `op`, `campo2`/`valor2`/`op2` e `campo3`/`valor3`/`op3`. (#15)
- Conversor de moldes: `condicao` com até três comparações ligadas por `and` e operadores `>`, `<`, `>=`, `<=`, `!=` e `<>`. (#15)
- **Busca que ignora maiúsculas e acentos**: `presidencia`, `PRESIDÊNCIA` e `Presidência` acham o mesmo conteúdo. Vale para o título, para as propriedades de texto indexáveis e para o título de objetos referenciados (`campo="autor.titulo" op="contem"`), igual em SQLite, MySQL e PostgreSQL. Os dados que já existiam são recalculados pela migração. ADR 0010 desenha a busca com relevância e tolerância a erros de grafia. (#17)
- **Histórico de versões**: cada objeto guarda uma versão a cada alteração (quem mudou, quando e o motivo). Dá para **restaurar** uma versão antiga (ela volta como uma versão nova; o histórico nunca é apagado) e há **proteção contra conflito**: salvar sobre uma versão que outra pessoa já mudou é recusado. (#18)
- **Mudar a URL de um conteúdo não quebra o endereço antigo**: o endereço anterior fica no histórico e passa a responder **301** para o atual (mantendo a query string, sempre para caminho do próprio site). Para tomar um endereço que redireciona para outro conteúdo, ao **criar** ou ao **alterar**, é preciso liberá-lo de propósito. (#18)
- **Mover um conteúdo na árvore** (com toda a subárvore), recusando ciclos e pai de outro site; os endereços não mudam. Estados válidos: `privado`, `submetido`, `rejeitado`, `publicado`, `apagado`. (#18)
- `bin/pbl publicar` remove do cache também as páginas de endereços que deixaram de existir. (#18)
- **Domínios com papel**: cada site tem um domínio **principal** (o canônico) e quantos domínios extras precisar, cada um como **`servir`** (mostra o mesmo conteúdo e avisa o canônico no cabeçalho `Link: rel="canonical"`) ou **`redirecionar`** (301 para o principal, mantendo caminho e consulta). Trocar o principal faz o antigo passar a redirecionar. O host de destino sempre vem do cadastro, nunca da requisição; host inválido na requisição dá 400. (#19)
- **Regras manuais de redirecionamento** por site, para endereços antigos que não são um conteúdo atual (maiúsculas, `.php`, sistemas que acabaram): exatas ou por prefixo (`/antigo/x` → `<destino>/x`, o prefixo mais longo vence), 301 ou 302, destino só no próprio site ou `https://`. Valem depois da página publicada e do histórico de URLs. (#19)
- Origens de regras com caracteres codificados (`/old%20name`) funcionam: são guardadas decodificadas, a mesma forma do caminho pedido; origens perigosas depois de decodificar (`%2e%2e`, `%0A`, `%3F`…) e UTF-8 inválido são recusados. (#19)
- Comandos `bin/pbl dominio` (listar, adicionar, principal, remover) e `bin/pbl redirecionamento` (listar, adicionar, remover) enquanto não há tela de administração. (#19)
- **Contas da equipe** (`usuario`) com senha guardada em **argon2id** (bcrypt onde o PHP não tem argon2), política de senha (10 a 1024 caracteres, sem senhas óbvias nem igual ao login), atualização automática do hash e verificação que não revela se o login existe (mesmo custo de tempo). (#20)
- **Perfis por seção da árvore**: `leitor`, `autor` (cria e edita só o que ele criou) e `editor` (edita, publica, apaga), concedidos num conteúdo e herdados por tudo abaixo dele; vale o mais forte; **administrador global** à parte. `Permissoes::pode` é o único ponto de decisão e a ação é um identificador fixo do código, nunca texto da requisição. (#20)
- **Autoria**: cada conteúdo guarda quem o criou e cada versão guarda quem a fez. **Auditoria** que só acrescenta (criar conta, trocar senha, ativar/desativar, conceder/revogar acesso), sem nunca registrar senha. (#20)
- Comando `bin/pbl usuario` (criar, senha, ativar, desativar, listar, conceder, revogar, acessos, auditoria); a senha é pedida de forma oculta (ou lida da entrada padrão), nunca por argumento. (#20)
- ADR 0011 (autenticação, sessão, permissões e auditoria). (#20)
- Documentação de design (ADRs 0003 a 0009, fluxos e plano), documentação para quem usa e técnica, guia de colaboração, política de segurança, versionamento e pipeline automática de testes.

### Alterado
- A URL `index` passou a ser reservada (a página inicial é gravada no cache como `index.html`). Quem já usava esse endereço é renomeado pela migração para `index-1` (ou o primeiro número livre), e a publicação pula um endereço inválido em vez de interromper o site. (#18)
- `contem` e `busca=` agora ignoram acentos, não só maiúsculas (antes `árvore` não achava `Arvore`). (#17)
- Internamente, a consulta de objetos saiu de `ObjetoRepositorio` e foi para o `MotorDeConsulta`; `#temfilhos` deixou de ser um aviso do conversor. (#15)
- A sintaxe dos moldes mudou em relação ao rascunho inicial: `<@eco titulo@>` agora é `<@eco #titulo@>` e `<@repetir filhos@>` virou `<@filhos@>`. (#6)

### Corrigido
- Instalar com um domínio inválido deixava a instalação pela metade e impedia tentar de novo; agora valida antes e grava tudo numa transação. (#19)
- Ao migrar, um site que já tinha vários domínios ficaria com todos como principais; agora um é o principal e os outros passam a servir o mesmo conteúdo. (#19)
- O redirecionamento mantém a query string antes da âncora (`/novo?x=1#secao`). (#19)
- Nova linha no fim da URL era aceita como válida. (#9)
- Data impossível (`2026-02-31`) virava 3 de março em `formatadata`; agora dá vazio. (#11)
- `repetir` podia girar sem fim nos limites dos inteiros. (#11)
- Busca por título e `usarobjeto titulo=` não achavam textos com letras acentuadas maiúsculas no SQLite. (#11)
- `aleatorio limite=1` sempre escolhia o primeiro item; agora sorteia antes de aplicar a janela. (#11)
- Bloco aninhado com o mesmo nome estourava a pilha do compilador; agora é um erro claro. (#11)
- Página com `data_validade` vencida continuava sendo servida do cache. (#10)

### Segurança
- Permissões não dependem mais de texto vindo da requisição (a falha do PBL3 em que quem podia `edit` executava a administração de usuários não é possível por construção): a ação é um valor fixo do código e a decisão usa só usuário, ação e objeto. Usuário inativo não pode nada; perfis não passam de um site para outro nem para fora da subárvore. (#20)
- Redirecionamentos cadastrados só aceitam destino no próprio site ou `https://` (sem `//`, `javascript:`, `http:`, credenciais, `..`, quebra de linha ou barra invertida); nomes de domínio são validados rótulo a rótulo, sem regex que cause *backtracking*. (#19)
- O redirecionamento de endereços antigos só vai para o caminho do próprio site (nunca para URL externa) e só se o conteúdo de destino estiver publicado e vigente. (#18)
- Uma propriedade `ref` só aceita objeto do mesmo site (e da classe indicada); seguir uma referência numa consulta também fica dentro do site. Consulta com campo inválido ou junções demais é recusada, e nenhum texto do molde entra no SQL. (#15)
- A validação de URL tinha *backtracking* catastrófico (~93 caracteres esgotavam o limite do PCRE, ~4 ms de CPU por requisição em endereço público). Reescrita com classes disjuntas, quantificadores possessivos e teto de 300 caracteres. (#9)
- Campos do sistema (`#id`, `#site`, `#pai`…) vencem propriedades antigas da classe com o mesmo nome: impede um molde de ler objetos de outro site. (#11)
- `temfilho id=` e `usarobjeto` só enxergam objetos do mesmo site e dentro da vigência. (#10, #11)
- O conversor de moldes recusa gravar através de link simbólico e fora da pasta de destino. (#10)
- Moldes são compilados para PHP que só chama o *runtime*: PHP cru é rejeitado, variáveis são isoladas e laços e inclusões têm teto. (#6)

[Não lançado]: https://github.com/publicare/publicare4/commits/main

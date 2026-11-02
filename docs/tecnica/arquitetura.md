# Arquitetura

Visão geral do que existe hoje. Decisões e desenho completo: [ADRs](../adr/) e [fluxos](../fluxos/).

## Do pedido à página

```mermaid
flowchart LR
    V[Visitante] --> I[public/index.php]
    I --> A[Aplicacao]
    A --> S[Servicos: banco instalado]
    A -->|host| H[SiteRepositorio: domínio → site]
    A -->|/_pbl/enviar, /_pbl/enviado| FM[EnvioDeFormulario: formulário público, sem sessão]
    A -->|/_pbl/...| P[Painel: entrar, sair, telas da administração]
    A -->|URL validada| C[Caminhos::pagina]
    C --> F[(dados/cache/paginas/site/url.html)]
    F -->|existe e não venceu| R[Resposta 200]
    F -->|não existe ou venceu| UH[(url_historico)]
    UH -->|endereço antigo de conteúdo no ar| M[301 para o endereço atual]
    UH -->|nenhum| RM[(redirecionamento: regras manuais)]
    RM -->|regra| M2[301/302 para o destino cadastrado]
    RM -->|nenhuma| N[404]
```

A requisição pública **só lê um arquivo pronto**: ela não monta página nem consulta conteúdo. O banco é consultado apenas para descobrir o site pelo domínio.

## Da publicação ao cache

```mermaid
flowchart LR
    P[bin/pbl publicar] --> PU[Publicador]
    PU -->|objetos visíveis do site| OR[ObjetoRepositorio]
    PU --> RE[Renderizador]
    RE --> M[molde da pele: dados/peles/pele/classe.pbl]
    M --> CO[Compilador: Lexer → Parser → Gerador]
    CO --> PHP[(dados/cache/pbl/hash.php)]
    RE --> RT[Runtime + FonteRepositorio]
    RT --> OR
    RE --> HTML[HTML] --> F[(cache de páginas)]
```

`bin/pbl agendados` (cron) usa o mesmo `Publicador`: `pendentes` compara o banco com o cache (visível sem página, ou fora do ar com página) e `aplicarAgendados` republica o site só se houver diferença, o que põe no ar o que a `data_publicacao` liberou e tira o que a `data_validade` venceu.

O molde é compilado **uma vez** para PHP; o hash cobre o molde, a cadeia de layouts e a versão do formato do compilador. O PHP gerado só chama o `Runtime`; nada escrito no molde vira código.

## Menu, busca e desempenho (fase 5l)

O menu **não** consulta o banco por página nem por item. `MenuDoSite` monta a árvore com **uma** consulta por site (objeto + classe, mais um `LEFT JOIN` para o destino dos `link`), filtrada por `no_menu = 1`, publicado e vigente, e a grava em `dados/cache/menu/<site>.json` com o hash SHA-256 da árvore. O comando `<@menu@>` só lê esse arquivo.

```mermaid
flowchart LR
    E[edição, mover, agendamento] --> V[MenuDoSite.verificar: 1 consulta]
    V -->|hash igual| O[republica objeto e pai]
    V -->|hash mudou| S[novo snapshot + pendente + auditoria menu.invalidado]
    S -->|site com até 300 objetos| R[publicarSite na hora]
    S -->|maior| C[bin/pbl publicar ou agendados]
    C --> R
```

- Edições que não entram no menu (corpo, outros campos) não mudam o hash.
- Limites: profundidade 3 por padrão (`PBL_MENU_PROFUNDIDADE`, até 5) e 50 itens por nível; o excedente é contado em `cortes` e a página inicial do painel e `/_pbl/site/{id}/publicacao` avisam do corte e da republicação pendente. `sitemap.xml` (só publicados, vigentes, sem `seo_nao_indexar` nem `link`) e `robots.txt` saem do domínio principal; `no_menu`/`rotulo_menu` entram no histórico de versões (chave `_menu` no JSON).
- `/busca` e `?pagina=N` são páginas dinâmicas (`PaginasDinamicas`), não cacheadas; o resto continua estático. Para tráfego alto, ponha uma CDN na frente.
- Detalhes e decisões: [ADR 0016](../adr/0016-menu-busca-e-paginas-dinamicas.md).

## Configurações do site (fase 5m)

`ConfigDoSite` é o catálogo fechado de chaves (tipo, padrão, validação); `ConfigDoSiteRepositorio` lê e grava a tabela `site_config` (uma linha por site e chave, valor em JSON), valida tudo antes de gravar qualquer coisa e calcula a `versao` (hash das linhas) para a edição otimista. O molde lê `%SITE.config.chave` (`Runtime::configDoSite` → `FonteDeDados`); chave fora do catálogo é recusada no parser. `MenuDoSite::profundidadeDe` usa `menu_profundidade` limitado pelo teto global. Salvar pela tela (`TelasDeConfiguracoes`) audita só os nomes das chaves e chama `Publicador::republicarPorConfiguracao`: até 300 objetos republica na hora; acima disso marca o site como pendente, o mesmo marcador do menu. Decisões: [ADR 0017](../adr/0017-configuracoes-do-site.md).

## Administração pelo navegador

```mermaid
flowchart LR
    N[Navegador] -->|GET/POST /_pbl/objeto/id...| PA[Painel]
    PA -->|sem sessão| E[303 /_pbl/entrar]
    PA -->|sessão válida, /_pbl/| TC[TelasDeConteudo: painel inicial]
    PA -->|sessão válida, /_pbl/objeto/id/...| T[TelasDeObjetos]
    T -->|POST| CS{origem + CSRF}
    CS -->|falhou| F[403]
    CS --> PE{Permissoes::pode<br/>ação fixa da rota}
    T -->|GET| PE
    PE -->|não| F
    PE -->|ver| MC[MotorDeConsulta: filhos com ordem e filtros]
    PE -->|criar, editar, estado,<br/>mover, lixeira, restaurar| OR[ObjetoRepositorio<br/>versão esperada, histórico, auditoria]
    OR -->|ConflitoDeVersao| C409[409, nada sobrescrito]
    OR --> PU[Publicador: regrava ou tira do cache<br/>a página, o pai e o endereço antigo] --> R[303 de volta à tela]
```

As regras de integridade não moram nas telas: `ObjetoRepositorio` recusa (com `ViolacaoDeRegra`) tipo não aceito pelo pai e campo obrigatório vazio, normaliza as datas de vigência para UTC e limpa propriedades `html` com `HtmlSeguro`; as telas só traduzem o erro em mensagem. A tela de mover navega pela árvore com links (`?em=`) e botões de `POST`.

Os tipos de conteúdo (`/_pbl/classes`, `/_pbl/classe/{id}/...`) seguem a mesma receita em `TelasDeClasses`, com `Permissoes::podeModelarClasses` no lugar de `pode` e `ClasseRepositorio` (versão esperada, regra de não apagar valores) no lugar de `ObjetoRepositorio`; veja o [fluxo 12](../fluxos/12-administracao-web.md).

Contas (`/_pbl/contas`, `/_pbl/conta/{id}/...`) ficam em `TelasDeContas` e domínios (`/_pbl/site/{id}/dominios/...`) em `TelasDeDominios`, com a mesma receita: `Permissoes::podeAdministrarContas` e `Permissoes::podeAdministrarSite` (ação `Administrar` na raiz do site) no lugar de `pode`, e os repositórios que a linha de comando já usa (`UsuarioRepositorio`, `DominioRepositorio`). As peças comuns (origem e CSRF, barra, leitura de texto) estão no trait `AuxiliaresDeTela`.

Redirecionamentos, peles e a opção de busca (`/_pbl/site/{id}/...`) de um site ficam em `TelasDeSite`, com `Permissoes::pode(Acao::Administrar)` na seção raiz; os moldes são lidos e gravados só por `GerenciadorDePeles` (nomes validados, sem links simbólicos, gravação atômica, validação pelo parser antes de salvar) e o site é republicado pelo `Publicador` depois. A tela de busca só liga/desliga `Site::$buscaTolerancia` (`SiteRepositorio::definirBuscaTolerancia`), sem tocar em pele nem em moldes.

`GerenciadorDePeles` também guarda o **histórico** de cada molde (`pele_molde_versao`, uma linha por gravação ou remoção, com retenção) e um **rascunho** por molde (`pele_molde_rascunho`, não grava o arquivo nem gera versão). "Voltar a esta versão" passa pelas mesmas verificações de uma gravação (parser, modo elevado, edição simultânea) e grava uma versão nova, nunca reescrevendo as antigas (mesma lógica de `ObjetoRepositorio::restaurar`). Publicar um rascunho é uma gravação comum seguida de apagar a linha do rascunho. A **pré-visualização** de um rascunho usa `Renderizador::renderizar` direto (com um `ResolvedorDePele` que troca, só em memória, a fonte do molde em edição), nunca o `Publicador`: a resposta é a página renderizada com cabeçalhos de isolamento (`no-store`, `X-Robots-Tag: noindex`, CSP própria), sem gravar `dados/cache/paginas`. O **realce de sintaxe** só leitura (histórico, diferença, visualização) é `Pbl\Linguagem\Realce`, que usa o `Lexer` e sempre escapa o HTML gerado. Nada disto usa JavaScript (fase 5j, [ADR 0012](../adr/0012-administracao-web.md) §14).

As telas são HTML montado no servidor, sem JavaScript, com o CSS do Bootstrap servido do próprio site (`public/_pbl/assets/`). Arquivo existente em `public/` é entregue direto pelo servidor web; o resto de `/_pbl/` vai para o `Painel`. Detalhes no [ADR 0012](../adr/0012-administracao-web.md) e no [fluxo 12](../fluxos/12-administracao-web.md).

## Modelo de dados

| Tabela | Papel |
|---|---|
| `site`, `dominio` | sites e os domínios que levam a eles (vários domínios para um site) |
| `classe` | tipos de conteúdo (valem para a instalação toda): propriedades em JSON (com rótulo, obrigatório e arquivada opcionais), descrição, filhos aceitos e versão |
| `objeto` | os conteúdos: dados em JSON, `caminho` materializado (`/1/45/230/`), metadados (`peso`, `descricao`, vigência), `titulo_norm` |
| `objeto_indice` | valores tipados só das propriedades marcadas como **indexáveis** (as `ref` sempre); é o que permite filtrar e ordenar, inclusive pelo campo de um objeto apontado, com `JOIN`s e sem tabela temporária |
| `objeto_versao` | histórico: uma linha por estado salvo de cada objeto (a atual inclusive), com o motivo |
| `url_historico` | endereço antigo → objeto; a `Aplicacao` responde 301 para o endereço atual |
| `redirecionamento` | regras manuais por site (exatas ou por prefixo, 301/302) |
| `dominio` | host → site, com `modo` (`principal`, `servir`, `redirecionar`) |
| `usuario`, `acesso`, `auditoria` | equipe, perfil de um usuário numa subárvore e o registro de ações (só acrescenta) |
| `sessao`, `tentativa_login` | sessões da administração (só o hash do token) e falhas de login (só hashes) |
| `arquivo` | metadados de cada arquivo enviado (propriedade `arquivo`/`imagem`): dono (`objeto_id`), chave em disco, tipo, tamanho, sha256; o conteúdo mora em `dados/uploads/` (fluxo 7, ADR 0014) |
| `schema_versao` | migrações já aplicadas |

Sem DDL em produção: só o migrador cria ou altera tabelas; o usuário do banco em uso normal só precisa de `SELECT/INSERT/UPDATE/DELETE` ([ADR 0003](../adr/0003-plataforma-low-code.md)). A subárvore de um objeto sai do prefixo do `caminho`, o que também limita cada consulta ao seu site.

## Camadas

| Camada | Namespace | Responsabilidade |
|---|---|---|
| Entrada | `Pbl\Aplicacao`, `public/index.php` | resolver host e URL, devolver arquivo do cache |
| Conteúdo | `Pbl\Conteudo` | classes, objetos, sites; validação dos dados por tipo (`texto`, `numero`, `html`, `data`, `booleano`, `ref`); índice |
| Instalação | `Pbl\Instalacao` | `Instalador` (atômico), `InstaladorWeb` (assistente em `/_pbl/instalar`, só antes de instalar), `Requisitos` |
| Formulários públicos | `Pbl\Formularios` | `FormularioRepositorio` (lê a definição e falha fechada), `FormularioHtml` (HTML escapado), `FormularioParaMolde` (ponte do comando `formulario`), `EnvioDeFormulario` (rotas `/_pbl/enviar/{id}` e `/_pbl/enviado/{id}`: limite, tamanho, origem, token, armadilha, validação e criação do objeto privado); `Acesso\LimiteDeEnvios` e `Csrf::paraFormulario` ([ADR 0013](../adr/0013-formularios-publicos.md)) |
| Administração | `Pbl\Admin` | `Painel`: rotas sob `/_pbl/` (entrar, sair) com sessão, CSRF, limite de tentativas e cabeçalhos de segurança; `TelasDeConteudo`: painel inicial com os sites; `TelasDeObjetos`: um conteúdo e seus filhos (ordem e filtros), criar, editar todas as propriedades (inclusive upload nas propriedades `arquivo`/`imagem`), estado e datas, mover, lixeira e histórico ([ADR 0012](../adr/0012-administracao-web.md)); `TelasDeClasses`: tipos de conteúdo (só administrador; campo com valores é arquivado, nunca apagado); `TelasDeContas`: contas, senhas e papéis por seção (só administrador; última conta de administrador protegida); `TelasDeDominios`: domínios do site (alias, redirecionador, principal); `Pagina`: HTML com Bootstrap local, escape e cabeçalhos |
| Arquivos | `Pbl\Arquivos` | Upload e entrega de arquivo numa propriedade `arquivo`/`imagem` ([ADR 0014](../adr/0014-upload-de-arquivos.md)): `TiposDeArquivo` (whitelist fechada), `ServicoDeUpload` (valida e grava, com `finfo` e recodificação por GD), `ArmazenamentoDeArquivos` (caminho em disco, gravação atômica), `ArquivoRepositorio` (metadados), `ArquivoController` (rota pública de entrega, como `EnvioDeFormulario`), `ServicoDeMiniaturas` (miniaturas sob demanda, cache em disco), `LimpadorDeUploads` (`bin/pbl limpar-uploads`) |
| Acesso | `Pbl\Acesso` | contas, senhas (argon2id), perfis por subárvore, `Permissoes::pode` (único ponto de decisão) e auditoria |
| Busca | `Pbl\Busca` | normalização de texto (minúsculas e sem acento) na gravação e na consulta (`Texto`); busca por palavras: `Termos` (palavras, plural), `BuscaInterface` e o driver padrão `BuscaSql` sobre a tabela `objeto_termo`, alimentada por `IndexadorDeObjeto` a cada criação/alteração; o `MotorDeConsulta` encaixa a condição e a relevância na consulta única; `Visibilidade` centraliza a condição de conteúdo público, usada pela busca e pela sugestão de grafia parecida (`BuscaSql::sugerir`, nível C, opcional por site via `Site::$buscaTolerancia`, exposta ao molde como `%SUGESTAO`) ([ADR 0010](../adr/0010-busca.md)) |
| Consulta | `Pbl\Consulta` | `Consulta` (o pedido) e `MotorDeConsulta` (a execução): filtros, ordem, referências, contagem, carga em lote. Único caminho para ler conteúdo em lista |
| Linguagem | `Pbl\Linguagem` | compilar e executar moldes (PBL4) em ambiente restrito |
| Publicação | `Pbl\Publicacao` | renderizar, gravar e limpar o cache; ligar o runtime ao banco |
| Conversão | `Pbl\Conversao` | migrar moldes do PBL3 |
| Infraestrutura | `Pbl\Banco`, `Pbl\Instalacao`, `Pbl\Console` | conexão e migrações, instalador, comandos |

O `Runtime` não conhece o banco: usa a interface `FonteDeDados`, implementada por `FonteRepositorio`, que monta `Consulta`s para o `MotorDeConsulta`. Isso mantém a linguagem testável e permite outras fontes (por exemplo, a API de conteúdo prevista no [ADR 0009](../adr/0009-peles-de-saida-headless.md)).

## Pastas do site (`dados/`)

```
dados/
  config/     configuração e a trava de instalação
  cache/      paginas/ (HTML pronto) e pbl/ (PHP compilado)
  peles/      moldes de cada pele (a pele básica é copiada na instalação)
  uploads/    arquivos enviados (propriedade `arquivo`/`imagem`, fluxo 7): nome gerado, nunca o do cliente
  pbl4.sqlite banco SQLite padrão
```

## O que ainda não existe

Parte das telas da administração (regras de redirecionamento e peles: hoje por linha de comando). Upload e entrega de arquivo, upload na criação e comandos de molde (`<@arquivo@>`/`<@miniatura@>`) estão feitos (fluxo 7, ADR 0014). Estão no [plano](../plano.md).

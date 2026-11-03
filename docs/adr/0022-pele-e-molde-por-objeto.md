# ADR 0022 — Pele e molde por objeto, peles com JavaScript opcional e barra de edição

Status: aceito (2026-11-02). Estende o [ADR 0012](0012-administracao-web.md) (JavaScript opcional por pele), o [ADR 0016](0016-menu-busca-e-paginas-dinamicas.md) (menu) e o [ADR 0021](0021-modelos-opcionais.md) (pele básica).

## Contexto

Hoje a pele é do **site** inteiro e o molde é escolhido só pelo nome da classe. Um mesmo domínio não consegue ter, por exemplo, uma página única (onepage) e um portal com várias páginas, nem uma home diferente das páginas internas. Além disso, quem está logado no painel e navega pelo site publicado não tem atalho para editar ou publicar o que está vendo.

## Decisão

1. **Colunas `pele` e `molde` em `objeto`** (nulas = herdar; migração aditiva `M018`). Não entram no histórico de versões (são aparência, não conteúdo).
2. **Pele efetiva** = pele do ancestral mais próximo que tenha uma (inclusive o próprio objeto); sem nenhuma, a do site. O objeto onde a pele começa é a **raiz da pele**.
3. **Molde efetivo**, nesta ordem: molde do objeto (se existir na pele efetiva); `home`, se o objeto é a raiz do site e a pele tem `home`; molde com o nome da classe; `padrao`.
4. **Menu e migalhas** partem da raiz da pele: o menu de uma pele não lista seções que têm pele própria diferente (o Portal não mostra o Onepage e vice-versa). O snapshot do menu guarda uma subárvore por raiz de pele.
5. **Assets de pele** (CSS/JS/imagens, `dados/peles/<pele>/assets/`) são servidos pelo PHP em `/_pbl/peles/<pele>/<arquivo>` (lista de extensões, sem `..`, `nosniff`, cache longo com `?v=hash`), então cada pele tem seu caminho e o cache não se mistura. Os moldes usam a variável `#pele_url`.
6. **Trocar pele ou molde republica a subárvore** do objeto (síncrono até `LIMITE_SINCRONO`; acima disso a subárvore fica pendente para `bin/pbl publicar`/agendados).
7. **Edição:** no card "Publicação", selects "Pele" e "Aparência (molde)" com "herdar" como padrão, só para quem pode publicar, validados contra as peles e moldes existentes.
8. **JavaScript próprio em todas as peles** (revisto em 2026-11; antes a básica era sem JS, ver o adendo do [ADR 0012](0012-administracao-web.md)). Sempre local (mesmo domínio, `?v=hash`, sem inline, sem manipulador no HTML, sem CDN) e como melhoria progressiva: sem JS a página segue legível e navegável. Só entra JS quando a pele o declara no molde.
9. **Barra de edição nas páginas públicas.** As páginas continuam estáticas. O molde base de cada pele inclui `data-pbl-objeto` no `body` e o script próprio `pbl-barra.js` (`?v=hash`, `script-src 'self'`). O script só age se existir o cookie indicador `pbl4_ind` (não-HttpOnly, valor fixo, sem dados; a sessão real segue HttpOnly) e então consulta `GET /_pbl/api/acoes?objetos=1,2,3` (uma chamada para a página toda, até 100 ids; `?objeto=ID` continua valendo), autenticado pela sessão: sem sessão, 401; com sessão, JSON com **só** as ações que a conta pode fazer naquele objeto (`Permissoes::pode`) e um token CSRF. As ações aparecem **ao lado do título de cada item listado**: o molde marca o título com `data-pbl-objeto="ID"` e deixa um `<span class="pbl-acoes" hidden></span>` vazio, que o script preenche com botões de ícone (SVG criado por DOM; `aria-label` e `title`); o `body` continua marcando o objeto da página, com as ações junto do `<h1>`. Objeto inexistente, apagado ou fora do que a conta pode ver simplesmente não aparece na resposta. Editar e excluir abrem as telas do painel; publicar/despublicar são `POST /_pbl/api/estado` com a `versao` lida, CSRF e conferência de origem, e republicam (o botão troca de estado sem recarregar a página). A página estática nunca contém dados da sessão, e a checagem de permissão é sempre do servidor.
10. **"Gravar e publicar"** ao lado de "Gravar" na edição do objeto, para quem pode publicar; um único envio grava e define o estado como publicado.
11. **Peles distribuídas** (`basica`, `onepage`, `portal`) são instaláveis e copiadas por inteiro (moldes e assets). Em instalações existentes só são atualizadas se o arquivo for byte a byte uma versão distribuída (SHA-256), como no ADR 0021 §4.

## Consequências

- Menu e migalhas ficam mais caros de montar (uma subárvore por raiz de pele), mas continuam em snapshot.
- Mudar o molde de uma pasta com milhares de filhos deixa páginas pendentes até a próxima publicação em lote.
- A barra faz uma chamada a mais por página, só para quem tem o cookie indicador.
- Fora de escopo: editor visual de peles, assets de pele enviados pelo painel, versionamento de pele/molde por objeto.

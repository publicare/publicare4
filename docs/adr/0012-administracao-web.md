# ADR 0012 — Administração pelo navegador

Status: aceito e implementado em parte (2026-11-01, fase 5a: painel com os sites, navegação na árvore e edição de título e descrição). Ficam para as próximas fatias: criar, mover, mudar estado e apagar conteúdo, editar as propriedades da classe, histórico de versões na tela, contas, domínios e peles. Complementa o [ADR 0011](0011-autenticacao-sessao-permissoes.md) (sessão, CSRF e permissões) e detalha a parte de administração do [fluxo 5](../fluxos/05-conteudo-workflow.md).

## Contexto

Até a fase 4 o conteúdo só se gerencia pela linha de comando e por código. A administração precisa existir sem repetir os erros do PBL3: ação decidida por texto da requisição, ausência de CSRF e telas que só **escondiam** o botão de quem não podia agir. O produto roda em hospedagem comum, então a administração não pode depender de etapa de build, de JavaScript obrigatório nem de serviço extra.

## Decisão

### 1. Onde mora
- Todas as rotas ficam sob **`/_pbl/`**, o prefixo reservado do ADR 0011 (o `_` nunca é válido em URL de conteúdo).
- Rotas desta fatia:

| Método e rota | O que faz | Ação exigida |
|---|---|---|
| `GET /_pbl/` | painel inicial: sites e seções que a pessoa pode ver | sessão |
| `GET /_pbl/objeto/{id}` | um conteúdo e a lista dos filhos (só leitura, paginada) | `Acao::Ver` no objeto |
| `GET /_pbl/objeto/{id}/editar` | formulário de título e descrição | `Acao::Editar` no objeto |
| `POST /_pbl/objeto/{id}/editar` | grava título e descrição | CSRF + origem + `Acao::Editar` |

- O `{id}` é validado por expressão sem ambiguidade (`[1-9][0-9]{0,9}`, ancorada) antes de virar inteiro.

### 2. HTML no servidor, sem JavaScript
- Cada tela é HTML montado em PHP (`Pbl\Admin\Pagina`), com formulários comuns (`GET` para ler, `POST` para gravar) e o padrão **POST → 303 → GET** depois de salvar.
- **Toda saída é escapada** (`Pagina::h`, `htmlspecialchars` com `ENT_QUOTES | ENT_SUBSTITUTE`). Título, descrição, nome de classe, de site e de pessoa nunca vão crus para a página.
- Todas as respostas passam por `Pagina::protegida()`: `no-store`, CSP `default-src 'none'; style-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'`, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, HSTS em HTTPS. **Nenhum estilo nem script inline**; sem JavaScript, a CSP não libera `script-src`. O `img-src data:` existe só porque o CSS do Bootstrap desenha alguns ícones de formulário como SVG embutido.
- HTML semântico: atalho "pular para o conteúdo", navegação em `<nav>` dentro de `<header>`, conteúdo em `<main>`, `<label for>` em todo campo, `<th scope>` nas tabelas, alertas com `role="alert"`/`role="status"`.

### 2.1. Visual: Bootstrap 5 local (decidido)
- **Bootstrap 5.3.8** (a última 5.3.x estável), **só o CSS**, servido do próprio site em `public/_pbl/assets/bootstrap-5.3.8/`. Sem CDN (a administração não depende de terceiros no ar nem vaza a visita), sem etapa de build e sem Node: o arquivo é copiado sem alteração da release oficial do npm.
- Procedência e integridade: versão, origem e SHA-256 em `public/_pbl/assets/PROVENIENCIA.md`, conferidos por teste (`tests/AssetsAdminTest.php`). Licença MIT do Bootstrap ao lado do arquivo e listada em [Licenças de terceiros](../tecnica/terceiros.md).
- O `bootstrap.bundle.js` **não** é usado: nenhuma tela desta fatia precisa de componente com JavaScript (a barra de navegação não recolhe). Se um dia for necessário, entra pelo mesmo caminho e a CSP ganha `script-src 'self'`, nunca `unsafe-inline`.
- Ajustes próprios num único arquivo, `public/_pbl/assets/pbl.css` (sem `@import` nem `url()` de fora).
- Os arquivos ficam **dentro** do prefixo reservado `/_pbl/`, mas são arquivos de verdade em `public/`: o Apache (`RewriteCond %{REQUEST_FILENAME} !-f`), o nginx de referência (`try_files $uri`) e o servidor embutido do PHP (em desenvolvimento) os entregam direto, sem passar pelo `index.php`. O que não existe cai no `Painel` e recebe 404, como qualquer rota desconhecida da administração. Trocar de versão é criar uma pasta nova (`bootstrap-5.3.9/`), o que também resolve cache de navegador.

### 3. Permissão em toda rota
- Cada rota escolhe a ação no código (`Acao::Ver`, `Acao::Editar`…) e chama **`Permissoes::pode`** antes de ler ou gravar. Esconder o botão é só conforto: a rota recusa sozinha.
- Sem sessão: `303` para `/_pbl/entrar` (também no POST, que não grava nada).
- Sem permissão **ou objeto inexistente**: a mesma resposta `403`, para não revelar quais ids existem a quem não pode vê-los.
- A lista de filhos não verifica permissão por filho: o perfil vale para a subárvore inteira (ADR 0011), então quem vê o pai vê os filhos. O caminho de navegação (migalhas) mostra só os ancestrais que a pessoa pode ver.
- O painel inicial mostra ao administrador global todos os sites (entrando pela raiz) e aos demais só as seções onde receberam um papel (e não as que já estão dentro de outra seção dele).

### 4. Gravação
- Todo POST confere, **antes de qualquer outra coisa**, a origem (`Rede::origemConfere`) e o token CSRF da sessão (`Csrf::conferirSessao`). Falhou: `403` e nada é gravado.
- Os campos chegam como texto (valor não textual é recusado), em UTF-8 válido, sem caracteres de controle (a descrição aceita quebra de linha), com teto de tamanho (título 300, descrição 2000) e título obrigatório. Erro de validação: `422` com o formulário de novo.
- A gravação usa **só** `ObjetoRepositorio::atualizar` (sem SQL novo), com a **versão esperada** que veio no formulário. Se outra pessoa salvou antes, `ConflitoDeVersao` vira `409` com o formulário mostrando o valor atual e o texto que a pessoa tentou salvar; salvar de novo é uma decisão consciente.
- Se nada mudou, nada é gravado (nenhuma versão nova).
- Depois de salvar, o `Publicador` regrava a página do objeto e a do pai (onde o título costuma aparecer em listas), se estiverem visíveis. Falha ao republicar não desfaz a edição: a tela avisa para publicar o site de novo. Outras páginas que citam o título (menus em outras seções) só mudam na próxima publicação do site.

### 5. Auditoria
- `ObjetoRepositorio::atualizar` já registra `objeto.atualizar` com **os nomes** dos campos alterados e a versão, nunca o conteúdo. A administração passa um motivo fixo (`administracao`), nunca texto digitado.
- Tentativa de editar sem permissão (com CSRF válido) registra `objeto.editar.negado` com o id do objeto, sem conteúdo.

## Consequências

- Cada nova tela segue a mesma receita: rota fixa, sessão, ação do enum, `Permissoes::pode`, CSRF e origem no POST, escape, auditoria sem conteúdo, classes do Bootstrap sem estilo inline, teste de acesso sem login, sem permissão, CSRF e XSS.
- Atualizar o Bootstrap é tarefa de manutenção: baixar a nova versão, conferir o `integrity` do npm, registrar o SHA-256 e trocar `Pagina::BOOTSTRAP`.
- Sem JavaScript, a interface é simples e segue o visual padrão do Bootstrap; melhorias de conforto (pré-visualização, arrastar para mover) poderão vir depois como camada opcional, sem tirar as checagens do servidor.
- A API de conteúdo (ADR 0009) poderá reutilizar as mesmas decisões de permissão; esta administração não depende dela.

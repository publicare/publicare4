# ADR 0012 — Administração pelo navegador

Status: aceito e implementado em parte (2026-11-01, fase 5a: painel com os sites, navegação na árvore e edição de título e descrição; fase 5c: tipos de conteúdo, §6; fase 5b: criar, editar todas as propriedades, estado e datas, mover, lixeira, histórico com restaurar e lista com ordem e filtros, §7). fase 5d: contas e domínios, §8). Ficam para as próximas fatias: redirecionamentos e peles. Complementa o [ADR 0011](0011-autenticacao-sessao-permissoes.md) (sessão, CSRF e permissões) e detalha a parte de administração do [fluxo 5](../fluxos/05-conteudo-workflow.md).

## Contexto

Até a fase 4 o conteúdo só se gerencia pela linha de comando e por código. A administração precisa existir sem repetir os erros do PBL3: ação decidida por texto da requisição, ausência de CSRF e telas que só **escondiam** o botão de quem não podia agir. O produto roda em hospedagem comum, então a administração não pode depender de etapa de build, de JavaScript obrigatório nem de serviço extra.

## Decisão

### 1. Onde mora
- Todas as rotas ficam sob **`/_pbl/`**, o prefixo reservado do ADR 0011 (o `_` nunca é válido em URL de conteúdo).
- Rotas desta fatia:

| Método e rota | O que faz | Ação exigida |
|---|---|---|
| `GET /_pbl/` | painel inicial: sites e seções que a pessoa pode ver | sessão |
| `GET /_pbl/objeto/{id}` | um conteúdo, as ações que a pessoa pode fazer e a lista dos filhos (paginada, com ordem e filtros por classe e estado) | `Acao::Ver` no objeto |
| `GET`/`POST /_pbl/objeto/{id}/editar` | título, descrição, endereço, peso e as propriedades da classe (5a: só título e descrição) | `Acao::Editar` no objeto |
| `GET`/`POST /_pbl/objeto/{id}/novo` | escolher a classe (`?classe=`) e criar um filho | `Permissoes::podeCriarSob` (`Acao::Criar` no pai) |
| `GET`/`POST /_pbl/objeto/{id}/estado` | estado e datas de publicação e de validade | `Acao::Publicar` |
| `GET`/`POST /_pbl/objeto/{id}/mover` | mudar de pai, com a subárvore | `Acao::Apagar` no objeto + `Acao::Criar` no destino |
| `GET`/`POST /_pbl/objeto/{id}/apagar` | confirmação e envio para a lixeira | `Acao::Apagar` |
| `POST /_pbl/objeto/{id}/recuperar` | tirar da lixeira | `Acao::Apagar` |
| `GET /_pbl/objeto/{id}/versoes` | histórico de versões | `Acao::Ver` |
| `POST /_pbl/objeto/{id}/restaurar` | restaurar uma versão | `Acao::Editar` |
| `GET /_pbl/classes` | lista dos tipos de conteúdo (contagem de uso só para quem modela) | sessão |
| `GET`/`POST /_pbl/classes/nova` | criar tipo de conteúdo | `Permissoes::podeModelarClasses` (+ CSRF + origem no POST) |
| `GET`/`POST /_pbl/classe/{id}/editar` | descrição, filhos aceitos e campos | idem |
| `GET`/`POST /_pbl/classe/{id}/apagar` | confirmar e apagar tipo sem uso | idem |

- Todo POST exige CSRF + origem antes de qualquer outra coisa.
- O `{id}` é validado por expressão sem ambiguidade (`[1-9][0-9]{0,9}`, ancorada) antes de virar inteiro; em `/_pbl/objeto/{id}/…` a sub-rota precisa estar numa lista fixa para aquele método (`TelasDeObjetos::atende`), conferida **antes** da sessão: o resto é 404 para todos.

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

### 6. Tipos de conteúdo (fase 5c)
- **Quem**: classes valem para a instalação inteira e mudam o que todas as seções podem guardar, por isso só o administrador global modela tipos (`Permissoes::podeModelarClasses`, novo, conferido em toda rota menos a lista). Os demais veem só a lista, sem a contagem de uso (que cruza sites). Para quem não modela, tipo inexistente e sem permissão dão o mesmo 403.
- **O que se guarda**: na classe, `descricao`, `aceita_filhos` e `filhas` (nomes; vazia = qualquer tipo) e `versao` (migração 9, só colunas novas com padrão que mantém o comportamento de hoje); em cada propriedade, além de `nome`, `tipo`, `indexavel` e `classe`, os opcionais `rotulo`, `obrigatorio` e `arquivada`, gravados só quando têm valor (o JSON das classes antigas não muda). A ordem dos campos é a ordem da lista. Não existe valor padrão no modelo, então a tela não oferece.
- **Nome técnico não muda** (nem da classe nem do campo): é o que moldes, referências e listas de filhas usam.
- **Nenhum dado se perde**: o uso de cada campo é contado em PHP sobre o JSON dos objetos e das versões (igual em qualquer banco). Campo com valor, hoje ou em versão antiga, não pode ser removido nem mudar de tipo, classe apontada ou indexação: a tela recusa (422) explicando e oferece **arquivar**. Arquivar um campo com valores hoje passa por uma tela de confirmação com a contagem; o valor continua no objeto, a validação continua aceitando, os moldes continuam lendo e restaurar versões antigas continua funcionando. O `ClasseRepositorio::atualizar` repete a regra, então nem um formulário forjado nem outro chamador conseguem destruir dados.
- **Apagar** só sem nenhum objeto (em qualquer estado, inclusive `apagado`) e sem outra classe que dependa dela (referência restrita a ela ou lista de filhas); o bloqueio é explicado na tela e repetido no repositório.
- **Edição simultânea**: `UPDATE ... WHERE versao = ?`; versão diferente dá 409 sem gravar e mostra o que está salvo (o que a pessoa digitou não é reaproveitado, porque pode não caber mais na classe atual).
- **Auditoria**: `classe.criar`, `classe.atualizar` (nomes do que mudou, como `propriedade.resumo.rotulo`, `ordem`, `descricao`), `classe.apagar` e `classe.negado`; nunca o texto de rótulos ou descrições.
- **Fora desta fatia**: exigir `obrigatorio` e `filhas` ao salvar ou mover um objeto (feito nas telas de objetos, fase 5b, §7; o `ObjetoRepositorio` ainda não repete a regra); mudar a indexação de um campo com valores (precisa reindexar).

### 7. Conteúdo (fase 5b)
- **Arquivo próprio**: as telas de um conteúdo ficam em `TelasDeObjetos`; `TelasDeConteudo` ficou só com o painel inicial. O `Painel` só registra as rotas.
- **Lista de filhos**: ordens fixas (peso e título; título; alterados por último; data de publicação), filtro por classe (só ids de classes existentes) e por estado; sem filtro, a lixeira fica de fora. Valor desconhecido na URL é ignorado. Nada da URL chega ao SQL: as ordens e filtros vão como `Consulta` para o `MotorDeConsulta`, que passou a aceitar `status` como coluna.
- **Criar**: a pessoa escolhe a classe entre as que o tipo do pai aceita (`aceita_filhos` e `filhas` da fase 5c, §6; conferido também no POST, 422 se forjado) e preenche título, descrição, endereço (vazio = gerado do título, com `-2`, `-3`… se já usado), peso e as propriedades. O conteúdo **nasce sempre `privado`**, com `criado_por` = a pessoa; ir ao ar é a tela de estado, com outra permissão.
- **Campos por tipo** (os de `ClasseRepositorio::TIPOS`, nenhum inventado): `texto` (área de texto, até 10 000), `html` (área de texto, até 200 000, guardado como veio e mostrado **como texto** na administração), `numero` (inteiro ou decimal, aceita vírgula), `data` (`aaaa-mm-dd`, com hora opcional), `booleano` (Sim / Não / sem valor) e `ref` (número de outro conteúdo **do mesmo site que a pessoa pode ver**, da classe exigida, se houver; a mesma mensagem para "não existe", "outro site" e "sem permissão"; uma referência já gravada continua valendo ao salvar outros campos). Campo vazio = sem valor (a propriedade sai dos dados), a não ser que seja **obrigatório** (422). O rótulo vem da classe (`Classe::rotuloDe`). Campo **arquivado** não aparece no formulário e não é tocado na gravação, mesmo que um formulário forjado o envie: o valor guardado continua. Na edição, campo que nem veio no pedido mantém o valor atual. Toda validação acontece no servidor, com a mensagem ao lado do campo (`aria-invalid`, `aria-describedby`); o repositório valida de novo.
- **Endereço**: só letras minúsculas, números, hífen e `/`; recusado se já é de outro conteúdo ou se hoje redireciona para outro (`urlDisponivel`, sem "liberar de propósito" pela tela). A página inicial do site não troca de endereço. Ao trocar, a página no endereço antigo sai do cache na hora e o antigo passa a responder 301.
- **Estado e datas** (`Acao::Publicar`): `privado`, `submetido`, `rejeitado` ou `publicado` (o fluxo de submeter e revisar, com fila e avisos, fica para a fase 6), mais publicação e validade digitadas em **horário de Brasília** e gravadas em UTC (ISO 8601). Publicar regrava a página; tirar do ar (ou validade vencida) tira a página do cache. Publicação com data futura avisa que a página só entra no ar na próxima publicação do site depois da data.
- **Mover** (`Acao::Apagar` no objeto e `Acao::Criar` no destino): destino pelo número, no mesmo site, fora da lixeira, fora da própria subárvore e de um tipo que aceite o tipo do objeto; a página inicial não se move; versão esperada também aqui. O endereço não muda; o objeto, o pai antigo e o novo são regravados.
- **Lixeira** (`Acao::Apagar`): o repositório não tem apagar definitivo, então "apagar" é o estado `apagado`, com tela de confirmação. A página sai do cache na hora. Não vai para a lixeira a página inicial nem quem tem filhos fora da lixeira (é preciso mover ou mandar antes). Na lixeira, o conteúdo não se edita, não muda de estado, não se move e não recebe filhos; **tirar da lixeira** o devolve como `privado` (e exige que o pai não esteja na lixeira). A auditoria registra a troca de estado (`objeto.estado`, de/para).
- **Corrida com a lixeira**: a regra "nada fora da lixeira dentro de algo na lixeira" é conferida **dentro da transação** que grava (`ObjetoRepositorio::criarDentro`, `mandarParaLixeira`, `tirarDaLixeira` e `mover(..., destinoForaDaLixeira: true)`), com a linha do pai travada por um `UPDATE` que não muda nada (bloqueio de linha no MySQL/PostgreSQL, de escrita no SQLite), então criar, mover, tirar da lixeira e mandar o pai para a lixeira ficam um depois do outro (`ConflitoDeArvore` → 409). Como defesa em profundidade, a tela reconfere depois de gravar e, se a regra quebrou mesmo assim, desfaz (o filho criado vai para a lixeira, o objeto movido volta, o estado anterior volta) e responde 409.
- **Histórico**: lista as versões (até as 200 mais novas) com quando (Brasília), quem, título, estado e motivo; restaurar (`Acao::Editar`) usa `ObjetoRepositorio::restaurar` com versão esperada e **sem mexer no estado nem nas datas** (restaurar o texto não pode publicar nem tirar do ar: isso é `Acao::Publicar`). Se o endereço daquela versão já é de outro conteúdo, ou os campos da classe mudaram, a tela explica e nada muda.
- **Edição simultânea**: `ConflitoDeVersao` vira 409 em editar (com o que a pessoa digitou e o que está salvo), estado, mover, lixeira e restaurar; nada é sobrescrito.
- **Auditoria**: tentativas sem permissão com CSRF válido registram `objeto.criar.negado`, `objeto.editar.negado`, `objeto.estado.negado`, `objeto.mover.negado`, `objeto.apagar.negado`, `objeto.recuperar.negado` e `objeto.restaurar.negado`, só com ids. O que é gravado continua registrado pelo repositório só com nomes de campo e motivo fixo.

```mermaid
stateDiagram-v2
    [*] --> privado: criar (Criar no pai)
    privado --> publicado: estado (Publicar)
    publicado --> privado: estado (Publicar)
    privado --> submetido: estado (Publicar)
    submetido --> rejeitado: estado (Publicar)
    rejeitado --> privado: estado (Publicar)
    privado --> apagado: lixeira (Apagar, sem filhos vivos)
    publicado --> apagado: lixeira (Apagar)
    apagado --> privado: tirar da lixeira (Apagar)
```

(Qualquer estado da lista vai para qualquer outro pela tela de estado; o diagrama mostra os caminhos usuais.)

### 8. Contas e domínios (fase 5d)
- **Rotas** (arquivos `TelasDeContas` e `TelasDeDominios`; o `Painel` só as registra; peças comuns no trait `AuxiliaresDeTela`):

| Método e rota | O que faz | Quem |
|---|---|---|
| `GET /_pbl/contas` | lista de contas | `Permissoes::podeAdministrarContas` |
| `GET`/`POST /_pbl/contas/nova` | criar conta (login, nome, e-mail, senha duas vezes, marca de administrador) | idem |
| `GET /_pbl/conta/{id}` | a conta: dados, senha, situação e papéis | idem |
| `POST /_pbl/conta/{id}/editar` | nome, e-mail e marca de administrador | idem |
| `POST /_pbl/conta/{id}/senha` | redefinir senha | idem |
| `POST /_pbl/conta/{id}/desativar` e `/reativar` | situação | idem |
| `POST /_pbl/conta/{id}/conceder` e `/revogar` | papel (leitor, autor, editor) numa seção, pelo número | idem |
| `GET /_pbl/site/{id}/dominios` | domínios do site | `Permissoes::podeAdministrarSite` |
| `POST /_pbl/site/{id}/dominios/adicionar`, `/principal`, `/modo`, `/remover` | alias ou redirecionador novo; trocar o principal; alias ⇄ redirecionador; remover | idem |

- **Permissão**: contas valem para a instalação inteira, então só o administrador global (`podeAdministrarContas`, novo). Domínios exigem `Acao::Administrar` na página inicial do site (`podeAdministrarSite`, novo; nenhum perfil por seção a inclui, então hoje é o administrador global; site sem página inicial: idem). Sem permissão e inexistente dão a mesma 403; o POST tem origem + CSRF antes de tudo e registra `conta.negado` ou `dominio.negado` só com ids.
- **Sem lógica duplicada**: tudo passa pelos repositórios que a linha de comando usa. Novos nesta fatia: `UsuarioRepositorio::atualizar` (nome e e-mail, audita só os nomes dos campos), `definirAdministrador`, `ehUltimoAdministradorAtivo` e `DominioRepositorio::definirModo`.
- **Regras**: ninguém desativa nem tira a marca de administrador de si mesmo (na própria conta a caixa nem aparece e o servidor ignora o campo); a última conta de administrador ativa não é desativada nem rebaixada (repetido no `UsuarioRepositorio::definirAdministrador`; `definirAtivo` continua sem a trava para não mudar o comportamento da linha de comando, a tela confere antes). Redefinir a senha usa `definirSenha` (política de 10 a 1024, custo fixo do hash) e encerra as sessões da conta; na própria conta, a pessoa vai ao login. A senha é digitada duas vezes, não volta ao formulário e não entra na auditoria.
- **Papéis**: a seção é escolhida pelo número do conteúdo (como em mover); conceder substitui o papel que a conta já tinha naquela seção; seção inexistente é 422. Administradores já podem tudo, e a tela avisa que os papéis deles só valem se a marca sair.
- **Domínios**: o site sempre tem um principal. Adicionar só como alias (`servir`) ou redirecionador (`redirecionar`); trocar o principal escolhe o que o anterior passa a ser; o principal não se remove nem muda de modo; domínio de outro site não se mexe por esta tela; o endereço pelo qual a pessoa está acessando agora não se remove (um domínio `redirecionar` nunca abre a administração, pois a `Aplicacao` redireciona antes). DNS e certificado do domínio são feitos fora do PBL4.
- **Auditoria**: `usuario.criar`, `usuario.atualizar` (nomes dos campos), `usuario.admin.dar`/`tirar`, `usuario.ativar`/`desativar`, `senha.trocar`, `acesso.conceder`/`revogar` (pelo repositório, com o ator `usuario:ID`) e `dominio.adicionar`/`principal`/`modo`/`remover` (site, host e modo; host é configuração pública, não dado pessoal).
- **Fora desta fatia**: apagar conta (só desativar), lista paginada e busca de contas, alterar o login, pedir a senha de novo antes de ações sensíveis, atribuir papel escolhendo a seção numa árvore (hoje pelo número) e verificar se o DNS do domínio aponta para cá.

## Consequências

- Cada nova tela segue a mesma receita: rota fixa, sessão, ação do enum, `Permissoes::pode`, CSRF e origem no POST, escape, auditoria sem conteúdo, classes do Bootstrap sem estilo inline, teste de acesso sem login, sem permissão, CSRF e XSS.
- Atualizar o Bootstrap é tarefa de manutenção: baixar a nova versão, conferir o `integrity` do npm, registrar o SHA-256 e trocar `Pagina::BOOTSTRAP`.
- Sem JavaScript, a interface é simples e segue o visual padrão do Bootstrap; melhorias de conforto (pré-visualização, arrastar para mover) poderão vir depois como camada opcional, sem tirar as checagens do servidor.
- A API de conteúdo (ADR 0009) poderá reutilizar as mesmas decisões de permissão; esta administração não depende dela.

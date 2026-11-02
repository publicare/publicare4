# Segurança

Como reportar uma vulnerabilidade: [SECURITY.md](../../SECURITY.md). Esta página descreve as defesas **em vigor** e o que ainda não existe.

## Defesas em vigor

| Superfície | Defesa | Onde |
|---|---|---|
| **Moldes** (conteúdo de quem edita a pele) | Compilados para PHP que só chama o `Runtime`; PHP cru (`<?`, `?>`) é rejeitado; variáveis isoladas (não são as do PHP); sem acesso a arquivos, superglobais nem funções do PHP | `Pbl\Linguagem` |
| **Saída HTML** | `eco` escapa por padrão; `ecocru` precisa ser escrito de propósito | `Runtime::esc` |
| **Laços e inclusões** | Teto de 10.000 iterações (sem estourar inteiros), 8 níveis de `incluir`, 5 de herança de layout | `Runtime`, `Compilador` |
| **URL da requisição** | Validada por regex **sem backtracking** (classes disjuntas, quantificadores possessivos, `/D`) e com teto de 300 caracteres antes da regex; só então vira caminho de arquivo | `Caminhos::urlValida` |
| **Arquivos do cache** | O caminho nunca sai de `dados/cache/paginas/`; gravação atômica (temporário + `rename`) | `Caminhos`, `Publicador` |
| **SQL** | Sempre com parâmetros; ordenação por lista fechada de colunas (nome de propriedade vai como parâmetro); `LIKE` com curingas escapados | `ObjetoRepositorio` |
| **Isolamento entre sites** | Consultas e `usarobjeto`/`temfilho` sempre dentro do site do objeto corrente; campos do sistema (`#id`, `#site`…) vencem dados da classe | `FonteRepositorio`, `ContextoDeObjeto` |
| **Vigência** | Conteúdo fora do período não é publicado, não aparece em consultas e sai do cache (inclusive por vencimento sem republicar) | `Objeto::visivel`, `.expira` |
| **Redirecionamento de URL antiga** | Só para caminho relativo do próprio site, montado a partir da URL validada do objeto (nunca de texto da requisição, exceto a query string); só se o destino está publicado e vigente | `Aplicacao`, `ObjetoRepositorio::urlAtualDoHistorico` |
| **Sessão** | Token de 32 bytes aleatórios, só o SHA-256 no banco; cookie `__Host-pbl4` (`HttpOnly`, `Secure`, `SameSite=Lax`, sem `Domain`); token novo a cada login e o antigo é encerrado (contra fixação); expira em 30 min sem uso e em 12 h; trocar senha ou desativar derruba as sessões; sair só por POST com CSRF | `SessaoRepositorio`, `Painel` |
| **CSRF** | Token ligado à sessão (HMAC do id da sessão com o segredo do site), `hash_equals`, mais `Origin`/`Referer` conferidos; no formulário de entrar, token assinado por hora | `Csrf`, `Rede` |
| **Força bruta** | 5 falhas por login ou 30 por IP em 15 min bloqueiam (429 + `Retry-After`) no servidor; login e IP só como HMAC; resposta igual para login inexistente e senha errada; auditoria sem login digitado nem IP | `LimiteDeTentativas`, `Painel` |
| **Cabeçalhos da administração** | `Cache-Control: no-store`, CSP restritiva (`default-src 'none'`, `style-src 'self'`, sem `script-src` e sem `unsafe-inline`, `frame-ancestors 'none'`, `form-action 'self'`), `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: same-origin`, HSTS em HTTPS | `Pagina` |
| **Telas da administração** | Toda rota exige sessão (sem ela, 303 para entrar) e decide com `Permissoes::pode` e uma ação fixa do código, também no POST (esconder o botão não é a defesa): ver e histórico `Ver`; criar `Criar` no pai; editar e restaurar `Editar`; estado e datas `Publicar`; mover, lixeira e tirar da lixeira `Apagar` (mover também `Criar` no destino). Sem permissão e objeto inexistente dão o mesmo 403; sub-rota e id da URL validados por expressão ancorada e lista fixa (o resto é 404, até sem sessão); todo POST confere origem e CSRF antes de tudo; campos só como texto, UTF-8 válido, sem caracteres de controle, com teto de tamanho e validados pelo tipo da propriedade (obrigatórios exigidos; arquivados nunca tocados, nem por formulário forjado); tipo do filho conferido contra os aceitos pelo pai também no POST; referência só para conteúdo do mesmo site que a pessoa pode ver, com a mesma mensagem para "não existe" e "sem permissão"; ordem e filtros da lista só de listas fixas; toda saída escapada (título hostil e HTML guardado viram texto); gravação só pelo `ObjetoRepositorio` com versão esperada (409 em edição simultânea, também em estado, mover, lixeira e restaurar); a regra da lixeira (nada fora dela sob pai apagado) conferida na mesma transação, com a linha do pai travada, e reconferida depois de gravar (desfaz e dá 409 se uma corrida escapou); auditoria com nomes de campos e motivo fixo, e registro de tentativa negada (`objeto.<ação>.negado`, só ids) | `TelasDeObjetos`, `TelasDeConteudo`, `Painel` |
| **Tipos de conteúdo na administração** | Só o administrador global altera (`Permissoes::podeModelarClasses`, em toda rota e no POST); os demais veem só a lista, sem contagem de uso, e recebem o mesmo 403 para tipo existente ou não; origem e CSRF antes de tudo; nomes técnicos validados por regex ancorada e com teto, rótulo e descrição como texto UTF-8 sem caracteres de controle e com teto; chaves do formulário só são lidas para os campos que a classe já tem; campo com valor (hoje ou em versão antiga) não é removido nem muda de tipo, só arquivado, com confirmação, e a regra se repete no repositório; apagar tipo só sem objetos e sem dependentes; versão esperada (409); auditoria só com nomes de tipo e de campo | `TelasDeClasses`, `ClasseRepositorio` |
| **Contas na administração** | Só o administrador global (`Permissoes::podeAdministrarContas`, em toda rota e no POST); conta inexistente e sem permissão dão a mesma 403. Origem + CSRF antes de tudo no POST. Senha pela política (10 a 1024, nada óbvio) e custo de hash fixo do `UsuarioRepositorio`; digitada duas vezes, nunca devolvida à página nem à auditoria. Trocar a senha ou desativar encerra as sessões da conta. Ninguém desativa nem tira a marca de administrador de si mesmo, e a última conta de administrador ativa não é desativada nem rebaixada. Criar, editar, desativar/reativar, redefinir senha e conceder/revogar papel pedem o **modo elevado** (abaixo). Busca e paginação (`UsuarioRepositorio::listar`, 50 por página) filtram e normalizam em PHP, sem SQL com texto de fora. Auditoria só com ids, perfil e nomes de campo (`conta.negado` nas tentativas sem permissão) | `TelasDeContas`, `UsuarioRepositorio` |
| **Domínios na administração** | `Acao::Administrar` na raiz do site (hoje só o administrador global; site sem raiz idem), em toda rota e no POST; mesma 403 para site inexistente. Host validado rótulo a rótulo; o principal não se remove nem muda de modo; domínio de outro site não se mexe por esta tela; o endereço em uso na requisição não se remove. Adicionar, trocar o principal e remover pedem o **modo elevado** (alternar alias ⇄ redirecionador não). Auditoria `dominio.*` com site, host e modo | `TelasDeDominios`, `DominioRepositorio` |
| **Modo elevado (fase 5h)** | Confirmação de senha generalizada para toda ação sensível do painel (contas, domínios, tipos de conteúdo, moldes e pele ativa), não só a Atualização: confirmar a senha abre uma janela de 10 min guardada na sessão (`sessao.elevado_ate`); dentro dela, outra ação sensível não pede a senha de novo. A janela acaba com a sessão (logout, troca de senha, expiração), sem limpeza própria. Mesmo limite de tentativas do login (por login e por IP, 429 com `Retry-After`); a senha nunca entra na auditoria, só o sucesso/falha (`<prefixo>.senha_errada`/`.bloqueado`). Atualizar e reverter usam o mesmo componente, mas sempre pedem a senha (código em execução) | `ModoElevado`, `AuxiliaresDeTela` |
| **Estilos da administração** | Bootstrap 5.3.8 (só CSS) servido do próprio site, sem CDN; arquivo idêntico à release oficial, com SHA-256 registrado em `public/_pbl/assets/PROVENIENCIA.md` e conferido por teste; nenhum JavaScript | `public/_pbl/assets/` |
| **Instalador web** | Só antes de instalar; prova de posse (`dados/instalar.liberar`), CSRF, origem, escape de tudo, senha nunca refletida, atômico; some depois (404) | `InstaladorWeb` |
| **Pastas internas** | `.htaccess` (e a raiz em `public/`): `dados/`, `app/`, `vendor/`, `composer.*` não são servidos (403 no Apache testado) | `.htaccess` |
| **Segredo do site** | 32 bytes em `dados/config/segredo`, permissão 0600, fora do Git, criado na instalação | `Segredo` |
| **Contas e senhas** | Senha em argon2id (bcrypt de reserva, com SHA-256 antes para não truncar em 72 bytes), política de tamanho e de senhas óbvias, hash atualizado no login, verificação com o mesmo custo para login que não existe, nunca registrada na auditoria | `UsuarioRepositorio`, `SenhaPolitica` |
| **Permissões** | `Permissoes::pode(usuário, ação, objeto)`: ação é um enum fixo do código (nunca texto da requisição), perfil herdado pelo `caminho` conferido em PHP, o mais forte vence, o autor edita só o que criou, usuário inativo nada, nada cruza de site | `Permissoes`, `Perfil`, `Acao` |
| **Atualização** | Manifesto assinado com Ed25519 (chave pública embutida), SHA-256 do pacote e de cada arquivo, extração defensiva (zip-slip, links, zip bomb), HTTPS só para o mesmo host, versão só avança, manutenção + cópia do banco + volta atrás; detalhes em [atualização](atualizacao.md). **No painel** (`/_pbl/atualizacao`): só administrador geral, origem + CSRF no POST, **senha de novo** com o limite de tentativas do login, versão reconferida ao vivo antes de iniciar, atualização em **processo separado** (nunca na requisição), **uma operação por vez** (trava, compartilhada com reverter), requisição web grava só em `dados/`, nenhum dado da requisição vai para a linha de comando (nem mesmo o nome do comando, restrito a `atualizar`/`reverter`), saída do processo escapada | `Atualizador`, `Assinatura`, `Pacote`, `TelasDeAtualizacao`, `EstadoDoPainel`, `LancadorDeProcesso` |
| **Voltar atrás de uma atualização concluída** | `bin/pbl reverter` (CLI) e o botão correspondente no painel (mesma reautenticação, trava e processo separado da atualização) só agem depois de `Reversor::avaliar` conferir: versão instalada é a registrada em `dados/atualizacao/aplicada.json`, código guardado em `dados/atualizacao/anterior/` corresponde à versão anterior, cópia do banco em `dados/backups/` existe e não foi alterada (SHA-256) nem mudou de versão de esquema, e o esquema do banco de hoje não avançou mais desde a atualização (senão recusa, explicando o motivo, em vez de arriscar). Confirmação explícita porque restaura o banco de antes: dados criados depois da atualização se perdem. Se a volta falhar no meio, devolve o código novo e o banco de hoje (copiado antes, por segurança) | `Reversor`, `RegistroDaAtualizacao`, `CopiaDeBanco`, `Trava` |
| **Auditoria** | Tabela que só acrescenta; registra contas, acessos, entradas e saídas e as alterações de conteúdo (criar, atualizar, estado, mover) com **os nomes dos campos, nunca o conteúdo**; sem senha, token, login digitado nem IP em texto | `Auditoria` |
| **Domínios e redirecionamentos** | Host de destino vem do cadastro, nunca da requisição; host inválido → 400; nomes de domínio validados rótulo a rótulo (sem regex ambíguo); regras só aceitam destino no site ou `https://` (sem `//`, `javascript:`, `http:`, credenciais, `..`, quebra de linha), com limites de 5000 regras e 200 prefixos por site | `Aplicacao`, `Dominio`, `RedirecionamentoRepositorio` |
| **Edição simultânea** | `versaoEsperada` recusa salvar sobre uma versão que outra pessoa já alterou; histórico imutável | `ObjetoRepositorio::atualizar` |
| **Conversor de moldes** | Não escreve fora do destino nem através de link simbólico; nomes de molde sanitizados | `ConversorDeDiretorio` |
| **Upload de arquivos** (fase 7a) | Whitelist fechada (nunca `php`/`html`/`svg`/`js`), tipo real por `finfo` no conteúdo (nunca pela extensão do nome), imagem decodificada de verdade e **recodificada** pelo GD quando disponível (payload e EXIF somem), nome em disco aleatório (nunca o do cliente), `dados/uploads/` fora do `docroot`, entrega só de objeto publicado e vigente com `Content-Type` fixo e `nosniff`, apagar órfão só por `bin/pbl limpar-uploads` (nunca na requisição) | `Pbl\Arquivos`, [ADR 0014](../adr/0014-upload-de-arquivos.md) |
| **Dependências** | `composer audit` e Dependabot na pipeline | `.github/` |

## Como tratamos entrada de fora

1. Validar **antes** de usar (formato, tamanho, conjunto permitido).
2. Nunca montar SQL, caminho de arquivo ou código com texto de fora.
3. Testar com entrada hostil (veja `tests/CaminhosTest.php`, `tests/ConsultaTest.php`).
4. Regex sobre entrada pública: sem quantificadores sobrepostos, com teto de tamanho e teste de tempo.

## HTML em propriedades `html` e integridade do conteúdo (fase 5g)

- **Limpeza na gravação, por lista de permissões** ([ADR 0012](../adr/0012-administracao-web.md) §10). `HtmlSeguro::limpar` lê o HTML com `DOMDocument` e **reescreve** a saída a partir da árvore (texto e atributos sempre escapados), em vez de filtrar por regex; o que o parser e o navegador entendam diferente vira, no pior caso, texto escapado. Roda em `ObjetoRepositorio::validar`, então vale para tela, linha de comando, importador e restauração de versão.
- Permitido: parágrafos, títulos, ênfase, listas, tabelas, citações, código, `a[href]`, `img[src alt width height]` e `title`/`lang`/`dir`. Proibido: `script`, `style`, `iframe`, `object`, `embed`, `svg`, `math`, `form` e controles, `template`, `meta`/`link`/`base`, **qualquer** `on*`, `style`, `class`, `id`, `target`, `srcset`; comentários, CDATA e instruções.
- Endereços: caracteres de controle e espaços são removidos antes de olhar o esquema (`java<TAB>script:`), entidades já vêm decodificadas pelo parser; links `http`/`https`/`mailto`/`tel`/relativo; imagens `http`/`https`/relativo; `javascript:`, `vbscript:`, `data:`, `file:` e qualquer outro são descartados. Todo link ganha `rel="noopener noreferrer"`. Profundidade e tamanho limitados (1 MB).
- Os moldes continuam escapando por padrão; `ecocru` de um campo `html` agora imprime HTML já limpo. O HTML gravado **antes** da fase 5g só era limpo quando o campo fosse gravado de novo (a administração nunca o interpreta, mostra como texto); a fase 5i fechou essa lacuna com uma varredura (abaixo).
- Testes: `tests/HtmlSeguroTest.php` (mais de 60 vetores de XSS, mutação, idempotência), `tests/IntegridadeDeConteudoTest.php` e `tests/AdminIntegridadeTest.php`.
- Regras de tipos aceitos e campos obrigatórios no repositório (`ViolacaoDeRegra`): um formulário forjado, um script de importação ou a linha de comando não contornam o que a tela exige.

### Varredura do HTML antigo (fase 5i)

`bin/pbl sanitizar-html [--simular] [--site=ID] [--limite=N]` lê, em lotes (`ObjetoRepositorio::loteOrdenadoPorId`, sem carregar tudo na memória), toda propriedade `html` de todo objeto — de qualquer site e **qualquer estado**, inclusive a lixeira e o que nunca foi publicado (o valor continua gravado e um dia pode ser restaurado ou publicado) — e aplica `HtmlSeguro::limpar`. Quando o resultado é diferente do gravado, grava uma **nova versão** pelo caminho normal (`ObjetoRepositorio::atualizar`): nunca sobrescreve sem histórico, e dá para voltar pela tela de versões.

- Autoria **sistema** (sem usuário) e auditoria `objeto.atualizar` só com os nomes dos campos tocados e o id do objeto, igual a qualquer outra gravação pelo repositório — o HTML em si nunca é auditado.
- `--simular` não grava nada; mostra, por objeto e campo, um **resumo** do que seria tirado (tags e atributos removidos, por nome, e o tamanho antes/depois em bytes) — nunca o HTML inteiro.
- Páginas afetadas que estão no ar são republicadas pelo mesmo mecanismo da edição pelo painel (regrava se visível, tira do cache se não; o pai também é refeito, por poder listar o conteúdo).
- Idempotente: como `HtmlSeguro::limpar` já é idempotente, rodar de novo não encontra nada para mudar.
- Exclusão mútua com `atualizar`/`reverter` pela mesma trava (`Trava`, `dados/atualizacao.lock`): não roda durante uma atualização do PBL4.
- Testes: `tests/SanitizarHtmlComandoTest.php`.

## Formulários públicos (fase 6a)

Única superfície que **grava sem sessão**; desenho completo no [ADR 0013](../adr/0013-formularios-publicos.md).

- **Ordem das defesas**: formulário existe e está no ar (404 único) → limite por IP e formulário (429, 10 em 15 min, conta acertos e erros) → tamanho (413, 64 KiB) → origem e token (403) → campo-armadilha (finge sucesso) → obrigatórios e tipos (422) → `ObjetoRepositorio::criarDentro` (regras do tipo, HTML limpo).
- **CSRF**: HMAC fixo por formulário (as páginas são arquivos estáticos) **mais** `Rede::origemConfere`. Não há sessão a sequestrar; o token não é segredo contra quem lê a página, e a defesa contra abuso é limite + armadilha + conferência de origem. Trocar o segredo do site exige republicar as páginas com formulário.
- **Superfície mínima**: só os campos declarados são lidos; `ref` é proibido (enumeração de conteúdo); campos desconhecidos e de sistema (`titulo`, `status`, `pai`, `peso`, `criado_por`…) são ignorados; o envio nasce `privado`, sem autor, com URL aleatória, e nunca é publicado sozinho.
- **Nenhuma resposta explica a recusa de formulário**: inexistente, de outro site, fora do ar, fechado ou mal configurado dão o mesmo 404.
- **Auditoria sem valores**: `formulario.envio` (ator `anonimo`, formulário, tipo, objeto, HMAC do IP). A armadilha e o 429 não são registrados (evita encher a auditoria, que só cresce).
- **Limitação conhecida**: sem captcha de terceiros nem verificação de e-mail; um atacante com muitos IPs ainda consegue encher a seção de destino de envios privados (a equipe os manda para a lixeira). Captcha plugável e quotas globais são próximos passos.
- Testes: `tests/FormulariosTest.php`.

## Upload de arquivos (fase 7a + 7b)

Único lugar, fora dos moldes, em que a administração grava o **conteúdo** de um arquivo (não só texto). Desenho completo no
[ADR 0014](../adr/0014-upload-de-arquivos.md).

- **Ordem das defesas**: erro do PHP (`upload_max_filesize`/`post_max_size`, mensagem clara) → tamanho configurado da
  propriedade → tipo real do conteúdo por `finfo` contra a whitelist (nunca a extensão do nome enviado) → imagem:
  decodificação de verdade pelo GD e recodificação (payload escondido e EXIF somem); sem GD, só os bytes mágicos do formato
  (mais fraco, registrado em `sanitizada = false`).
- **Nunca o nome do cliente como caminho**: chave aleatória de 16 bytes no disco; a extensão vem sempre da whitelist. O nome
  original só é metadado saneado (exibição e `Content-Disposition`).
- **Whitelist fechada por categoria** (`imagem`: jpg/png/gif/webp; documento: pdf/txt/csv e docx/xlsx/pptx/odt/ods/odp):
  nunca `php`, `phtml`, `html`, `svg` ou `js`. Os seis formatos de Office/OpenDocument são contêineres ZIP: quando o
  `finfo` só diz "é um ZIP", `ServicoDeUpload` confere a assinatura e **olha dentro do pacote** (`word/document.xml`,
  `xl/workbook.xml`, `ppt/presentation.xml` ou o arquivo `mimetype` do ODF) antes de aceitar; sem bater com nenhum
  formato conhecido, ou sem a extensão `zip` do PHP para abrir o pacote, o envio é recusado como ambíguo.
- **Defesa em profundidade no repositório**: `ObjetoRepositorio::validar` confere de novo que o arquivo apontado existe, é
  do mesmo site e da categoria certa — um formulário forjado não troca o valor por um arquivo de outro site ou tipo.
- **Entrega só por `ArquivoController`**, rota pública (sem sessão, como `EnvioDeFormulario`) que só serve o arquivo de um
  objeto **publicado e vigente agora**; qualquer outro caso dá 404, sempre a mesma resposta. `Content-Type` fixo da
  whitelist, `nosniff`, `Content-Disposition` seguro (nome saneado), `ETag`/cache e um trecho de `Range`.
- **Nunca apaga na requisição**: trocar ou remover o valor de uma propriedade só deixa de referenciar o arquivo antigo; só
  `bin/pbl limpar-uploads` (cron, fora da requisição) apaga o que nenhum objeto nem versão antiga referencia mais, com
  carência de 1 hora.
- **Limitação conhecida**: sem GD, upload de imagem fica só com a verificação de cabeçalho (mais fraca que decodificar
  de verdade); sem a extensão `zip`, upload de `docx`/`xlsx`/`pptx`/`odt`/`ods`/`odp` é sempre recusado (não há como
  olhar dentro do pacote). O instalador avisa nos dois casos.
- **Deduplicação e limpeza**: dois uploads com o mesmo conteúdo compartilham o arquivo físico (uma linha de `arquivo`
  por envio, a mesma `chave` em disco); `bin/pbl limpar-uploads` só remove o arquivo físico depois de apagar **todas**
  as linhas órfãs e confirmar que nenhuma linha (viva ou ainda não examinada) continua usando aquela chave — nunca
  apaga um arquivo que outra linha, viva ou só fora da carência, ainda precise.
- **Criação atômica (fase 7b)**: a tela de criar conteúdo também aceita upload; o envio só acontece **dentro da mesma
  transação** do `INSERT` do objeto (depois dele, já com o id novo), através de um gancho `$aposInserir` em
  `ObjetoRepositorio::criar`/`criarDentro`. Upload que falhe desfaz a transação inteira — o objeto nunca chega a existir
  pela metade. O arquivo físico em disco, gravado antes do insert do banco, não entra na transação; numa falha depois
  dele já escrito, fica órfão de fábrica (sem linha em `arquivo`, nunca listado por `limpar-uploads`, reaproveitado por
  dedup se o mesmo conteúdo for enviado de novo).
- **Comandos de molde (fase 7b)**: `<@arquivo valor=… info="url|nome|tamanho|tipo"@>` e `<@miniatura valor=… tamanho=n@>`
  sempre escapam a saída (como `eco_limite`/`formatadata`, sem variante "cru") e só imprimem algo para um arquivo cujo
  objeto dono está publicado e vigente **no mesmo site do objeto corrente** — a mesma regra do `ArquivoController`,
  repetida em `FonteRepositorio::arquivo` (o `Runtime` continua sem depender de `Pbl\Arquivos`, só da interface
  `FonteDeDados`).
- Testes: `tests/ArquivosTest.php`, `tests/AdminUploadTest.php`, `tests/LinguagemTest.php`, `tests/AdminClassesTest.php`.

## Escrita em disco pela administração (moldes de pele)

A edição de peles é a única superfície em que a administração **grava arquivos** (`dados/peles/<pele>/<molde>.pbl`), e moldes são código-ish, então ela é fechada em `GerenciadorDePeles` ([ADR 0012](../adr/0012-administracao-web.md) §8):

- Só administra o site quem tem `Acao::Administrar` (hoje o administrador global); CSRF e origem em todo POST.
- Nome da pele e do molde por expressão ancorada e possessiva (sem `..`, barra inicial, barra dupla, contrabarra, maiúscula, byte nulo); a extensão `.pbl` é sempre acrescentada pelo código, então não há como criar `.php`, `.htaccess` ou outro arquivo.
- Links simbólicos nunca são seguidos (pele, pasta ou arquivo) e o `realpath` precisa ficar dentro da pasta da pele; tamanho máximo de 200 000 bytes, UTF-8 válido, limite de moldes por pele.
- Gravação atômica (temporário na mesma pasta + `rename`) e proteção contra edição simultânea por SHA-256.
- O conteúdo só é salvo se o parser da linguagem o aceitar; PHP cru é recusado e o `Gerador` só emite literais e chamadas ao `Runtime`, então o molde não executa nada fora do sandbox da linguagem.
- Auditoria com site, pele e nome do molde, nunca o conteúdo. Criar, editar e apagar molde, e trocar a pele ativa, pedem o modo elevado (fase 5h, acima). Testes em `tests/AdminSiteTest.php` (travessia, extensões, links simbólicos, PHP cru, XSS no editor).

### Histórico, rascunho e pré-visualização (fase 5j)

- **Histórico** (`pele_molde_versao`, migração 15): toda gravação ou remoção guarda o conteúdo inteiro, o hash e o autor; retenção de `GerenciadorDePeles::MAX_VERSOES_POR_MOLDE` (200) versões por molde. "Voltar a esta versão" passa pelas **mesmas** verificações de uma gravação comum: CSRF, origem, permissão, modo elevado, validação pelo parser (recusa 422 se a versão não compila mais, por exemplo depois que um layout citado foi apagado) e proteção contra edição simultânea (hash do arquivo aberto); nunca reescreve o histórico, só acrescenta uma versão nova.
- **Rascunho** (`pele_molde_rascunho`, no máximo um por molde): não passa pelo parser (só tamanho e UTF-8), para dar para guardar um texto incompleto; não toca no arquivo da pele nem gera versão. Publicar o rascunho volta a exigir o parser e o modo elevado, como qualquer gravação.
- **Pré-visualização isolada**: exige permissão de administrar o site (mesma checagem de toda a tela de peles, 403 para quem não tem) e um rascunho salvo (404 sem ele); a renderização usa `Renderizador` diretamente (nunca o `Publicador`), então **não grava** `dados/cache/paginas` nem muda a página publicada. A resposta é a página renderizada com `Cache-Control: no-store, no-cache, must-revalidate`, `X-Robots-Tag: noindex, nofollow` e uma CSP própria (`default-src 'none'; script-src 'none'; style-src 'self' 'unsafe-inline'; form-action 'none'; frame-ancestors 'none'` — diferente da CSP da administração, porque o molde pode ter `<style>` embutido, como qualquer página publicada).
- **Realce de sintaxe** (`Pbl\Linguagem\Realce`): sempre HTML escapado (`htmlspecialchars` com `ENT_QUOTES | ENT_SUBSTITUTE`), nunca interpreta o molde; um molde que nem o `Lexer` consegue separar (sintaxe malformada) volta como texto simples escapado, em vez de expor um erro. Usado só em telas de leitura (histórico, diferença, visualização); a caixa de edição continua uma `<textarea>` simples.
- Testes em `tests/AdminPeleHistoricoTest.php` (versão em criar/salvar/apagar, diferença escapada, voltar recusado pelo parser, voltar exige CSRF e modo elevado, rascunho isolado do cache e da página pública, pré-visualização sem permissão, retenção) e `tests/RealceTest.php` (escapando tokens maliciosos).

## O que ainda não existe (e quando entra)

| Item | Previsto |
|---|---|
| Recuperação de senha por e-mail, autenticação em dois fatores | depois da fase 4 ([ADR 0011](../adr/0011-autenticacao-sessao-permissoes.md)) |
| Permissão por campo | a decidir (o modelo de classes não tem permissão por campo; as telas de objetos tratam todos os campos não arquivados igual) |
| Captcha plugável, verificação de e-mail e quota global em formulários públicos | próximas fatias da fase 6 ([ADR 0013](../adr/0013-formularios-publicos.md)) |
| Anexo de arquivo em formulário público | próxima fatia do fluxo 6 ([ADR 0013](../adr/0013-formularios-publicos.md)) |
| Atualizar pelo painel sem que o PHP web precise gravar o código (hoje o processo de segundo plano roda com o mesmo usuário do PHP, então a pasta do PBL4 precisa ser gravável por ele; onde isso é indesejável, use a linha de comando com outro usuário) | a decidir ([ADR 0006](../adr/0006-atualizador-automatico.md)) |
| Política para quando existir migração **destrutiva** de verdade (hoje nenhuma existe — ADR 0006 §8 —, então `bin/pbl reverter` sempre é seguro quando o registro da atualização confere) | a decidir ([ADR 0006](../adr/0006-atualizador-automatico.md)) |
| HTTPS forçado na administração (hoje é recomendação) | a decidir |
| Pele inteira (criar, importar, apagar), arquivos de pele que não sejam `.pbl` (CSS, imagens), pele filha, galeria | [ADR 0007](../adr/0007-galeria-pacotes-peles.md) |

## Atrás de um proxy (Cloudflare, nginx)

Com `"confiar_proto": true`, a conferência de origem usa o esquema e a porta que o visitante enxerga (`X-Forwarded-Proto` e, se existir, `X-Forwarded-Port`), e não os da conexão com o proxy; sem a configuração, esses cabeçalhos são ignorados.

O IP real e o HTTPS chegam em cabeçalhos que só podem ser confiados se você disser que o proxy existe. Crie `dados/config/rede.json`:

```json
{"cabecalho_ip": "CF-Connecting-IP", "confiar_proto": true}
```

Sem o arquivo, vale só o que o servidor enxerga (o IP do proxy, o que faria o limite de tentativas tratar todos os visitantes como um só). Use **HTTPS** na administração: sem ele o cookie não recebe `Secure` nem o prefixo `__Host-`.

A administração ainda não está completa (já cuida do conteúdo, dos tipos de conteúdo, das contas e dos domínios; faltam redirecionamentos e peles). Até ela ficar completa e passar por revisão de segurança, **não exponha o PBL4 em produção** para conteúdo que não seja o da publicação estática de páginas.

## Princípios

Segurança é critério de aceite, não detalhe: código que toca entrada de fora, SQL, arquivos, permissões, cache ou moldes precisa dizer no PR o que foi considerado.

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
| **Contas na administração** | Só o administrador global (`Permissoes::podeAdministrarContas`, em toda rota e no POST); conta inexistente e sem permissão dão a mesma 403. Origem + CSRF antes de tudo no POST. Senha pela política (10 a 1024, nada óbvio) e custo de hash fixo do `UsuarioRepositorio`; digitada duas vezes, nunca devolvida à página nem à auditoria. Trocar a senha ou desativar encerra as sessões da conta. Ninguém desativa nem tira a marca de administrador de si mesmo, e a última conta de administrador ativa não é desativada nem rebaixada. Auditoria só com ids, perfil e nomes de campo (`conta.negado` nas tentativas sem permissão) | `TelasDeContas`, `UsuarioRepositorio` |
| **Domínios na administração** | `Acao::Administrar` na raiz do site (hoje só o administrador global; site sem raiz idem), em toda rota e no POST; mesma 403 para site inexistente. Host validado rótulo a rótulo; o principal não se remove nem muda de modo; domínio de outro site não se mexe por esta tela; o endereço em uso na requisição não se remove. Auditoria `dominio.*` com site, host e modo | `TelasDeDominios`, `DominioRepositorio` |
| **Estilos da administração** | Bootstrap 5.3.8 (só CSS) servido do próprio site, sem CDN; arquivo idêntico à release oficial, com SHA-256 registrado em `public/_pbl/assets/PROVENIENCIA.md` e conferido por teste; nenhum JavaScript | `public/_pbl/assets/` |
| **Instalador web** | Só antes de instalar; prova de posse (`dados/instalar.liberar`), CSRF, origem, escape de tudo, senha nunca refletida, atômico; some depois (404) | `InstaladorWeb` |
| **Pastas internas** | `.htaccess` (e a raiz em `public/`): `dados/`, `app/`, `vendor/`, `composer.*` não são servidos (403 no Apache testado) | `.htaccess` |
| **Segredo do site** | 32 bytes em `dados/config/segredo`, permissão 0600, fora do Git, criado na instalação | `Segredo` |
| **Contas e senhas** | Senha em argon2id (bcrypt de reserva, com SHA-256 antes para não truncar em 72 bytes), política de tamanho e de senhas óbvias, hash atualizado no login, verificação com o mesmo custo para login que não existe, nunca registrada na auditoria | `UsuarioRepositorio`, `SenhaPolitica` |
| **Permissões** | `Permissoes::pode(usuário, ação, objeto)`: ação é um enum fixo do código (nunca texto da requisição), perfil herdado pelo `caminho` conferido em PHP, o mais forte vence, o autor edita só o que criou, usuário inativo nada, nada cruza de site | `Permissoes`, `Perfil`, `Acao` |
| **Atualização** | Manifesto assinado com Ed25519 (chave pública embutida), SHA-256 do pacote e de cada arquivo, extração defensiva (zip-slip, links, zip bomb), HTTPS só para o mesmo host, versão só avança, manutenção + cópia do banco + volta atrás; detalhes em [atualização](atualizacao.md). **No painel** (`/_pbl/atualizacao`): só administrador geral, origem + CSRF no POST, **senha de novo** com o limite de tentativas do login, versão reconferida ao vivo antes de iniciar, atualização em **processo separado** (nunca na requisição), **uma por vez** (trava), requisição web grava só em `dados/`, nenhum dado da requisição vai para a linha de comando, saída do processo escapada | `Atualizador`, `Assinatura`, `Pacote`, `TelasDeAtualizacao`, `EstadoDoPainel`, `LancadorDeProcesso` |
| **Auditoria** | Tabela que só acrescenta; registra contas, acessos, entradas e saídas e as alterações de conteúdo (criar, atualizar, estado, mover) com **os nomes dos campos, nunca o conteúdo**; sem senha, token, login digitado nem IP em texto | `Auditoria` |
| **Domínios e redirecionamentos** | Host de destino vem do cadastro, nunca da requisição; host inválido → 400; nomes de domínio validados rótulo a rótulo (sem regex ambíguo); regras só aceitam destino no site ou `https://` (sem `//`, `javascript:`, `http:`, credenciais, `..`, quebra de linha), com limites de 5000 regras e 200 prefixos por site | `Aplicacao`, `Dominio`, `RedirecionamentoRepositorio` |
| **Edição simultânea** | `versaoEsperada` recusa salvar sobre uma versão que outra pessoa já alterou; histórico imutável | `ObjetoRepositorio::atualizar` |
| **Conversor de moldes** | Não escreve fora do destino nem através de link simbólico; nomes de molde sanitizados | `ConversorDeDiretorio` |
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
- Os moldes continuam escapando por padrão; `ecocru` de um campo `html` agora imprime HTML já limpo. O HTML gravado **antes** da fase 5g só é limpo quando o campo for gravado de novo; a administração nunca o interpreta (mostra como texto).
- Testes: `tests/HtmlSeguroTest.php` (mais de 60 vetores de XSS, mutação, idempotência), `tests/IntegridadeDeConteudoTest.php` e `tests/AdminIntegridadeTest.php`.
- Regras de tipos aceitos e campos obrigatórios no repositório (`ViolacaoDeRegra`): um formulário forjado, um script de importação ou a linha de comando não contornam o que a tela exige.

## Escrita em disco pela administração (moldes de pele)

A edição de peles é a única superfície em que a administração **grava arquivos** (`dados/peles/<pele>/<molde>.pbl`), e moldes são código-ish, então ela é fechada em `GerenciadorDePeles` ([ADR 0012](../adr/0012-administracao-web.md) §8):

- Só administra o site quem tem `Acao::Administrar` (hoje o administrador global); CSRF e origem em todo POST.
- Nome da pele e do molde por expressão ancorada e possessiva (sem `..`, barra inicial, barra dupla, contrabarra, maiúscula, byte nulo); a extensão `.pbl` é sempre acrescentada pelo código, então não há como criar `.php`, `.htaccess` ou outro arquivo.
- Links simbólicos nunca são seguidos (pele, pasta ou arquivo) e o `realpath` precisa ficar dentro da pasta da pele; tamanho máximo de 200 000 bytes, UTF-8 válido, limite de moldes por pele.
- Gravação atômica (temporário na mesma pasta + `rename`) e proteção contra edição simultânea por SHA-256.
- O conteúdo só é salvo se o parser da linguagem o aceitar; PHP cru é recusado e o `Gerador` só emite literais e chamadas ao `Runtime`, então o molde não executa nada fora do sandbox da linguagem.
- Auditoria com site, pele e nome do molde, nunca o conteúdo. Testes em `tests/AdminSiteTest.php` (travessia, extensões, links simbólicos, PHP cru, XSS no editor).

## O que ainda não existe (e quando entra)

| Item | Previsto |
|---|---|
| Recuperação de senha por e-mail, autenticação em dois fatores | depois da fase 4 ([ADR 0011](../adr/0011-autenticacao-sessao-permissoes.md)) |
| Permissão por campo | a decidir (o modelo de classes não tem permissão por campo; as telas de objetos tratam todos os campos não arquivados igual) |
| Upload seguro (lista de tipos, fora do `docroot`) | com o upload (fluxo 7) |
| Atualizar pelo painel sem que o PHP web precise gravar o código (hoje o processo de segundo plano roda com o mesmo usuário do PHP, então a pasta do PBL4 precisa ser gravável por ele; onde isso é indesejável, use a linha de comando com outro usuário) | a decidir ([ADR 0006](../adr/0006-atualizador-automatico.md)) |
| Voltar atrás **depois** de uma atualização concluída por botão ou comando | a decidir (hoje é manual: `dados/atualizacao/anterior/` e `dados/backups/`) |
| HTTPS forçado na administração (hoje é recomendação) | a decidir |
| Pele inteira (criar, importar, apagar), arquivos de pele que não sejam `.pbl`, histórico de moldes | com o editor e a galeria de peles ([fluxo 4](../fluxos/04-edicao-peles.md)) |
| Limpar o HTML antigo já gravado antes da fase 5g (hoje ele é limpo na próxima gravação do campo; a leitura não altera nada) | a decidir (um comando de varredura) |
| Reautenticar (pedir a senha de novo) antes de ações de conta sensíveis; lista de contas paginada | a decidir |

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

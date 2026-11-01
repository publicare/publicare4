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
| **Telas da administração** | Toda rota exige sessão (sem ela, 303 para entrar) e decide com `Permissoes::pode` e uma ação fixa do código, também no POST (esconder o botão não é a defesa); sem permissão e objeto inexistente dão o mesmo 403; id da URL validado por expressão ancorada; todo POST confere origem e CSRF antes de tudo; campos só como texto, UTF-8 válido, sem caracteres de controle, com teto de tamanho; toda saída escapada (título hostil vira texto); gravação só por `ObjetoRepositorio::atualizar` com versão esperada (409 em edição simultânea); auditoria com nomes de campos e motivo fixo, e registro de tentativa de edição negada | `TelasDeConteudo`, `Painel` |
| **Estilos da administração** | Bootstrap 5.3.8 (só CSS) servido do próprio site, sem CDN; arquivo idêntico à release oficial, com SHA-256 registrado em `public/_pbl/assets/PROVENIENCIA.md` e conferido por teste; nenhum JavaScript | `public/_pbl/assets/` |
| **Instalador web** | Só antes de instalar; prova de posse (`dados/instalar.liberar`), CSRF, origem, escape de tudo, senha nunca refletida, atômico; some depois (404) | `InstaladorWeb` |
| **Pastas internas** | `.htaccess` (e a raiz em `public/`): `dados/`, `app/`, `vendor/`, `composer.*` não são servidos (403 no Apache testado) | `.htaccess` |
| **Segredo do site** | 32 bytes em `dados/config/segredo`, permissão 0600, fora do Git, criado na instalação | `Segredo` |
| **Contas e senhas** | Senha em argon2id (bcrypt de reserva, com SHA-256 antes para não truncar em 72 bytes), política de tamanho e de senhas óbvias, hash atualizado no login, verificação com o mesmo custo para login que não existe, nunca registrada na auditoria | `UsuarioRepositorio`, `SenhaPolitica` |
| **Permissões** | `Permissoes::pode(usuário, ação, objeto)`: ação é um enum fixo do código (nunca texto da requisição), perfil herdado pelo `caminho` conferido em PHP, o mais forte vence, o autor edita só o que criou, usuário inativo nada, nada cruza de site | `Permissoes`, `Perfil`, `Acao` |
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

## O que ainda não existe (e quando entra)

| Item | Previsto |
|---|---|
| Recuperação de senha por e-mail, autenticação em dois fatores | depois da fase 4 ([ADR 0011](../adr/0011-autenticacao-sessao-permissoes.md)) |
| Permissão por campo | com o editor de classes |
| Upload seguro (lista de tipos, fora do `docroot`) | com o upload (fluxo 7) |
| Atualização com pacote **assinado** (Ed25519), verificação e *rollback* | fase 4 ([ADR 0006](../adr/0006-atualizador-automatico.md)) |
| HTTPS forçado na administração (hoje é recomendação) | a decidir |
| Telas de criar, mover, mudar estado e apagar conteúdo, com as mesmas defesas | próximas fatias da fase 5 ([ADR 0012](../adr/0012-administracao-web.md)) |

## Atrás de um proxy (Cloudflare, nginx)

Com `"confiar_proto": true`, a conferência de origem usa o esquema e a porta que o visitante enxerga (`X-Forwarded-Proto` e, se existir, `X-Forwarded-Port`), e não os da conexão com o proxy; sem a configuração, esses cabeçalhos são ignorados.

O IP real e o HTTPS chegam em cabeçalhos que só podem ser confiados se você disser que o proxy existe. Crie `dados/config/rede.json`:

```json
{"cabecalho_ip": "CF-Connecting-IP", "confiar_proto": true}
```

Sem o arquivo, vale só o que o servidor enxerga (o IP do proxy, o que faria o limite de tentativas tratar todos os visitantes como um só). Use **HTTPS** na administração: sem ele o cookie não recebe `Secure` nem o prefixo `__Host-`.

A administração ainda está no começo (ver e editar título e descrição). Até ela ficar completa e passar por revisão de segurança, **não exponha o PBL4 em produção** para conteúdo que não seja o da publicação estática de páginas.

## Princípios

Segurança é critério de aceite, não detalhe: código que toca entrada de fora, SQL, arquivos, permissões, cache ou moldes precisa dizer no PR o que foi considerado.

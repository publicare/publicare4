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
| Sessão própria, tela de entrar/sair, CSRF, limite de tentativas no servidor, cabeçalhos de segurança, segredo do site | fase 3b ([ADR 0011](../adr/0011-autenticacao-sessao-permissoes.md)) |
| Permissão por campo | com o editor de classes |
| Upload seguro (lista de tipos, fora do `docroot`) | com o upload (fluxo 7) |
| Atualização com pacote **assinado** (Ed25519), verificação e *rollback* | fase 4 ([ADR 0006](../adr/0006-atualizador-automatico.md)) |
| Cabeçalhos de segurança (CSP etc.) e HTTPS forçado | com a administração web |

Até lá, **não exponha o PBL4 em produção** para conteúdo que não seja o da publicação estática de páginas.

## Princípios

Segurança é critério de aceite, não detalhe: código que toca entrada de fora, SQL, arquivos, permissões, cache ou moldes precisa dizer no PR o que foi considerado.

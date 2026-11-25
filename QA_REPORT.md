# QA_REPORT — Senha provisória: trocar a senha no primeiro acesso

## Veredito: GO (com 2 pendências para o CI/operador)
Os 5 pontos do pedido estão cobertos por código e teste. Pendências fora do meu alcance: a suíte completa (CI) e a aplicação da migração M029 num banco real (operador).

## Cobertura por requisito
| Pedido | Evidência | Resultado |
|---|---|---|
| (1) marca `senha_provisoria`, migração aditiva com rollback | `M029SenhaProvisoria` (ADD COLUMN, padrão 0, idempotente, `ROLLBACK` documentado); `testMigracaoAdicionaColuna…Rollback` | OK |
| (1) CLI `usuario criar\|senha`, `conta-senha`, `instalar --admin-senha-provisoria` | `testCliUsuarioCriarESenha…`, `testCliContaSenhaGeradaProvisoria`, `testInstalarComAdminSenhaProvisoria`, `testInstalarExigeAdminLogin…`; `--provisoria` recusado com `--servico` e em outras ações | OK |
| (2) qualquer rota do painel redireciona para a troca | `Painel::comSessao` barra tudo (303 para `/_pbl/trocar-senha`); `testLoginProvisorioRedireciona…`, `testPostsDoPainelSaoBarradosSemExecutar` | OK |
| (2) API barrada | `/_pbl/api` devolve 403 `senha_provisoria`; `testApiDoPainelDevolve403…` | OK |
| (2) logout e tela de troca permitidos | `sair` não usa `comSessao`; `testSairContinuaPermitido` | OK |
| (2) senha atual + nova + confirmação, regras vigentes, nova ≠ atual, outras sessões expiram | `testTrocaInvalidaMantemAMarca` (provider), `testTrocaRemoveAMarcaMantemASessaoAtualEEncerraAsOutras`, CSRF recusado | OK |
| (3) marca cai ao trocar, recuperar e convite | `testTrocarASenhaRemove…`, `testRecuperarPorLinkLimpaAMarca`, `testAceitarConviteNaoDeixaMarca` | OK |
| (4) mensagem em português, acessível | `testTelaDeTrocaTemRotulosFocoESemMenu` (rótulos, foco, alerta ligado ao form); funciona sem JS; `Cache-Control: no-store` | OK |
| (5) docs e CHANGELOG | `recuperar-acesso.md`, `usuarios-e-permissoes.md`, `INVENTARIO.md`, CHANGELOG `[Não lançado]` | OK |

## Execução (neste ambiente)
| Comando | Resultado |
|---|---|
| `phpunit tests/SenhaProvisoriaTest.php` | 13 testes, 38 asserções, OK |
| `phpunit tests/SenhaProvisoriaPainelTest.php` | 13 testes, 90 asserções, OK (1 depreciação do PHPUnit, não funcional) |
| `phpunit tests/M021Test.php`, `tests/ClassesPadraoTest.php` (ajustados) | OK (2 e 23 testes) |
| `phpstan analyse` | sem erros |
| `php -l` nos arquivos PHP alterados | sem erros |

## Análise de risco
- Contorno por outra entrada: só `Painel` usa sessões (`Aplicacao` → `Painel`); o site público não aceita a sessão. Sem rota de painel fora de `comSessao`/`api`, salvo entrar, sair e fluxos públicos de conta.
- O estado da marca é lido do banco a cada requisição, então marcar uma conta já logada barra a sessão dela na próxima requisição.
- Auditoria registra só o booleano, nunca a senha.
- Contas existentes: padrão 0, sem mudança; código antigo ignora a coluna (rollback seguro).

## Pendências / limitações
1. Suíte completa não rodada aqui (regra da etapa): o CI deve rodá-la. Risco baixo: só os testes de `M021` e `ClassesPadrao` precisaram de ajuste.
2. Migração M029 não aplicada em banco real (sem permissão): rodar `bin/pbl atualizar` (ou o comando de migração do projeto) no ambiente de destino; rollback: `ALTER TABLE usuario DROP COLUMN senha_provisoria; DELETE FROM schema_versao WHERE versao = 29;`.
3. Sem navegador aqui: o E2E visual da tela fica para o QA E2E (checar foco inicial, leitor de tela e leitura do alerta de erro).
4. `.github/` e `deploy/` não foram tocados (conferido em `git status`).

## Distribuição
- **QA Funcional:** fluxo completo login provisório → troca → painel; erros (senhas diferentes, igual à atual, curta, atual errada, bloqueio por tentativas); recuperar senha e convite; CLI nos três comandos.
- **QA E2E:** duas abas (a outra expira ao trocar), teclado e leitor de tela na tela de troca, tela em 320 px.

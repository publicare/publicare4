# ADR 0033 — Login e contas: inventário e escopo (Fase 1 × Fase 2)

Status: aceito; Fase 1 entregue em fatias (ver "Estado da entrega").

## Contexto
O [ADR 0011](0011-autenticacao-sessao-permissoes.md) adiou recuperação de senha por e-mail, 2FA e visitantes. Na instalação da Veadeiros não havia como recuperar senha ou usuário sem alguém no servidor.

## Inventário (main @ 9c54b1e)
| Área | Já existia (reusado) | Faltava |
|---|---|---|
| Senha | `SenhaPolitica`; `UsuarioRepositorio` (argon2id, `definirSenha` encerra sessões, `definirAtivo`, `ehUltimoAdministradorAtivo`); hash fictício no login (tempo constante) | fluxo por token, troca pela própria pessoa, forçar troca |
| Sessão | `SessaoRepositorio` (só hash, 30 min/12 h), `SessaoAtiva`, `Csrf` (token pré-sessão), `ModoElevado` | listar com IP/dispositivo, encerrar uma |
| Limites | `LimiteDeTentativas` (login), `LimiteDeEnvios` (por formulário) | limite por conta e IP para recuperação |
| Contas | criar/atualizar/ativar, `TelasDeContas`, CLI `conta-senha`/`conta-destravar`/`usuario`, LGPD (`DadosDeConta`) | e-mail único, convite, perfil, falhas por conta |
| Auditoria | `Auditoria::registrar` | ações novas |
| E-mail | nada | transporte, configuração, textos |

## Decisão
Fase 1 entra: e-mail plugável; esqueci senha e usuário; convite; perfil (senha, e-mail, nome, sessões); admin (forçar troca, ativar/desativar, destravar, falhas); CLI equivalente; docs. Reusar o que existe; `LimiteDeEnvios` e o fluxo de login não mudam de assinatura. Fase 2 só é desenhada ([ADR 0038](0038-login-e-contas-fase-2.md)). Fora: escolher provedor de e-mail, release, `.github/`, `deploy/` e chaves de assinatura.

## Estado da entrega
| Fatia | Estado |
|---|---|
| E-mail: transporte nulo, arquivo e SMTP, configuração com senha cifrada, migração M028 | **feito** (domínio, testes) |
| Tokens de uso único, esqueci senha e usuário, limite, fila, uniformidade | **feito** (domínio, testes, cron) |
| Convite (criar, reenviar, cancelar, aceitar) | **feito** (domínio, testes) |
| CLI `conta-recuperar` e `conta-convidar` | **feito** |
| LGPD cobre `token_conta`; cron limpa tokens e processa a fila | **feito** |
| Telas HTTP: "Esqueci a senha/usuário" no login, redefinir, aceitar convite, Mais › E-mail do site, convite no painel (`TelasPublicasDeConta`, `TelasDeConvite`, `TelasDeEmail`) | **feito** (testes em `TelasDeContaTest`) |
| Perfil próprio: nome e troca de senha (pede a atual, mantém a sessão atual) (`TelasDePerfil`) | **feito** |
| Sessões listáveis (M029), troca de e-mail com dupla confirmação, forçar troca, falhas por conta, destravar no painel, `conta-sessoes` | **pendente** ([ADR 0037](0037-perfil-sessoes-e-operacoes-de-admin.md)) |

## Consequências
- Sem e-mail configurado, nada do fluxo por e-mail fica disponível; login, CLI e redefinição por administrador seguem valendo.
- Cada fatia pendente depende só de serviços já testados.

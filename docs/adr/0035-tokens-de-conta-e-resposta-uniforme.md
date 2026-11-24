# ADR 0035 — Tokens de conta de uso único e resposta uniforme

Status: aceito (implementado no domínio e no cron).

## Decisão
**Tokens** (`TokenDeConta`, tabela `token_conta`, migração M028): tipos `senha`, `convite`, `email_novo`, `email_antigo`. 32 bytes de `random_bytes` (64 hex, 256 bits); só o `sha256` vai ao banco (índice único). Validade: 30 min (senha e e-mail) e **72 h para o convite** (desvio da demanda, constante `VALIDADE_CONVITE`; pendência de aprovação do dono). Emitir invalida os anteriores do mesmo tipo e usuário; trocar a senha invalida todos os de senha. `consultar` (GET) não consome; `consumir` é um `UPDATE ... WHERE usado_em IS NULL AND expira_em > ?` conferindo as linhas afetadas. Token malformado, de outro tipo, usado ou expirado: mesma resposta genérica. O cron apaga expirados; anonimizar apaga os tokens da conta.

**Resposta uniforme** (`RecuperacaoDeAcesso`): `solicitar()` faz sempre o mesmo trabalho barato (registra o limite e grava um job 0600 em `dados/email/fila/`) e devolve sempre `MENSAGEM_UNIFORME`. A busca da conta, o token e o envio rodam em `processarFila()` (tarefa `limpeza` do cron ou depois da resposta), então nem corpo nem tempo revelam se a conta existe, está inativa, de serviço, com convite pendente, sem e-mail ou limitada. Único caso distinto: e-mail desligado na instalação (não depende da conta).

**Limite** (`LimiteDeRecuperacao`): 5 pedidos por conta e 20 por IP em 15 min, sobre `tentativa_login` com chaves HMAC `rec|...`. Conta o identificador mesmo se não existir. Estourar não muda a resposta: só omite o envio e audita `recuperacao.limitada`.

**Regras**: e-mail com várias contas recebe um link por conta; "esqueci usuário" lista todos os logins do e-mail e não emite token. Redefinir confere a política **antes** de consumir (senha fraca não queima o link), encerra as sessões, invalida os outros tokens e avisa por e-mail. Auditoria: `recuperacao.enviada`, `.limitada`, `.concluida`, `.falha`, `.emitida`, sem token, identificador nem IP em claro.

## Rotas e cabeçalhos (a implementar na fatia de telas)
`GET/POST /_pbl/redefinir-senha/<token>`, `/_pbl/convite/<token>` e `/_pbl/esqueci`, `/_pbl/esqueci-usuario`. Todo POST com CSRF pré-sessão (finalidade própria) e Origin; páginas com token enviam `Cache-Control: no-store` e `Referrer-Policy: no-referrer`; CSP sem script nem `style` inline (regra do painel, `Pagina::protegida`); o CSRF pré-sessão reaproveita `Csrf::paraEntrar`.

## Consequências
- Vazamento do banco não permite usar tokens; scanner de e-mail não queima o link.
- Os jobs da fila guardam o identificador digitado até processar (0600, apagados em seguida).

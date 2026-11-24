# ADR 0038 — Fase 2 de login e contas (apenas desenho)

Status: proposto. Sem código; cada item vira ADR próprio ao ser implementado.

1. **2FA TOTP** (RFC 6238) opcional por usuário; segredo cifrado como a senha SMTP ([ADR 0034](0034-transporte-de-email.md)); 10 códigos de recuperação só como hash e de uso único; segundo passo entre autenticar e criar a sessão, com limite de tentativas próprio.
2. **Lembrar este dispositivo**: cookie com token aleatório (hash no banco), validade configurável, revogável na lista de sessões.
3. **Expiração de senha** opcional: tarefa do cron marca `senha_trocar` após N dias.
4. **Bloqueio por país/IP**: lista em configuração de acesso e base geográfica local, avaliada antes do login, com contorno pela CLI.
5. **Login social/OIDC**: nova implementação da interface `Autenticador` ([ADR 0011](0011-autenticacao-sessao-permissoes.md)), vínculo por emissor e `sub`.
6. **Visitantes cadastrados** ([ADR 0004](0004-identidade-publica.md)): população separada de `usuario`, sem acesso ao painel, reaproveitando `TokenDeConta` e `Transporte`.

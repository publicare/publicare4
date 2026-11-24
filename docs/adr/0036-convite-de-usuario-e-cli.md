# ADR 0036 — Convite de usuário e paridade na CLI

Status: aceito (domínio e CLI implementados; telas pendentes).

## Decisão
- `ConviteDeUsuario`: o administrador informa login, nome, e-mail e papel. A conta nasce com `senha_hash = '!convite'` (nunca confere) e `convite_pendente = 1`; não entra até aceitar. O link (token `convite`, 72 h) leva à definição da senha (`SenhaPolitica`); aceitar limpa a pendência e grava `email_confirmado_em`. Reenviar invalida o link anterior; cancelar apaga a conta se ainda pendente e nunca usada. E-mail e login precisam ser novos (e-mail comparado em minúsculas). Sem e-mail configurado, o convite exige o modo "sem envio" (o link é entregue pelo operador).
- Migração M028 também acrescenta `usuario.convite_pendente` e `usuario.email_confirmado_em`.
- CLI (ator `cli`, nunca imprime senha): `conta-recuperar <login> [--email]` emite o link de senha (impresso uma vez, ou enviado); `conta-convidar <login> <email> --nome= [--admin] [--sem-envio]`. O link impresso é segredo de uso único e a saída diz isso. Sem domínio principal, os comandos recusam.
- Auditoria: `usuario.convidar`, `convite.reenviar`, `convite.cancelar`, `convite.aceitar`.

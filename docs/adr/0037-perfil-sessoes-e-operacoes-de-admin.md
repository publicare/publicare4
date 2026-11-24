# ADR 0037 — Perfil, sessões listáveis e operações de administração (desenho)

Status: proposto; **não implementado nesta entrega** (ver ADR 0033).

- **M029**: `sessao` ganha `ref` (16 hex, id público opaco), `ip_hash` (HMAC), `ip_aproximado` (/24 ou /48) e `agente` (navegador e SO reduzidos). A tela lista as sessões do usuário logado, marca a atual, encerra uma (por `ref`, sempre filtrando por `usuario_id`; ref alheia dá 404) ou todas as outras.
- **Perfil**: trocar senha pede a atual (`autenticar` + `LimiteDeTentativas` no espaço `elevado`), aplica `SenhaPolitica` e encerra as demais sessões. Trocar e-mail exige senha atual, envia link `email_novo` ao novo endereço e aviso `email_antigo` ao antigo; só efetiva com as duas confirmações (os tipos já existem em `TokenDeConta`). Nome é troca simples com CSRF.
- **Admin**: `usuario.senha_trocar` (forçar troca no próximo login, com guarda no painel), ativar/desativar, destravar (já existe `LimiteDeTentativas::liberarLogin`), tabela `acesso_falho` (sem o login digitado em claro; retenção de 90 dias) e último acesso. Tudo exige administrador, CSRF, Origin e modo elevado, e não deixa o site sem administrador ativo (`ehUltimoAdministradorAtivo`).
- **CLI**: `conta-sessoes <login> [--encerrar] [--todas]`.

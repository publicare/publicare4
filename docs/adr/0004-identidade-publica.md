# ADR 0004 — Identidade pública (visitantes cadastrados)

Status: proposto (2026-11-01). Complementa o [ADR 0003](0003-plataforma-low-code.md).

## Contexto

Consultas públicas, inscrição em eventos, avaliações, biblioteca com acesso restrito e denúncias com acompanhamento exigem que **visitantes** se identifiquem (ou participem de forma anônima, mas protegida). No PBL3 isso era refeito a cada sistema, com tabelas e scripts próprios. Misturar visitantes com os usuários do admin ampliaria a superfície de ataque e confundiria perfis.

## Decisão

1. **Duas populações separadas**: `usuario` (equipe, perfis por subárvore, admin) e `participante` (público). Participante nunca acessa o admin e nunca tem perfil de edição.
2. **Cadastro e verificação**: e-mail + token de uso único com prazo; senha opcional (acesso por link mágico quando não houver senha). Hash `argon2id`/`bcrypt`.
3. **Três modos de participação**, escolhidos por formulário/classe: anônimo (com captcha e limite por IP), identificado (exige cadastro verificado) e identificado com dados extras (CPF, vínculo etc. como campos do formulário).
4. **Escopo por site**: participante pertence a um site (domínio); o mesmo e-mail pode existir em sites diferentes. Compartilhar entre sites é opção.
5. **Dados pessoais (LGPD)**: finalidade e consentimento registrados por formulário; exportação e exclusão/anonimização pelo próprio participante ou pelo admin; campos sensíveis marcados na classe e fora de listagens, de busca e de exportação por padrão.
6. **Proteções**: CSRF, limitação de tentativas por e-mail e IP, captcha plugável, auditoria de acesso a dados pessoais.
7. **Participante ↔ objeto**: contribuições, inscrições e avaliações são objetos comuns (classe do sistema) com referência ao participante, e passam pelo workflow de moderação do ADR 0003.

## Consequências

- Uma base única de cadastro público reutilizada por todos os sistemas pequenos.
- Mais responsabilidade com dados pessoais: exige retenção configurável e relatório de tratamento.
- Autenticação do admin (fluxo 6) permanece independente.

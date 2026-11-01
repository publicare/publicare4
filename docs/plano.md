# Plano e questões em aberto

## Fases (revisadas pelos ADRs 0003 a 0005)

| Fase | Entrega | Critério de pronto |
|---|---|---|
| **0 — Tracer bullet** | Docker de dev; instalar com SQLite; criar classe → criar objeto → renderizar com template PBL4 → publicar → servir do cache | fluxo ponta a ponta com testes |
| **1 — Núcleo de dados** | `objeto` + JSON, caminho materializado, `objeto_indice`, hidratação em lote, motor de consulta, versões, sites e domínios | consultas dos 3 contextos sobre o mesmo motor |
| **2 — PBL4 (linguagem)** | Lexer, Parser, gramática, sandbox, escape, herança de layout, cache compilado; conversor de moldes PBL3 | moldes de teste convertem e renderizam |
| **3 — Segurança e acesso** | Autenticação moderna, CSRF, permissões por subárvore e campo, auditoria, aplicação sem DDL | revisão de segurança sem críticos |
| **4 — Instalador e atualizador** | Assistente web + CLI, SQLite por padrão, modelos prontos, `install.lock`; layout `app/`+`dados/`; atualizador com manifesto assinado, backup e rollback (ADR 0006) | leigo instala em hospedagem compartilhada e atualiza com um clique, com rollback testado |
| **5 — Admin e editor de peles** | API-first, admin, editor de peles (blocos + PBL), versionamento, import/export, galeria de peles por CDN, pele filha (ADR 0007) | instalar pele da galeria ou de arquivo, personalizar e reverter sem FTP |
| **6 — Fluxos e formulários** | Estados e transições como dado, formulários declarativos, notificações, agregações (fluxo 10) | sistema pequeno (cadastro com aprovação, enquete) montado sem código |
| **7 — Identidade pública** | `participante`, cadastro por token, modos anônimo/identificado, LGPD (ADR 0004) | consulta pública com cadastro funcionando |
| **8 — Pagamentos e módulos** | Interface de gateway, cobrança, webhooks idempotentes, conector simulado, pontos de extensão (ADR 0005) | evento com inscrição paga ponta a ponta com conector de teste |
| **9 — Importador PBL3** | Conteúdo, classes, versões, usuários, blobs; teste com bases do corpus | site real migrado e conferido |

## Questões em aberto

1. **Multi-subsite**: resolvido no desenho (domínio → raiz, `caminho` materializado, URL canônica; fluxos 1 e 9). Falta validar com sites reais: curinga e migração de objetos entre sites.
2. **Nomes físicos** (opção de instalação, ADR 0003): mapa lógico→físico na configuração. Testar com prefixos arbitrários sem depender de Oracle.
3. **Corpus real**: existe em repositórios **privados** do mantenedor (centenas de moldes PBL e dumps). Não entra neste repo público; ver nota abaixo.
4. **Branch mais recente do p3**: `master` (2021-03), `psr4`, `3.6.0`, `v3.x`; confirmar de qual partir.
5. **Peles por FTP**: política de sincronização com `pele_arquivo`.
6. **Busca**: driver padrão (fluxo 8).
7. **Licença/governança**: p3 é MIT; manter. Coautores históricos (Manuel Poppe, Thiago Borges) preservados nos créditos.
8. **Nome público**: "PBL4" vs. "Publicare 4" nos instaladores e na documentação.
9. **Fronteira do low-code**: validar com 2 ou 3 sistemas reais (consulta pública, evento pago, biblioteca) o que cabe em configuração e o que vira módulo.
10. **Pagamentos**: quais conectores entram primeiro (PIX/cartão/boleto); fiscal e recorrência ficam fora.
11. **LGPD**: política padrão de retenção e anonimização de participantes.
14. **Marketplace (ADR 0008)**: só com tração; definir licenciamento, loja, termos e divisão de receita antes de abrir a autores.
13. **Peles**: formato final do `pele.json`, custódia da chave do catálogo, política para peles de comunidade.
12. **Atualizador**: custódia da chave de assinatura, pacote diferencial, política de rollback de migrações (fluxo 11).

## Corpus de regressão (nota)

Moldes e dumps de projetos reais são conteúdo institucional e **não são commitados aqui**. Os golden files usam moldes sanitizados/sintéticos; um repositório privado separado guarda o corpus real, usado só em CI/local do mantenedor. Um template PBL3 com design system gov.br já público pode servir de pele de exemplo para o instalador.

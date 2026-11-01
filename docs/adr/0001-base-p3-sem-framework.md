# ADR 0001 — publicare3 como base; sem framework full-stack

Status: proposto (2026-11-01)

## Contexto

Dois candidatos a base: o `publicare3` (20k linhas, 49 telas de admin, workflow, peles, multi-driver, schema usado por MCTI/EPL/ANTT/PSB/Presidência) e o rascunho `publicare4` (1,4k linhas: migrações e seeds, sem HTTP/parser/admin, IDs UUID, sem `pele`/`parentesco`/`status`/`campo_ref`).

## Decisão

1. **Base = código e modelo de dados do publicare3**, modernizado por partes (strangler), cada refatoração precedida de teste de caracterização.
2. **O rascunho p4 é descartado.** Aproveita-se só Docker e a ideia de DBAL.
3. **Sem Laravel/Symfony full-stack.** O produto precisa instalar por FTP em hospedagem compartilhada, por leigo; o ORM briga com EAV + `campo_ref`; o PBL é a identidade do produto e concorreria com Blade/Twig.
4. **Peças soltas via Composer**: `symfony/http-foundation`, `doctrine/dbal` (no lugar do ADOdb), `symfony/console` (instalador CLI/migrações), `pimple/pimple`, `phpunit`, `phpstan`. O `vendor/` vai pronto no zip de distribuição.
5. **Alvo PHP 8.2+**; bancos suportados: MySQL 8 e PostgreSQL. Oracle fica sem suporte declarado até haver cliente (não testável no ambiente de dev).

## Consequências

- Compatibilidade de dados com sites p3 existentes (script de migração simples).
- Dívida do p3 (classes de 2k linhas, páginas procedurais) é paga incrementalmente.
- Se um dia o foco virar SaaS hospedado, reavaliar framework.

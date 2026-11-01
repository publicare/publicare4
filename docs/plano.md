# Plano e questões em aberto

## Fases (revisadas pelo ADR 0003)

| Fase | Entrega | Critério de pronto |
|---|---|---|
| **0 — Tracer bullet** | Docker de dev; instalar com SQLite; criar classe → criar objeto → renderizar com template PBL4 → publicar → servir do cache | fluxo ponta a ponta com testes |
| **1 — Núcleo de dados** | `objeto` + JSON, caminho materializado, `objeto_indice`, hidratação em lote, motor de consulta, versões, sites e domínios | consultas dos 3 contextos sobre o mesmo motor |
| **2 — PBL4 (linguagem)** | Lexer, Parser, gramática, sandbox, escape, herança de layout, cache compilado; conversor de moldes PBL3 | moldes de teste convertem e renderizam |
| **3 — Segurança e acesso** | Autenticação moderna, CSRF, permissões por subárvore e campo, auditoria, aplicação sem DDL | revisão de segurança sem críticos |
| **4 — Instalador** | Assistente web + CLI, SQLite por padrão, modelos prontos, `install.lock` | leigo instala em hospedagem compartilhada |
| **5 — Admin e editor de peles** | API-first, admin, editor de peles (blocos + PBL), versionamento, import/export | editar e reverter pele sem FTP |
| **6 — Fluxos e formulários** | Estados e transições como dado, formulários declarativos, notificações, participação pública | sistema pequeno (cadastro com aprovação) montado sem código |
| **7 — Importador PBL3** | Conteúdo, classes, versões, usuários, blobs; teste com bases do corpus | site real migrado e conferido |

## Questões em aberto

1. **Multi-subsite**: mecanismo conhecido (host → `cod_objeto` raiz, ajuste de `parentesco`; tabela `dominio`, ADR 0002). Falta especificar o corte do parentesco, objeto em mais de um domínio e canônico, curinga.
2. **Nomes físicos** (agora opção de instalação, ADR 0003): `bd.tabelas` (config) é a indireção real; `campo_ref` aponta metadado do `objeto`. Testar com um mapa de nomes arbitrários (ex.: prefixos aleatórios) sem depender de Oracle.
3. **Corpus real**: existe em repositórios **privados** do mantenedor (centenas de moldes PBL e dumps). Não entra neste repo público; ver nota abaixo.
4. **Branch mais recente do p3**: `master` (2021-03), `psr4`, `3.6.0`, `v3.x`; confirmar de qual partir.
5. **Peles por FTP**: política de sincronização com `pele_arquivo`.
6. **Busca**: driver padrão (fluxo 8).
7. **Licença/governança**: p3 é MIT; manter. Coautores históricos (Manuel Poppe, Thiago Borges) preservados nos créditos.
8. **Nome público**: "PBL4" vs. "Publicare 4" nos instaladores e na documentação.

## Corpus de regressão (nota)

Moldes e dumps de projetos reais são conteúdo institucional e **não são commitados aqui**. Os golden files usam moldes sanitizados/sintéticos; um repositório privado separado guarda o corpus real, usado só em CI/local do mantenedor. Um template PBL3 com design system gov.br já público pode servir de pele de exemplo para o instalador.

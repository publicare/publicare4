# Plano e questões em aberto

## Fases

| Fase | Entrega | Critério de pronto |
|---|---|---|
| **0 — Base e segurança** | Docker de dev (php 8.2 + MySQL/Postgres); p3 portado para a estrutura do PBL4 rodando; correções críticas: path traversal (`/include/font`, `/html/objects`), upload, regex de permissão, `password_hash`, CSRF, SQL parametrizado nas rotas expostas, sem `var_dump` ao visitante | portal de seed sobe em container; suíte de segurança verde |
| **1 — Rede de testes + parser** | Corpus de moldes e golden files; Lexer, Parser, Gerador; gramática EBNF; erros como exceção | saída idêntica ao p3 no corpus |
| **2 — Templates maduros** | Cache compilado, escape por padrão (+ migrador de moldes), herança de layout, `TemplateResolver` | sem `eval` por request; layouts com blocos |
| **3 — Instalador** | Assistente web + CLI, migrações, seeds, pele inicial, `install.lock` | leigo instala em hospedagem compartilhada sem terminal |
| **4 — Editor de peles** | Editor, validação, preview, versionamento, import/export, assistente "criar pele" | editar e reverter pele sem tocar em FTP |
| **5 — Core** | Repositórios com DBAL, tipos 8.2, fim da duplicação `Banco`/`DBLayer`, phpstan e CI | sem concatenação de SQL |

Ordem de dependência: 0 → 1 → (2, 3 em paralelo) → 4 → 5 contínuo.

## Questões em aberto

1. **Multi-subsite**: como domínios/subsites coexistem hoje (um `config/global.php` por vhost sobre o mesmo banco?). Define o escopo de `url_amigavel` e de `configuracao`.
2. **`campo_ref`**: semântica real (mapa lógico → físico) e como testá-la sem Oracle.
3. **Corpus real**: existe backup de moldes de MCTI/ANTT/EPL/PSB para regressão?
4. **Branch mais recente do p3**: `master` (2021-03), `psr4`, `3.6.0`, `v3.x`; confirmar de qual partir.
5. **Peles por FTP**: política de sincronização com `pele_arquivo`.
6. **Busca**: driver padrão (fluxo 8).
7. **Licença/governança**: p3 é MIT; manter. Coautores históricos (Manuel Poppe, Thiago Borges) preservados nos créditos.
8. **Nome público**: "PBL4" vs. "Publicare 4" nos instaladores e na documentação.

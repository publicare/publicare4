# ADR 0002 — Schema: evolução aditiva sobre o modelo do p3

Status: proposto (2026-11-01)

## Decisão

Manter o modelo do p3 (nomes, tipos, EAV, `parentesco`, `versaoobjeto`, `campo_ref`). Mudanças são **aditivas ou correções pontuais**, para que bancos p3 migrem por script.

### Manter
`classe`, `classexfilhos`, `propriedade` (com `campo_ref`), `tipodado`, `objeto`, `tbl_*` (blob, boolean, date, float, integer, objref, string, text), `parentesco`, `versaoobjeto`, `status`, `perfil`, `infoperfil`, `usuarioxobjetoxperfil`, `tag`/`tagxobjeto`, `pele`, `pilha`, `pendencia`, `logobjeto`, `logworkflow`.

### Corrigir
| # | Item | Ação |
|---|---|---|
| 1 | Dois dumps divergentes (`db/` e `docs/db`), MySQL com `CREATE SCHEMA`/`USE`/`VISIBLE` | Schema passa a ser **migrações PHP versionadas (DBAL)** gerando MySQL e Postgres; tabela `schema_versao` |
| 2 | `usuario.senha varchar(32)` (md5) | Alargar para 255; `password_hash` com rehash no login |
| 3 | `ix_objeto_descricao` (btree em `text`) | Remover (estoura no Postgres) |
| 4 | Índices simples separados nas `tbl_*` | Índice composto `(cod_objeto, cod_propriedade)` |
| 5 | Integridade | Confirmar e adicionar FKs; unique para `url_amigavel` e valor único por (objeto, propriedade) quando a propriedade não é multivalorada; fim do `SELECT MAX()` como insertId |
| 6 | `versaoobjeto.data_criacao` é `timestamp`, resto é `bigint` epoch | Padronizar |

### Adicionar
| Tabela | Finalidade |
|---|---|
| `schema_versao` | controle de migrações |
| `dominio` (host, cod_objeto_raiz, cod_pele_padrao, principal, ativo) | mapeia domínio/subdomínio → objeto raiz do subsite (ver abaixo); vários hosts podem apontar para a mesma raiz (alias com/sem `www`, domínios alternativos) |
| `configuracao` (chave, valor, escopo) | configuração do portal editável no admin; reduz dependência de `config/global.php` |
| `pele_arquivo` (cod_pele, caminho, tipo, conteudo, hash) | conteúdo das peles/templates no banco |
| `pele_arquivo_versao` (arquivo, conteudo, cod_usuario, data, hash) | histórico, diff, rollback |
| `tentativa_login` (login, ip, data) | rate limit no servidor |
| `auditoria` (cod_usuario, acao, alvo, data, ip) | edição de pele/template e ações sensíveis |

## Questões em aberto

- **Multi-subsite (resolvido em princípio):** cada domínio/subdomínio aponta para um `cod_objeto` que é a **raiz daquele subsite** (no 2.9.2: `$_dominios` em `source/iniciar.php:165`, host → `cod_objeto`; o p3 regrediu para um único `portal.objroot`). O inicializador identifica o host, define a raiz e **ajusta o parentesco** (a árvore passa a ser vista a partir dessa raiz). No PBL4 isso sai do arquivo de config e vira a tabela `dominio`. Falta especificar o ajuste de parentesco com exatidão (ver fluxo 1, "Raiz do domínio").
- **Nomes físicos configuráveis — correção:** a indireção que permite nomes de tabela/coluna arbitrários **não** é `propriedade.campo_ref`. É o mapa de configuração `bd.tabelas` (lógico → físico, tabela e colunas), já presente no p3 (`DBLayer`/`Config`). O `campo_ref` é outra coisa: nas propriedades de referência, indica uma coluna de metadado do `objeto` (`AdminObjeto::EMetadado`). No PBL4 o **mapa lógico→físico vira cidadão de primeira classe** (um `NameResolver` usado por todo acesso a banco via DBAL) e a semântica de `campo_ref` é preservada como está.
- **Peles no banco vs. disco:** `pele_arquivo` é a fonte; o disco vira cache compilado. Definir a política de sincronização para quem edita por FTP.

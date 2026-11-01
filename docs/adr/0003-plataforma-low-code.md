# ADR 0003 — PBL4 é uma plataforma low-code de sites, dados e fluxos

Status: proposto (2026-11-01). Substitui o ADR 0002 e os itens 1–2 do ADR 0001. Complementado pelos ADRs [0004](0004-identidade-publica.md) (identidade pública) e [0005](0005-pagamentos-conectores.md) (pagamentos e conectores).

## Contexto

O PBL nunca foi só um CMS. Além de portais e intranets, a mesma estrutura (classes definidas pelo admin, árvore de objetos, perfis por subárvore, workflow) sustentou pequenos sistemas de gestão e fluxos de dados: cadastros internos, consultas públicas, formulários, calendários, moderação de comentários, relatórios. Cada fluxo novo, porém, exigia um script PHP avulso. O PBL3 tem ideias boas e uma implementação de 20 anos: EAV em 8 tabelas com muitos joins, `eval` por request, admin procedural sem API, tabelas temporárias para hidratar referências.

O PBL4 não é uma modernização do PBL3: mantém as ideias, troca a implementação.

## Decisão

### 1. Definição do produto
Plataforma para **montar sites e pequenos sistemas de dados e fluxos sem código**: tipos de dado (classes), formulários declarativos, listas/filtros/relatórios, fluxos com estados e notificações, participação pública, API JSON. O portal é um uso entre outros. Limite declarado: sistemas pequenos a médios.

Usos típicos que o núcleo deve cobrir sem código: consulta pública e enquetes; biblioteca virtual (acervo com anexos, busca e facetas); eventos com programação (salas, mesas, palestrantes, valores), inscrição, pagamento e avaliação; cadastros internos com aprovação; formulários de contato e denúncia com moderação.

| Declarativo (configuração) | Extensão por código (módulo) |
|---|---|
| classes e campos, validações, formulários | conectores de pagamento (ADR 0005) |
| estados, transições, prazos, quem aprova | cálculos e regras entre várias tabelas |
| listagens, filtros, relatórios, exportação | integrações com sistemas externos |
| notificações por e-mail, permissões | tipos de campo e comandos PBL novos |

O que passar do limite usa pontos de extensão (módulos com interface estável e ganchos), não scripts soltos nem inflar o núcleo.

### 2. Armazenamento
- **`objeto`** com os dados em coluna **JSON**, validados pela definição da classe; **caminho materializado** (`/1/45/230/`) no lugar de `parentesco`, indexado por prefixo; versões em tabela própria. O caminho cumpre os dois papéis do `parentesco`: exibir a árvore a partir da raiz do site e **reduzir a população de registros nas buscas** (toda consulta é limitada a uma subárvore). Ver fluxo 9.
- **`objeto_indice`**: tabela de **schema fixo** com colunas tipadas (texto, número, data, id de referência), preenchida ao salvar só para propriedades marcadas "indexável". Serve a filtros e ordenação sem os 8 joins do EAV.
- **Sem DDL em runtime por padrão.** O usuário de banco da aplicação precisa apenas de `SELECT/INSERT/UPDATE/DELETE`. Criar classe, campo ou fluxo é inserir configuração.
- **Tabelas físicas por classe** (alto volume): recurso opcional e desligado por padrão, com credencial de migração separada fora da config do site e **modo de revisão** que só exporta o SQL para um DBA aplicar. Identificadores gerados, tipos em lista fechada, sem `DROP` pela interface, pré-visualização, backup e auditoria.
- **SQLite** como padrão de instalação leiga (zero configuração); MySQL 8 e PostgreSQL para portais grandes. Oracle sem suporte declarado.
- **Nomes físicos configuráveis** (prefixo/mapa lógico→físico) como opção de instalação, não como requisito do núcleo.
- Sem cluster/sharding agora. Escala, na ordem: índices e cache de página publicada, busca fora do banco, particionamento (Postgres), réplica de leitura.

### 3. Referências entre objetos
- Uma propriedade de referência guarda o **id** (e opcionalmente um *snapshot* de campos de exibição, p.ex. título).
- A carga é **em lote** (`expand`, profundidade máxima configurável), não objeto a objeto: coleta os ids referenciados, busca todos de uma vez e hidrata. Substitui as tabelas temporárias do PBL3.

### 4. Motor de consulta único
Uma só definição de consulta (filtros, ordem, limite, `expand`) usada pela **busca do portal, pelos filtros do admin e pelos templates/relatórios**. Filtros sobre objeto referenciado usam consulta em duas etapas ou `WITH`; resultados caros podem ir para cache. Nunca tabela temporária física.

### 5. Sites
`site` é entidade: vários domínios → uma raiz, idioma, pele padrão. A unicidade de `url_amigavel` é **por site** (`UNIQUE (site_id, url_amigavel)`), nunca global: o mesmo endereço pode existir em portais diferentes que dividem o banco. Objetos pertencem a um site; **classes, templates e peles são globais** (reutilizáveis entre sites, com sobrescrita por site).

### 6. Fluxos e formulários como dados
Estados, transições, quem aprova, prazos e notificações são definidos por classe ou pasta. Formulários públicos e internos são declarativos (campos, validação, anexos, captcha). Notificação por e-mail, depois WhatsApp/webhook. Participação pública (cadastro, token, moderação) é capacidade do núcleo (ADR 0004); inscrição paga usa conectores (ADR 0005). Ver fluxo 10. Modelos prontos no instalador (consulta pública, cadastro, almoxarifado…).

### 7. Templates
Linguagem PBL em português, mas bem construída: Lexer, Parser, gramática, sandbox, escape por padrão, herança de layout, compilação com cache. Edição de leigo por **blocos visuais**; PBL no modo avançado. Um **conversor** traduz moldes PBL3 (não há modo de execução legado).

### 8. Renderização
Página publicada é renderizada ao publicar e servida do cache; apenas busca, formulários e áreas autenticadas são dinâmicos.

### 9. API e admin
**API JSON primeiro**; o admin consome a mesma API (assets pré-compilados distribuídos no zip, instalável por FTP). Permissões por subárvore e por campo; auditoria de ações sensíveis.

### 10. Migração
**Importador PBL3 → PBL4** (conteúdo, classes, propriedades, versões, usuários, blobs), em vez de rodar o schema antigo. Dumps e moldes reais servem de teste do importador e do conversor (corpus privado, ver `plano.md`).

### 11. Mantido do ADR 0001
Sem framework full-stack; peças soltas via Composer; PHP 8.2+; `vendor/` no zip; instalável por FTP em hospedagem compartilhada.

## Consequências

- Início mais lento que modernizar o p3 in-place (sem migração incremental), com produto final bem diferente do PBL3.
- Segurança vira critério de aceite: aplicação sem DDL, sandbox de templates, auditoria.
- ADR 0002 (schema aditivo sobre o p3) fica substituído; suas tabelas novas (`dominio`→`site`, `configuracao`, `pele_arquivo*`, `tentativa_login`, `auditoria`, `schema_versao`) permanecem como ideia.
- Os fluxos 1–8 descrevem o PBL3 e o problema; leia-os junto com este ADR. O fluxo 9 detalha armazenamento e consulta.

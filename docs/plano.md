# Plano e questões em aberto

## Escopo por etapa

| Etapa | Fases | Por quê |
|---|---|---|
| **Núcleo mínimo (MVP)** | 0, 1, 2, 3, 4 | produto instalável, seguro e que se atualiza: dados, linguagem PBL4 com cache, segurança básica, instalador com pele inicial e atualizador (ADR 0006). Segurança entra no MVP porque o produto nasce exposto na internet. |
| **Em seguida** | 5, 6, 9 | editor/galeria de peles (ADR 0007), formulários e fluxos declarativos (a identidade low-code), importador PBL3 para migrar sites existentes e peles de saída JSON/headless (ADR 0009) |
| **Após tração** | 7, 8 e ADR 0008 | identidade pública, pagamentos e módulos, peles pagas e comunidade: só com demanda real |

Decisões que **não podem esperar** o MVP, porque custam muito mais depois: **tipo de saída da pele** no manifesto e no cache (ADR 0009), layout `app/` + `dados/`, chave pública de assinatura embutida e verificação de pacote, `objeto` JSON + `caminho` materializado, aplicação sem DDL em runtime, sandbox e escape no PBL4.

Primeiro critério de validação: um site real hoje em PBL3 rodando no PBL4 (via conversor de moldes e, depois, importador).

## Fases (revisadas pelos ADRs 0003 a 0009)

| Fase | Entrega | Critério de pronto |
|---|---|---|
| **0 — Tracer bullet** | Docker de dev; instalar com SQLite; criar classe → criar objeto → renderizar com template PBL4 → publicar → servir do cache | fluxo ponta a ponta com testes |
| **1 — Núcleo de dados** | `objeto` + JSON, caminho materializado, `objeto_indice`, hidratação em lote, motor de consulta, versões, sites e domínios. **1a feita**: tipos `ref`/`data`/`booleano`, motor único, ordenar e filtrar pelo objeto referenciado, `expandir`, `#temfilhos`. **1b feita**: versões de objeto, histórico de URLs com 301, mover, domínios com papel (principal/servir/redirecionar) e regras manuais de redirecionamento | consultas dos 3 contextos sobre o mesmo motor |
| **1c — Busca** | Nível A (normalização) **feito**; nível B **feito** (várias palavras, prefixo, plural, relevância, `BuscaInterface` com driver SQL padrão, tabela `objeto_termo`); nível C **feito** (sugestão de grafia parecida, `%SUGESTAO`, opcional por site, sem tabela nova). Falta o driver para portais grandes ([ADR 0010](adr/0010-busca.md)) | buscar `presid` acha `Presidência` (**feito**); `Fernandha` sugere `Fernanda` (**feito**) |
| **2 — PBL4 (linguagem)** | Lexer, Parser, gramática, sandbox, escape, herança de layout, cache compilado; conversor de moldes PBL3 (**feito**: 2a, 2b, 2c; faltam blobs e `config`, que esperam upload e configuração de site) | moldes de teste convertem e renderizam |
| **3 — Segurança e acesso** | **3 feita** (3a: contas, senhas, perfis por subárvore, permissões, autoria, auditoria, CLI; 3b: sessão, login, CSRF, limite de tentativas, cabeçalhos, segredo). Meta: autenticação moderna, CSRF, permissões por subárvore e campo, auditoria, aplicação sem DDL | revisão de segurança sem críticos |
| **4 — Instalador e atualizador** | **4a feita** (assistente web, CLI com administrador, `.htaccess`); **4b feita pela linha de comando** (atualizador assinado, empacotador, volta atrás automática se a atualização falhar; falta a chave de produção); **4c feita** (tela e botão "Atualizar agora" no painel, com senha de novo, processo em segundo plano e trava); **4d feita** (`bin/pbl reverter` e botão: desfaz uma atualização **já concluída**, devolvendo código e banco de antes dela — perde o que foi criado depois, por isso pede confirmação explícita —, recusando se o banco mudou de forma incompatível desde então; aviso de versão nova na página inicial lendo só o cache; `bin/pbl verificar-atualizacao` para o cron). Assistente web + CLI, SQLite por padrão, modelos prontos, `install.lock`; layout `app/`+`dados/`; atualizador com manifesto assinado, backup e rollback (ADR 0006) | leigo instala em hospedagem compartilhada e atualiza com um clique, com rollback testado, e volta atrás se decidir que a atualização não serviu |
| **5 — Admin e editor de peles** | **5a feita** (painel com os sites, navegação na árvore, editar título e descrição com versão otimista e republicação, Bootstrap 5 local sem JavaScript; [ADR 0012](adr/0012-administracao-web.md)); **5c feita** (tipos de conteúdo: listar, criar, editar campos com arquivamento em vez de apagar, apagar tipo sem uso; só administrador). **5b feita** (conteúdo: criar só dos tipos aceitos, editar todas as propriedades, estado e datas, mover, lixeira, histórico com restaurar, lista com ordem e filtros). **5e feita** (redirecionamentos e peles: editar moldes numa caixa de texto com validação pelo parser, criar e apagar moldes, pele ativa; sem editor com realce, pré-visualização nem histórico de moldes). **5d feita** (contas: criar, editar, desativar, redefinir senha, papéis por seção; domínios: adicionar, principal, alias ou redirecionador, remover). **5g feita** (regras de tipos aceitos e obrigatórios no repositório, HTML limpo por lista de permissões, publicação agendada com `bin/pbl agendados`, mover pela árvore). O editor de peles completo e a galeria continuam planejados. API-first, admin, editor de peles (blocos + PBL), versionamento, import/export, galeria de peles por CDN, pele filha (ADR 0007) | instalar pele da galeria ou de arquivo, personalizar e reverter sem FTP |
| **6 — Fluxos e formulários** | **6a feita** (formulário público declarativo: tipo `formulario`, comando `<@formulario@>`, envio anônimo privado com CSRF/origem, armadilha, limite por IP, tamanho e auditoria sem valores, tela de envios; [ADR 0013](adr/0013-formularios-publicos.md)). Faltam estados e transições como dado, notificações, captcha, anexos, prazos, agregações, editor de formulário e, na fase 7, participante identificado (fluxo 10) | sistema pequeno (cadastro com aprovação, enquete) montado sem código |
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
12. **Atualizador**: custódia da chave de assinatura, pacote diferencial (fluxo 11). A volta atrás de uma atualização concluída existe (`bin/pbl reverter`, fase 4d): como hoje nenhuma migração é destrutiva (ADR 0006 §8), basta devolver código e banco; falta decidir o que fazer quando existir migração destrutiva de verdade (negar sempre, ou marcar a migração como "não reversível" e checar isso também).

## Corpus de regressão (nota)

Moldes e dumps de projetos reais são conteúdo institucional e **não são commitados aqui**. Os golden files usam moldes sanitizados/sintéticos; um repositório privado separado guarda o corpus real, usado só em CI/local do mantenedor. Um template PBL3 com design system gov.br já público pode servir de pele de exemplo para o instalador.

# ADR 0028 — Suporte a MySQL e PostgreSQL

Status: Proposto; as decisões D1 a D14 usam os padrões sugeridos no dossiê, aceitos em 2026-11-16 pela coordenação e a confirmar pelo dono (D11, sobre a hospedagem e a data da migração do site em SQLite, continua a informar). Complementa o [ADR 0003](0003-plataforma-low-code.md) (armazenamento, sem DDL em runtime), o [ADR 0002](0002-schema-evolucao-aditiva.md) (esquema evolui só acrescentando) e o [ADR 0006](0006-atualizador-automatico.md) (atualização e migrações); a chave e o manifesto assinado seguem o [ADR 0019](0019-custodia-da-chave-de-assinatura.md). Prepara a versão 0.2: **o 0.1.0 oficial sai só com SQLite; MySQL e PostgreSQL chegam na 0.2**.

## Contexto

Hoje só o SQLite funciona. `Conexao` só abre `pdo_sqlite`; o instalador, o atualizador e o reversor tratam o banco como um arquivo (cópia por `VACUUM INTO`, troca por `rename`); e a documentação cita MySQL e PostgreSQL como previstos. O código de consulta já é quase todo portável: usa o DBAL, as datas são texto ISO, o JSON é tratado em PHP e a busca é SQL puro, sem FTS. O trabalho está na fábrica de conexão, nas migrações, no backup e na restauração, no instalador e nos testes.

Três restrições moldam a decisão:
- O público inclui quem instala por FTP em hospedagem compartilhada e não usa terminal. O SQLite continua o padrão simples.
- O ADR 0003 diz que a aplicação, em operação, não executa DDL.
- As atualizações trazem migrações, que precisam de DDL, e o usuário de operação não o terá.

## Decisão

1. **Camada de dialeto pequena sobre o DBAL.** Interface `Dialeto`, com implementações para SQLite, MySQL 8 e PostgreSQL, em `Pbl\Banco\Dialeto`. Ela cobre as opções de conexão, as opções de tabela, o suporte a DDL transacional, o limite de identificador, a trava de migração, a detecção de deadlock e a tradução de erros para mensagens compreensíveis. O SQL dos repositórios continua no subconjunto comum. Um teste de arquitetura proíbe `PRAGMA`, `INSERT OR`, `ON CONFLICT`, `json_extract`, `AUTOINCREMENT`, `RETURNING` e `sqlite_` fora de `Banco/Dialeto` e `Banco/Copia`.

2. **Migrações com a API `Schema` do DBAL**, como hoje, mais um subconjunto cru permitido (`ALTER TABLE … ADD COLUMN`, `CREATE INDEX`, `UPDATE`). Cada migração é dividida em passos idempotentes. Onde o DDL é transacional (SQLite e PostgreSQL), todas as pendentes rodam numa transação. No MySQL, que comita DDL sozinho, rodam sem transação e a versão só é gravada ao fim, de modo que reexecutar retoma o que faltou. Antes de migrar, o runner obtém uma trava de banco (`GET_LOCK` ou `pg_try_advisory_lock`) contra migrações simultâneas e detecta lacunas de versão.

3. **Tipos e colação.** JSON continua `TEXT` tratado em PHP. Datas continuam texto ISO em UTC. Carimbos em epoch viram `BIGINT`. Colunas de chave, comparação e ordem usam colação binária (`utf8mb4_bin` no MySQL, `"C"` no PostgreSQL), para dar o mesmo resultado do SQLite e manter o índice de prefixo utilizável. O MySQL usa `utf8mb4`, `InnoDB`, `sql_mode` estrito e fuso UTC.

4. **Configuração e credenciais.** O banco é escolhido por `dados/config/banco.json` (modo 0600, fora de `public/`, gravado de forma atômica), com sobrescrita por variáveis `PBL_BANCO_*` (as variáveis vencem o arquivo) e `PBL_BANCO_ARQUIVO` para guardá-lo fora da raiz web. Sem o arquivo, o sistema usa o SQLite em `dados/pbl4.sqlite`, e as instalações existentes seguem sem mudança. Só as credenciais do **usuário de operação** (`SELECT`, `INSERT`, `UPDATE`, `DELETE`; no PostgreSQL também `USAGE` e `SELECT` nas sequências e permissões padrão para tabelas futuras) ficam em disco. O **usuário de instalação** (DDL e DML) é pedido no assistente ou na CLI, vive só em memória e nunca é gravado. Se ele puder criar usuários, o assistente oferece criar o usuário de operação com senha aleatória; senão, pede os dados de um usuário já criado. O **modo simples**, com um usuário só, é permitido com aviso claro, confirmação explícita e banner permanente no painel, e fica registrado em `banco.json`. O TLS tem três modos (`desligado`, `exigir`, `verificar`); `verificar` é o recomendado para host remoto. O assistente confirma se o TLS está de fato em uso e avisa quando o host é remoto sem TLS.

5. **Atualização com DDL.** O manifesto assinado (chave de uso, [ADR 0019](0019-custodia-da-chave-de-assinatura.md)) já traz o campo `migracoes` ([ADR 0006](0006-atualizador-automatico.md)); a credencial de estrutura só é pedida quando ele é verdadeiro e o banco não é SQLite nem está em modo simples. O padrão é pedir de novo o usuário com DDL, uma vez por atualização, sem gravar: no painel (com o modo elevado) e na CLI (`bin/pbl atualizar`, prompt oculto ou variável do ambiente do processo). A credencial passa ao processo filho pelo stdin, aberto por `proc_open` direto, com `env` mínimo e explícito, nunca por argumentos, disco, sessão ou log. O lançador atual (`sh -c … &`, stdin em `/dev/null`) é reestruturado para isso. Para quem tem terminal, existe a opção de um usuário de migração separado, guardado fora do alcance do PHP web e usado só pela CLI; ela só protege se a CLI rodar com outro usuário de sistema. O modo simples dispensa o pedido. Antes de ligar a manutenção, baixar ou copiar qualquer coisa, um pré-voo testa os privilégios por tentativa real (criar, alterar, indexar, DML); se falhar, nada é alterado. No PostgreSQL a sonda roda numa transação revertida. Depois de migrar, o sistema aplica os GRANTs das tabelas novas e reconecta como usuário de operação para conferi-los.

6. **Assistente e CLI.** O assistente web ganha o passo de banco (SQLite como padrão), um teste de conexão com checklist em linguagem simples e a criação ou escolha do usuário de operação. O teste de conexão exige prova de posse e CSRF, bloqueia hosts de link-local e de metadados de nuvem, aceita IP privado só com confirmação, traduz os erros e limita tentativas. `bin/pbl instalar` ganha as opções equivalentes; senhas só por prompt oculto, ambiente ou stdin, nunca por flag.

7. **Backup, reversão e demo.** Em MySQL e PostgreSQL o backup é um dump lógico gerado em PHP (formato próprio, com checksum, sem as tabelas efêmeras). Ele é obrigatório (estende o backup do [ADR 0006](0006-atualizador-automatico.md)): se falhar, a atualização não começa. O arquivo tem nome com sufixo aleatório, modo 0600 e retenção limitada. Reverter exige a credencial de estrutura. A demo continua só em SQLite.

8. **Migração entre bancos.** `bin/pbl banco migrar` copia os dados preservando ids, com simulação, validação de UTF-8 e de tamanhos, checksum por tabela, reinício de sequências e troca reversível da configuração. A origem fica guardada, sem ser apagada, por um prazo definido.

9. **Testes.** A suíte inteira roda em SQLite, MySQL 8 e PostgreSQL 16 (serviços em contêiner no GitHub Actions). Só os testes que dependem do arquivo SQLite ficam num grupo à parte. Cada teste usa um banco isolado, clonado de um modelo migrado uma vez. Um teste de contrato de dialeto roda nos três bancos para evitar divergência de comportamento.

## Decisões D1 a D14

Padrões adotados enquanto o dono não confirma. D11 continua a informar.

| # | Decisão | Padrão adotado | Bloqueia |
|---|---|---|---|
| D1 | MariaDB entra na 0.2? | **Sim** (revisto em 2026-11-26, a pedido do dono): é o banco da maioria das hospedagens compartilhadas, usa o mesmo driver `pdo_mysql` e o DBAL escolhe a plataforma MariaDB pela versão do servidor | CI e mensagens |
| D2 | Versões mínimas | Testar MySQL 8.4, MariaDB 10.11 (LTS) e PostgreSQL 16; MySQL 8.0, MariaDB 11.4 e PostgreSQL 14 em agenda semanal | a0 |
| D3 | Prefixo de tabelas e schema para banco compartilhado | Sem prefixo na 0.2; PostgreSQL com schema opcional | a1 (formato do `banco.json`) |
| D4 | Usuário de migração separado entra na 0.2? | Depois da 0.2 | e3 |
| D5 | Cron e atualização automática com migração | Release com migração exige humano | e2 |
| D6 | Ordenação | Binária agora, linguística depois (`titulo_norm`) | c |
| D7 | Backup em PHP ou `mysqldump`; limite de tamanho no painel | PHP como padrão, atalho opcional | f |
| D8 | Demo | Só em SQLite | f |
| D9 | Editar M008, M013 e M014 no lugar (epoch `BIGINT`, boolean) | Sim | b |
| D10 | Aviso do modo simples e cron nesse modo | Aviso permanente e sem cron automático | d2, e2 |
| D11 | Hospedagem, tamanho e data da migração do site em SQLite | **A informar** | ensaio E5 |
| D12 | Retenção de backups e do `.sqlite` migrado (LGPD) | 7 a 30 dias, com remoção explícita | f, g |
| D13 | Hosts privados no teste de conexão | Aceitar com confirmação | d2 |
| D14 | Auditoria do restante do aplicativo | Projeto de segurança próprio, fora da 0.2 | – |

## Consequências

- O SQLite continua com zero configuração. MySQL e PostgreSQL passam a existir sem DDL em operação.
- A atualização que muda o esquema deixa de ser totalmente automática em MySQL e PostgreSQL: pede credencial de estrutura, ou usa a opção da CLI. Atualizações sem esquema novo continuam automáticas.
- No MySQL, uma migração interrompida pode deixar estado intermediário. Isso é aceito porque as migrações só acrescentam e o código antigo continua funcionando (ADR 0006 §8), desde que cada migração seja reentrante.
- A ordem textual é binária, como hoje no SQLite. A ordenação linguística fica para depois.
- Em hospedagem compartilhada, quem invadir o PHP lê o `banco.json` e só consegue DML, salvo no modo simples.
- Há mais manutenção: três bancos na CI, mais tempo de teste e o dump lógico.
- MariaDB é alvo declarado na 0.2 (revisão da D1): a CI testa também uma versão LTS dele e as mensagens falam "MySQL ou MariaDB". Pontos a vigiar: colação e tamanho de índice em versões antigas; a trava de sessão (`GET_LOCK`) é a mesma.
- Backups e a cópia da origem criam novas cópias de dados pessoais; a retenção precisa ser definida e documentada.

## Alternativas descartadas

- **Um ORM ou tradutor de SQL.** Trocaria um controle que já existe por uma dependência grande, e o DBAL já cobre o necessário.
- **SQL de migração por dialeto como padrão.** Triplica as 24 migrações e abre divergência de esquema. Fica só como exceção.
- **Tipos nativos `JSON`/`JSONB` e de data.** Reordenam chaves, quebram hashes e divergem do SQLite.
- **Guardar o usuário DDL cifrado em disco.** A chave teria de ficar num lugar acessível ao PHP web, e isso não resolve.
- **Só modo simples.** Contradiz o ADR 0003 e amplia o dano de uma invasão.
- **Usuário de migração separado como padrão.** Só protege onde a CLI roda com outro usuário de sistema, o que em hospedagem compartilhada quase nunca ocorre.
- **FTS5, FULLTEXT e `tsvector` como padrão da busca.** Seguem como drivers opcionais (ADR 0010), não como requisito.
- **Dump com `mysqldump` e `pg_dump` como padrão.** Nem sempre existem ou são executáveis em hospedagem compartilhada. Ficam como atalho opcional.

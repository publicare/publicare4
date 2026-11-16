# Banco de dados: camada de dialeto e `banco.json`

Esta página descreve a **fundação** do multi-banco ([ADR 0028](../adr/0028-suporte-mysql-postgresql.md)). Hoje o produto só funciona com SQLite; os outros bancos são previstos para a 0.2 e **não estão disponíveis para uso** (ADR 0028, 0.2).

## O que existe hoje

- A interface `Pbl\Banco\Dialeto\Dialeto` reúne o que muda de um banco para outro. Há três implementações: `DialetoSqlite` (o único em uso) e duas previstas para a 0.2 (ADR 0028), sem migrações nem instalador.
- `Pbl\Banco\Conexao::abrir(ConfigBanco)` abre o banco pelo dialeto escolhido. `Conexao::sqlite()` e `Conexao::memoria()` seguem como sempre: instalações existentes não mudam.
- `Pbl\Banco\ConfigBanco` é o objeto de valor (imutável, validado) da configuração; `CarregadorDeConfigBanco` e `GravadorDeConfigBanco` leem e gravam o `banco.json`.
- `Servicos::doBancoInstalado` já abre o banco pela configuração. Instalador, migração, atualizador e reversor continuam só com SQLite nesta etapa.

## O que a interface `Dialeto` cobre

| Método | Para quê |
|---|---|
| `opcoesDeConexao`, `aposConectar` | Parâmetros do DBAL e ajustes de sessão (UTC, modo estrito, schema, conferência de TLS) |
| `opcoesDeTabela` | Charset, engine e colação das tabelas (previstos para a 0.2, ADR 0028) |
| `ddlTransacional` | O banco desfaz DDL num rollback? SQLite sim; o banco previsto para a 0.2 com DDL que comita sozinho, não (ADR 0028) |
| `limiteDeIdentificador` | 0 (sem limite) no SQLite; 64 e 63 nos bancos previstos (ADR 0028) |
| `obterTrava`, `soltarTrava` | Trava de migração: no SQLite, `flock` em `<pasta do banco>/<nome>.lock`; nos previstos, trava de sessão do servidor (ADR 0028) |
| `ehDeadlock` | Detecta deadlock (no SQLite, sempre falso: `SQLITE_BUSY` é "banco ocupado") |
| `traduzirErro` | Mensagem em português, de forma fechada: nunca repete o texto do driver, o usuário, o host nem a senha |

O `Migrador` ainda **não** usa a trava; ela existe e é provada pelo teste de contrato. Ligá-la pertence ao projeto das migrações (ADR 0028).

## `dados/config/banco.json`

Sem o arquivo e sem variáveis, vale o SQLite em `dados/pbl4.sqlite`. O arquivo é gravado com modo 0600, de forma atômica (como `dados/config/segredo`), e se lê com aviso (não erro) se a permissão for mais frouxa.

| Campo | Variável | Observação |
|---|---|---|
| `driver` | `PBL_BANCO_DRIVER` | `sqlite` (padrão) ou os previstos para a 0.2 (ADR 0028): `mysql`, `pgsql` |
| `host`, `porta` | `PBL_BANCO_HOST`, `PBL_BANCO_PORTA` | Só para os drivers previstos (ADR 0028). Porta padrão: 3306 e 5432 |
| `nome` | `PBL_BANCO_NOME` | Nome do banco (drivers previstos, ADR 0028); no SQLite, caminho absoluto do arquivo (opcional) |
| `usuario`, `senha` | `PBL_BANCO_USUARIO`, `PBL_BANCO_SENHA` | Usuário de **operação** (DML). Nunca o de instalação |
| `schema` | `PBL_BANCO_SCHEMA` | Opcional, só no driver `pgsql` (ADR 0028, D3) |
| `tls` | `PBL_BANCO_TLS` | `desligado` (padrão), `exigir` ou `verificar` |
| `modo_simples` | `PBL_BANCO_MODO_SIMPLES` | Verdadeiro ou falso (`1`, `true`, `sim`, `0`, `false`, `nao`) |

Regras:

- **As variáveis vencem o arquivo**, campo a campo. `PBL_BANCO_ARQUIVO` aponta o caminho absoluto do **próprio `banco.json`** (para guardá-lo fora da raiz web); ele não escolhe o arquivo SQLite.
- Chave desconhecida no arquivo é erro. Não existe campo para o usuário de instalação/DDL: ele nunca é lido do arquivo nem gravado.
- A validação nomeia o campo errado e nunca mostra valores. A senha não aparece em exceção, `var_dump`, `print_r`, serialização, auditoria nem saída. `Conexao::abrir` traduz a falha de conexão e **não encadeia** a exceção do driver.
- Exemplo (previsto para a 0.2, ADR 0028; o comentário `//` é só explicação, o arquivo real não aceita comentários):

```jsonc
{
  "driver": "pgsql",  // previsto na 0.2
  "host": "127.0.0.1",
  "porta": 5432,
  "nome": "pbl",
  "usuario": "pbl_operacao",
  "senha": "…",
  "tls": "verificar",
  "modo_simples": false
}
```

## Regra de SQL portável

`tests/Arquitetura/SqlPortavelTest.php` proíbe `PRAGMA`, `INSERT OR`, `ON CONFLICT`, `json_extract`, `AUTOINCREMENT`, `RETURNING`, `sqlite_` e `VACUUM` em `app/src`, exceto em `Banco/Dialeto` e `Banco/Copia`. O SQL específico do SQLite (cópia por `VACUUM INTO`, `PRAGMA integrity_check`, checkpoint do WAL) mora em `Pbl\Banco\Copia\CopiaSqlite`.

## Teste de contrato e CI

`tests/Banco/ContratoDeDialetoTest.php` (grupo `contrato-dialeto`) roda o mesmo roteiro em cada banco: conectar, criar/ler/apagar tabela, transação, DDL transacional conforme o declarado, erro traduzido, senha errada sem vazamento e trava entre sessões. Sem `PBL_BANCO_*`, roda em SQLite (localmente). O CI sobe os serviços de teste do ADR 0028 e o executa nos três bancos; a suíte principal segue em SQLite.

```sh
vendor/bin/phpunit --group contrato-dialeto
```

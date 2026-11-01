# Guia de desenvolvimento

## Preparar o ambiente

Só é preciso **Docker** (com o plugin `compose`) e **make**.

```bash
git clone https://github.com/publicare/publicare4.git
cd publicare4
make instalar      # constrói a imagem (PHP 8.3) e roda composer install
make qa            # testes + análise estática
```

## Comandos do dia a dia

| Comando | O que faz |
|---|---|
| `make qa` | `make teste` e `make analise`: o que a pipeline roda |
| `make teste` | PHPUnit |
| `make analise` | PHPStan nível 8 |
| `make pbl ARGS="…"` | executa `bin/pbl` no container (veja a [linha de comando](cli.md)) |
| `make subir` / `make parar` | servidor de desenvolvimento em <http://localhost:8080> / para o ambiente |
| `make shell` | shell dentro do container |

Para testar outros bancos: `docker compose --profile mysql up -d` ou `--profile postgres` (hoje os testes automáticos usam SQLite).

## Ver funcionando

```bash
make pbl ARGS="instalar --nome=Demo"   # cria dados/, banco SQLite, pele básica e um site de exemplo
make pbl ARGS=publicar                 # renderiza as páginas para o cache
make subir                             # http://localhost:8080
```

A pasta `dados/` é do site (banco, cache, peles, uploads) e **não vai para o Git**. Para recomeçar: `rm -rf dados`.

## Estrutura do repositório

```
app/src/        código do produto
  Banco/        conexão, migrador e migrações (M001…)
  Conteudo/     Classe, Objeto, Site e seus repositórios
  Linguagem/    Lexer, Parser, Gerador, Runtime, Compilador (PBL4)
  Publicacao/   Renderizador, Publicador, fonte de dados dos moldes
  Conversao/    conversor de moldes PBL3 → PBL4
  Instalacao/   instalador
  Console/      comandos de bin/pbl
app/recursos/   arquivos que viajam com o produto (pele básica)
bin/pbl         linha de comando
public/         front controller (index.php)
tests/          PHPUnit
docs/           documentação (usuário, técnica, ADRs, fluxos)
```

A divisão `app/` (substituível) e `dados/` (do site) é intencional: o futuro atualizador troca `app/` e `vendor/` sem tocar em `dados/` ([ADR 0006](../adr/0006-atualizador-automatico.md)).

## Como escrever um teste

- Teste em `tests/`, classe `…Test`, com nomes de método que dizem o comportamento (`testPaginaVencidaDeixaDeSerServida…`).
- Banco: `Conexao::memoria()` + `new Migrador($con)->migrar()` dá um SQLite limpo por teste.
- Disco: use `Ajuda::pastaTemporaria()` e `Ajuda::apagar()`.
- Fluxo completo (instalar → publicar → servir): veja `tests/TracerBulletTest.php`.
- Entrada hostil é obrigatória para qualquer coisa que receba dado de fora (URL, parâmetro de molde, arquivo).

## Pipeline

Todo PR roda `.github/workflows/ci.yml`: testes em PHP 8.2/8.3/8.4, PHPStan, `composer validate` e `composer audit`, e a conferência do CHANGELOG. A `main` só aceita merge com tudo verde. Detalhes em [CONTRIBUTING.md](../../CONTRIBUTING.md).

## Dependências

Poucas e justificadas ([ADR 0001](../adr/0001-base-p3-sem-framework.md)): Doctrine DBAL (banco), Symfony Console e HttpFoundation. Toda dependência nova precisa de motivo no PR. O Dependabot abre PRs de atualização (composer semanal, com as versões menores e correções agrupadas num PR só; GitHub Actions mensal) e eles são dispensados da exigência de CHANGELOG. Antes de mergear um deles, confira o que a nova versão muda e se a pipeline passou. Mergear um PR que altera `.github/workflows/` pelo `gh` exige que o token tenha o escopo `workflow` (`gh auth refresh -h github.com -s workflow`); pelo site do GitHub não.

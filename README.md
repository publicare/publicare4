# PBL4 (Publicare 4)

Plataforma livre para **sites, dados e fluxos**, sucessora do Publicare/PBL3. Pensada para quem monta portais e pequenos sistemas (consulta pública, biblioteca, eventos, cadastros) **sem programar**, em hospedagem comum, com moldes de aparência seguros e atualização simples.

> **Estado:** em construção (versão `0.x`, ainda sem lançamento). Já funcionam o modelo de conteúdo, a linguagem de moldes, a publicação em cache e o conversor de moldes do PBL3. A equipe já entra pelo navegador (`/_pbl/entrar`), e já cuida do conteúdo, dos tipos de conteúdo, das contas e dos domínios pelo navegador; **faltam** as telas de redirecionamentos e de peles. Veja o [estado do projeto](docs/usuario/estado-do-projeto.md).

## Por onde começar

| Você é… | Vá para |
|---|---|
| Quem vai **usar** o Publicare | [docs/usuario](docs/usuario/README.md) · [Como instalar](docs/usuario/instalar.md) |
| Quem **instala ou desenvolve** | [docs/tecnica](docs/tecnica/README.md) |
| Quem quer **colaborar** | [CONTRIBUTING.md](CONTRIBUTING.md) |
| Quem achou uma **falha de segurança** | [SECURITY.md](SECURITY.md) |
| Quem acompanha **novidades** | [CHANGELOG.md](CHANGELOG.md) e [releases](https://github.com/publicare/publicare4/releases) |

Todo o índice, com decisões de design e plano: [docs/README.md](docs/README.md).

## Ver funcionando em 3 minutos

Só precisa de **Docker** e **make** (o computador não precisa ter PHP).

```bash
make instalar                          # constrói o ambiente e instala as dependências
make pbl ARGS="instalar --nome=Demo"   # cria o banco SQLite e um site de exemplo
make pbl ARGS=publicar                 # renderiza as páginas para o cache
make subir                             # abre em http://localhost:8080
make qa                                # testes + análise estática
```

## Versão e qualidade

- Versionamento Semântico, versão em [`app/src/Versao.php`](app/src/Versao.php): [docs/versionamento.md](docs/versionamento.md).
- Nada entra na `main` sem passar pela pipeline (testes em PHP 8.2/8.3/8.4, PHPStan nível 8, auditoria de dependências, changelog): [`.github/workflows/ci.yml`](.github/workflows/ci.yml).

## Layout

```
app/src/   código do produto (substituível pelo atualizador, ADR 0006)
dados/     banco, cache, peles e uploads do site (fora do git; nunca tocado pela atualização)
public/    front controller
bin/pbl    linha de comando
tests/     PHPUnit
docs/      documentação (usuário, técnica, ADRs, fluxos)
```

## Licença

[MIT](LICENSE). Os créditos históricos do Publicare são preservados.

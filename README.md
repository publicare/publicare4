# PBL4 (Publicare 4)

Plataforma low-code de sites, dados e fluxos, sucessora do Publicare/PBL3. O design está em [`docs/`](docs/README.md) (ADRs 0003 a 0009, fluxos e plano por etapa).

Estado: **fase 0 (tracer bullet) concluída**: instalar (SQLite) → classe e objetos → molde PBL4 → publicar → servir do cache. Ainda não há admin, autenticação nem editor de peles.

## Desenvolvimento

O host não precisa de PHP: tudo roda em container (PHP 8.3, SQLite por padrão).

```bash
make instalar   # constrói a imagem e instala as dependências
make qa         # testes (PHPUnit) + análise estática (PHPStan nível 8)
make subir      # servidor de desenvolvimento em http://localhost:8080
make pbl ARGS=versao

# fluxo ponta a ponta
make pbl ARGS="instalar --nome=Demo"
make pbl ARGS=publicar
make subir          # http://localhost:8080
```

Outros bancos para teste: `docker compose --profile mysql up -d` ou `--profile postgres`.

## Layout

```
app/src/   código do produto (substituível pelo atualizador, ADR 0006)
dados/     config, uploads, peles do site, cache, SQLite (nunca tocado pela atualização; fora do git)
public/    front controller
bin/pbl    linha de comando
tests/     PHPUnit
docs/      design
```

## Licença

MIT.

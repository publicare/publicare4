# ADR 0041 — Manual como segundo site, com edições por prefixo

Status: aceito.

## Decisão
O manual é um site próprio da mesma instalação (pele `manual`). A raiz é a edição estável, `/beta/` a pré-release e `/<versão>/` guarda cada release; a versão só aparece no endereço e no seletor. Tag sem hífen atualiza raiz e `/<versão>/`; com hífen, `/beta/` e `/<versão>/`. A busca recebe `ed=<edição>` para não misturar edições. O passo da release está em `manual/release.sh` e `docs/tecnica/manual.md`; o workflow é do operador (não se toca em `.github/`).

## Consequências
Uma instalação atende todas as edições; o estável duplica o conteúdo da última versão (aceito).

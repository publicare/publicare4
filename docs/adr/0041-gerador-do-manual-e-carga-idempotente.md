# ADR 0041 — `bin/pbl manual-gerar`: compilação e carga idempotente

Status: aceito.

## Contexto
É preciso converter `docs/usuario` em conteúdo PBL4 e recarregá-lo a cada release sem perder correções feitas no painel. Não há biblioteca Markdown no projeto (ADR 0001, sem dependência nova).

## Decisão
- Etapa A (sem banco): `Pbl\Manual\Compilador` e conversor Markdown restrito ao subconjunto usado geram `manual/gerado/manual.json`. Pesos de 10 em 10, links `.md` reescritos, tabela de conteúdo, anterior/próximo. Link ou âncora quebrados, página órfã, front matter inválido e imagem sem alt interrompem.
- Etapa B: `Pbl\Manual\Carga` cria as classes `manual_edicao`, `manual_capitulo` e `manual_pagina` e grava pelo `ServicoDeConteudo` (permissões, versões, auditoria). Idempotente: igual não toca; editado à mão é preservado e listado (`--forcar` sobrescreve com versão nova); sumiu da fonte é despublicado. `--simular` não grava; a carga real exige `--confirmar`; cópia prévia em `dados/manual/`.

## Consequências
Compilação testável sem banco; conversor próprio limita a sintaxe dos guias (um ADR pode adotar biblioteca depois).

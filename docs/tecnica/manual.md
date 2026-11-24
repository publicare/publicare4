# Manual de uso: como é gerado e publicado

O manual de uso é a pasta `docs/usuario/` (fonte única, [ADR 0040](../adr/0040-fonte-unica-do-manual.md)). A cada release ele vira conteúdo do próprio PBL4, num site à parte, e é publicado em `https://manual.publicare.org/` (endereço a confirmar com o dono antes de publicar; DNS e servidor são do operador).

## Escrever

- Cada guia tem front matter simples (`titulo`, `resumo` até 200 caracteres, `capitulo`, `ordem` único no capítulo; opcionais `slug` e `publico: contribuicao` para ficar fora do manual). A árvore de capítulos está em `docs/usuario/capitulos.md`.
- Texto por tarefa, sem datas e sem número de versão: valores que mudam apontam para o instalador e o `CHANGELOG`.
- Links entre guias: `outro-guia.md#ancora`. Imagens: só `![texto alternativo](captura:<id>)`, com o `<id>` em `docs/usuario/capturas.json`. Não há PNG no repositório.
- Mudou uma tela ou um comando? Atualize `docs/usuario/INVENTARIO.md` e o guia (um teste cobra).

## Gerar

```
php bin/pbl manual-gerar --so-compilar --sem-capturas        # só valida e compila (sem banco); desenvolvimento
php bin/pbl manual-gerar --so-compilar --capturas=<pasta>    # com as capturas reais
php bin/pbl manual-gerar --site=manual --como=<conta de serviço> --simular
php bin/pbl manual-gerar --site=manual --como=<conta de serviço> --confirmar
```

A compilação falha com link ou âncora quebrados, página órfã, front matter inválido, imagem sem alt ou captura faltando. A carga é idempotente: o que não mudou não é tocado; páginas editadas à mão no painel são preservadas e listadas (`--forcar` sobrescreve, gravando versão nova); páginas que saíram da fonte são despublicadas, nunca apagadas. Antes de gravar, uma cópia do que será tocado vai para `dados/manual/`; antes da primeira carga, faça também `bin/pbl backup`. O primeiro site do manual pode ser criado com `--criar-site=<domínio> --confirmar`.

## Edições

Estável na raiz do site; pré-release (tag com hífen) em `/beta/`; cada release guarda `/<versão>/` (a versão aparece só no endereço e no seletor, nunca no texto). `--edicao=auto` (padrão) decide pela versão (`--versao=`, que vem da tag sem o `v`).

## Capturas de tela automáticas

`php manual/capturas.php` monta os pedidos de captura (v2) das telas citadas. A esteira da prévia (`previa/gerar-pacote.php`, com contas fictícias) as executa e entrega uma pasta de `<id>.png`, que entra na geração por `--capturas=`. **Premissa registrada:** o contrato do pedido v2 mora na esteira, fora deste repositório; o formato está isolado em `Pbl\Manual\PedidoV2::montar` e deve ser ajustado ali se divergir. Capturas só de contas fictícias da prévia, nunca de instalação real.

## Passo da release (para o operador instalar)

Este repositório não traz o workflow (não se mexe em `.github/` aqui). O passo que o workflow, acionado por tag `v*`, deve chamar depois de rodar a esteira de capturas:

```
MANUAL_SITE=manual MANUAL_CONTA=manual-bot manual/release.sh "$GITHUB_REF_NAME" capturas/
```

O operador cria a conta de serviço e guarda a credencial como segredo do workflow; nada disso fica no repositório. 

## Pele do manual

`app/recursos/peles/manual/`: menu lateral por capítulo, seletor de edições, busca (`/busca?q=…&ed=<edição>`, sem JavaScript), tema claro/escuro (segue o sistema; botão guarda a escolha), versão para impressão (CSS `@media print`), landmarks, foco visível, sem CDN e sem estilo ou script embutido. A pele `publicare` (atividade 63a47517) ainda não está na main; por isso o manual tem layout próprio sobre a base de moldes. **Adaptação quando ela chegar:** trocar o miolo do `layout.pbl` por `<@estende "publicare"@>` sobrescrevendo os blocos `lateral` e `miolo`, mantendo `manual_pagina.pbl`, `manual_capitulo.pbl`, `busca.pbl` e os assets do manual.

## Link Ajuda no painel

O painel mostra "Ajuda (manual)" apontando para a configuração `manual_url` do site (aba Comportamento; padrão `https://manual.publicare.org/`; só https, ou http em localhost).

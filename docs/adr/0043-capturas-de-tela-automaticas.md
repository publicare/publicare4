# ADR 0043 — Capturas de tela automáticas

Status: aceito.

## Decisão
Nenhum PNG no repositório. Os guias usam `![alt](captura:<id>)`, com `<id>` em `docs/usuario/capturas.json`. `manual/capturas.php` gera o pedido de captura v2 para a esteira da prévia (`previa/gerar-pacote.php`, contas fictícias); a pasta de PNG entra por `--capturas=`. Captura faltando falha a geração, salvo `--sem-capturas` (quadro de texto, só desenvolvimento).

## Premissa
O contrato do pedido v2 está fora deste repositório; o formato fica isolado em `PedidoV2::montar`.

## Consequências
Imagens acompanham a versão; a esteira de capturas precisa rodar antes da geração no release.

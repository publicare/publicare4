# ADR 0044 — Link Ajuda (manual) no painel

Status: aceito.

## Decisão
Configuração por site `manual_url` (tipo `url_https`, aba Comportamento, padrão `https://manual.publicare.org/`). Só https (http em localhost), sem credenciais, sem espaços, até 200 caracteres, terminada em `/`; vazio volta ao padrão. O painel mostra "Ajuda (manual)" com `target="_blank" rel="noopener noreferrer"` e aviso de nova aba para leitores de tela. Sem chamada de rede ao endereço.

## Consequências
O operador aponta para outro domínio sem mudar código; até o domínio existir, o link leva a um destino inexistente (o dono confirma o endereço antes de publicar).

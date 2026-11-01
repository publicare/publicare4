# ADR 0005 — Pagamentos e conectores

Status: proposto (2026-11-01). Complementa o [ADR 0003](0003-plataforma-low-code.md).

## Contexto

Eventos e cursos com inscrição paga já foram feitos sobre o PBL, com gateway de pagamento escolhido caso a caso e fluxo de confirmação escrito à mão. Pagamento é o ponto em que "sem código" mais esbarra em integração externa, e o que menos convém no núcleo: cada provedor muda API, taxas e regras.

## Decisão

1. **Núcleo define o contrato; provedores são módulos.** Interface `GatewayPagamento` com operações pequenas: criar cobrança, consultar situação, tratar notificação (webhook) e reembolsar (opcional). Cada provedor (cartão, boleto, PIX, nota de empenho/GRU para órgão público) é um conector instalável.
2. **Cobrança como objeto.** Classe do sistema `cobranca` (valor, moeda, referência ao objeto de origem, participante, provedor, situação, identificador externo). A inscrição referencia a cobrança; o fluxo de estados da inscrição (`pendente → pago → confirmado | cancelado | expirado`) é configuração do ADR 0003, disparada por eventos de cobrança.
3. **Webhooks idempotentes e verificados**: assinatura/segredo por provedor, rota própria fora do CSRF, processamento idempotente pelo identificador externo, registro de todo evento recebido. A situação só muda por confirmação do provedor, nunca por retorno do navegador.
4. **Sem dados de cartão**: o PBL4 nunca vê nem armazena número de cartão (checkout hospedado ou tokenização do provedor).
5. **Preço e regras como dados**: valores por categoria/lote, datas de virada, cupom, gratuidade, vagas limitadas. Regra que exceder isso vira módulo.
6. **Conciliação**: relatório de cobranças por período e situação, exportável; reprocessamento manual de uma notificação.
7. **Credenciais** do provedor fora do banco de conteúdo (arquivo de configuração/variáveis de ambiente), nunca em objeto editável pelo admin de conteúdo.

## Consequências

- O núcleo de pagamento é pequeno e testável com um conector simulado.
- Sistemas de inscrição paga funcionam por configuração + um conector.
- Fiscal/NF, split e recorrência ficam fora do escopo inicial.

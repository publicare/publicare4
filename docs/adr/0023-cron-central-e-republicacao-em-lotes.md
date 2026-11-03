# ADR 0023 — Cron central e republicação em lotes com progresso

Status: aceito (2026-11-03). Estende o [ADR 0012](0012-administracao-web.md) (administração, passagem do tempo) e o [ADR 0006](0006-atualizador-automatico.md) (verificação de versão).

## Contexto

O PBL4 dependia de várias linhas de cron avulsas (`agendados`, `publicar`, `limpar-uploads`, `verificar-atualizacao`), cada uma com a sua frequência, e o administrador não tinha como saber se rodavam. Sites grandes (mais de `LIMITE_SINCRONO` objetos) ficavam com a republicação "pendente" até alguém rodar `bin/pbl publicar`, e a tela de publicação tratava lotes sem mostrar andamento.

## Decisão

1. **Uma linha de cron por minuto**: `php bin/pbl cron`. O comando percorre um **catálogo** de tarefas (`Pbl\Cron\Catalogo`; cada uma implementa `Tarefa`: id, nome, descrição, frequência mínima, `leve()`, `temTrabalho()` e `executar()`). Uma tarefa só roda se **venceu** (frequência) **e** `temTrabalho()` é verdadeiro; senão só registra a verificação. Saída vazia quando nada foi feito (o cron não envia e-mail à toa).
2. **Catálogo inicial**: `agendados` (1 min), `republicacao` (1 min; refaz em lotes, com a mesma fila do painel), `limpeza` (1 h; sessões expiradas e tentativas antigas), `verificar-atualizacao` (1 h; só consulta e atualiza o cache de 24 h, nunca aplica), `uploads-orfaos` (1 dia). Tarefa nova = classe nova + uma linha no catálogo.
3. **Estado em `dados/cron/`** (`estado.json`: por tarefa a última verificação, execução, resultado, duração e erro curto sem caminhos; e o "batimento" de cada origem; `config.json`: pseudo-cron). Gravação atômica; JSON adulterado vira "sem dado".
4. **Sem sobreposição e com orçamento**: `flock` não-bloqueante em `dados/cron/cron.lock` (outra rodada em andamento = a nova sai em silêncio) e orçamento de tempo por rodada (50 s no cron, 25 s em "Executar agora", 3 s no pseudo-cron); o que não coube fica para o minuto seguinte.
5. **Tela "Tarefas agendadas"** (`/_pbl/tarefas`, só administrador geral, CSRF): última e próxima execução, resultado, "Executar agora" (origem `manual`, não conta como cron), situação do cron e como configurá-lo. A página inicial do administrador avisa quando o cron **real** não roda há mais de 5 minutos (ou nunca rodou).
6. **Pseudo-cron opcional**, desligado por padrão (tela ou `PBL_PSEUDO_CRON=1|0`, que manda sobre a tela): em `GET` do painel, se o cron real está atrasado, roda só as tarefas `leve()` com orçamento curto; nunca nas páginas públicas, nunca com rede nem varredura de arquivos, e uma falha nunca derruba a tela.
7. **Republicação em lotes (`FilaDeRepublicacao`)**: estado em `dados/republicacao/site-<id>.json` (total, feitas, publicadas, falhas, último id). Painel (25 páginas por requisição), `bin/pbl publicar` e cron usam a mesma fila. **Retomável** (iniciar com uma ativa devolve a existente) e **sem simultaneidade** (trava `inicio` e trava `lote` não-bloqueante por site); uma fila parada há mais de 1 h é considerada abandonada. Falha em uma página conta e segue; 5 lotes seguidos com erro interrompem. A auditoria `site.republicar` é gravada uma vez, ao terminar.
8. **Progresso na tela "Menu e publicação"**: `pbl-admin.js` (versionado, sem inline, CSP `'self'`) chama `POST …/publicacao/lote` lote a lote e atualiza a barra ("42 de 92 páginas", largura por `style.width` via script) e o resumo final (publicadas, falhas, tempo). Sem JavaScript, o POST comum trata lotes por até 20 s e, se faltar, mostra "Continuar".

## Consequências

- As linhas antigas de cron continuam funcionando; o guia passa a recomendar a única linha `bin/pbl cron`.
- Sites grandes deixam de depender de comando manual: a pendência é consumida pelo cron ou pelo botão.
- O estado em arquivos (e não em tabela) evita migração e funciona com o banco ocupado; custa uma pasta `dados/cron/` e `dados/republicacao/` gravável.
- Pendente: tarefas de limpeza da lixeira de conteúdo (decisão humana, fora do cron) e ajuda contextual (`?`) na tela nova, a cargo do passe de UX do painel.

# Cron central

Uma única linha de cron, a cada minuto, cuida de tudo que o PBL4 faz sozinho ([ADR 0023](../adr/0023-cron-central-e-republicacao-em-lotes.md)).

## Linha de cron

| Onde | Linha |
|---|---|
| Hestia / cPanel (Cron Jobs, "a cada minuto") | `* * * * * php /caminho/do/projeto/bin/pbl cron >/dev/null 2>&1` |
| crontab comum | a mesma linha (`crontab -e`) |
| Docker (cron do host) | `* * * * * docker compose -f /caminho/do/projeto/docker-compose.yml exec -T app php bin/pbl cron >/dev/null 2>&1` |

No cPanel e na Hestia, use o caminho completo do PHP se `php` não estiver no PATH (por exemplo `/usr/local/bin/php` ou `/usr/bin/php8.3`). O usuário do cron precisa poder gravar em `dados/`.

## Como conferir

- No painel, **Tarefas agendadas** (`/_pbl/tarefas`, menu de administrador): deve aparecer "O cron do servidor está rodando" com a hora da última rodada, e cada tarefa com a última execução e o resultado.
- Na linha de comando: `php bin/pbl cron --listar` (estado de cada tarefa, sem rodar nada) e `php bin/pbl cron --tarefa=agendados` (roda uma tarefa agora, ignorando a frequência).
- Sem cron, a página inicial do administrador avisa depois de 5 minutos.

## Tarefas

| Id | Frequência | O que faz |
|---|---|---|
| `agendados` | 1 min | põe no ar e tira do ar o conteúdo por data (substitui `bin/pbl agendados`) |
| `republicacao` | 1 min | continua, em lotes, a republicação pendente de sites grandes |
| `limpeza` | 1 h | apaga sessões expiradas e registros antigos de tentativas |
| `verificar-atualizacao` | 1 h | consulta o canal de atualizações (só avisa; nunca aplica) |
| `uploads-orfaos` | 1 dia | apaga arquivos enviados que ninguém usa mais |

Cada tarefa só roda se venceu **e** tem trabalho; o comando não escreve nada quando não há o que fazer, e não sobrepõe duas rodadas (trava `dados/cron/cron.lock`).

## Sem acesso ao cron (pseudo-cron)

Em "Tarefas agendadas", **Ligar o pseudo-cron** (ou `PBL_PSEUDO_CRON=1` no ambiente): enquanto alguém usa o painel e o cron real está parado, as tarefas leves rodam por até 3 segundos em cada acesso. Não roda nas páginas públicas. É um quebra-galho; o cron de verdade é sempre melhor.

## Republicar com progresso

"Menu e publicação" republica em lotes de 25 páginas com barra de progresso ("42 de 92 páginas") e resumo final. Se a conexão cair, é só voltar e clicar em "Continuar". `php bin/pbl publicar` mostra o mesmo progresso e usa a mesma fila (retoma uma republicação já começada); o cron também.

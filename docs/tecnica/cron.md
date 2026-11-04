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

| `demo-reset` | 1 min (confere); roda a cada 7 dias, 04:00 | **só em demo**: reinicia a demonstração (ver abaixo) |

Cada tarefa só roda se venceu **e** tem trabalho; o comando não escreve nada quando não há o que fazer, e não sobrepõe duas rodadas (trava `dados/cron/cron.lock`).

## Sem acesso ao cron (pseudo-cron)

Em "Tarefas agendadas", **Ligar o pseudo-cron** (ou `PBL_PSEUDO_CRON=1` no ambiente): enquanto alguém usa o painel e o cron real está parado, as tarefas leves rodam por até 3 segundos em cada acesso. Não roda nas páginas públicas. É um quebra-galho; o cron de verdade é sempre melhor.

## Republicar com progresso

"Menu e publicação" republica em lotes de 25 páginas com barra de progresso ("42 de 92 páginas") e resumo final. Se a conexão cair, é só voltar e clicar em "Continuar". `php bin/pbl publicar` mostra o mesmo progresso e usa a mesma fila (retoma uma republicação já começada); o cron também.

## Reinício agendado da demonstração

Só existe em instalação de demo: `"demo": true` em `dados/config/demo.json` ou `PBL_DEMO=1`. Sem a flag a tarefa nem entra no catálogo, o comando `bin/pbl demo-resetar` recusa e a própria rotina de reinício lança antes de tocar em qualquer coisa. Ajustes opcionais no mesmo arquivo: `"dias"` (1 a 90, padrão 7) e `"hora"` (`HH:MM` de Brasília, padrão `04:00`).

O reinício: trava `dados/demo/reiniciar.lock` → backup de `dados/` (SQLite, uploads, peles) em `dados/demo/backups/`, mantendo os 3 últimos → manutenção ligada (resposta 503 estática com `Retry-After`) → apaga `pbl4.sqlite*`, cache, peles, uploads e `dados/config/instalado.lock` → reinstala com o instalador (mesmo nome e domínio principal; administrador `admin` / `admin-demo-2026`; todos os modelos) → `demo-popular --forcar` (`autor` e `editor` com `demo-2026-pbl4`) → manutenção desligada. Ficam intactos `dados/config/` (segredo, `rede.json`, flag), `dados/cron/` e `dados/demo/`. Se qualquer passo falhar, o backup é restaurado e as páginas são republicadas; a tarefa só tenta de novo depois de 1 hora.

Comandos: `bin/pbl demo-resetar` (só reinicia se a hora marcada já chegou), `--agora` (reinicia já) e `--simular` (mostra o que faria, sem mudar nada). O botão "Executar agora" da tela não força o reinício fora de hora. O estado do ciclo é `dados/demo/estado.json`; ao ligar a flag numa instalação existente, o primeiro ciclo conta a partir daquele momento (nunca reinicia de surpresa na hora). A faixa "Demo: reinicia em DD/MM às 04:00" aparece nas páginas públicas (entra ao publicar) e no painel.

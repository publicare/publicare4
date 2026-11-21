# Cópias de segurança (técnico)

Decisões: [ADR 0029](../adr/0029-copias-de-seguranca.md). Código: `app/src/Backup/`.

## Configuração — `dados/config/backup.json` (0600)
| Chave | Padrão | Significado |
|---|---|---|
| `ativo` | `false` | liga o agendamento (a cópia manual sempre funciona) |
| `frequencia` | `diaria` | `diaria` ou `semanal` |
| `hora` | `03:00` | `HH:MM` no fuso do site |
| `dias` | `[1]` | dias ISO (1 seg … 7 dom), só na semanal |
| `reter_ultimas` / `reter_dias` | `7` / `30` | retenção (0 desliga a regra) |
| `pasta` | `dados/backups` | relativa à raiz ou absoluta; validada |
| `fuso` | `America/Sao_Paulo` | |

Valor inválido volta ao padrão (desativado).

## Pasta
`ValidadorDePasta` recusa `..`, link simbólico, a raiz ou ancestral dela, `app/`, `vendor/`, `public/` e o estado interno; exige escrita; dentro do site grava `.htaccess` de negação. Prefira uma pasta fora do `public_html`.

## Pacote
`pbl4-AAAAMMDD-HHMMSS-<tipo>-v<versao>.zip` (tipos: manual, agendada, pre-restauracao; as cópias `*.sqlite` do atualizador aparecem como pre-atualizacao). Contém segredos: trate como segredo.

## Comandos
```
bin/pbl backup criar
bin/pbl backup listar
bin/pbl backup restaurar <arquivo> [--confirmo]   # sem --confirmo só confere e avisa
bin/pbl backup limpar [--simular]
bin/pbl backup enviar                             # destino remoto: ainda não disponível
```

## Cron
Tarefa `backup` (60 s, fora do pseudo-cron). Estado em `dados/backup/estado.json`. Trava ocupada → tenta no minuto seguinte.

## Limitações atuais
Sem destino remoto e sem criptografia (ver ADR). Só SQLite.

## Tela `/_pbl/copias` (`Pbl\Admin\TelasDeBackup`)
Só orquestra o código de `Pbl\Backup`. Rotas: `GET /copias`, `POST /copias/criar|config|baixar|apagar|limpar|restaurar`, `GET /copias/restaurar?arquivo=` (confirmação). Só administrador geral; todo POST confere origem e CSRF; criar/config/baixar/apagar/limpar pedem a senha (modo elevado) e restaurar pede sempre, mais a palavra `RESTAURAR` e o aviso marcado. O download é por POST (sem URL guardável), com `Cache-Control: no-store`. Cópias do atualizador (`*.sqlite`) e protegidas não podem ser apagadas pela tela.
A cópia roda dentro do POST (sem `proc_open`); `pbl-backup.js` só mostra a barra "trabalhando" e evita envio duplo (sem JS funciona igual). Limitação: sites muito grandes podem exceder o tempo da requisição; nesses casos use o cron ou `bin/pbl backup criar`. Depois de restaurar, a auditoria é gravada numa conexão nova (o banco foi trocado).

# ADR 0029 — Cópias de segurança: núcleo único, pacote ZIP, restauração segura

Status: aceito (núcleo, CLI e cron); tela, destino remoto e criptografia pendentes.

## Contexto
Só o atualizador copiava o banco (`dados/backups/`). Hospedagem comum não tem `proc_open`; o cron central roda por minuto.

## Decisão
- Núcleo em `Pbl\Backup` (config, validador de pasta, criador, catálogo, retenção, restaurador, agenda). CLI (`bin/pbl backup`) e cron (`BackupAgendadoTarefa`) só orquestram; a tela futura fará o mesmo.
- Pacote `.zip` (ext-zip, sem dependência nova): `manifesto.json` (formato, tipo, data UTC, versão, esquema, SHA-256 por entrada) + `banco/pbl4.sqlite` (`VACUUM INTO`) + `dados/**`. Arquivo 0600, SHA-256 do pacote em `.sha256` ao lado. Fora do pacote: `backups/`, `backup/`, `atualizacao/`, `cron/`, `cache/`, locks e o banco vivo.
- Restauração: confere tudo antes de escrever (SHA-256, entradas só do manifesto, caminhos seguros, esquema ≤ código, espaço), cria cópia `pre-restauracao`, liga manutenção, troca por renomeação e desfaz se a verificação final falhar. Mesma trava de atualizar/reverter.
- Retenção: apaga só o que está além das N últimas **e** mais velho que X dias; nunca a mais recente, a pré-atualização do "voltar atrás" nem a pré-restauração recente.
- Agenda no fuso do site, janela de 6 h após o horário (cron parado não dispara cópia velha). Desativada por padrão (`dados/config/backup.json`) — é o feature flag.

## Pendente (propostas)
- Execução em passos retomáveis para a tela sem processo (hoje a criação roda inteira numa chamada; adequada a CLI/cron).
- Destinos remotos: S3-compatível por cURL + SigV4 próprio; SFTP por `phpseclib/phpseclib` (única dependência nova proposta), credenciais em `dados/config/backup-remoto.json` 0600.
- Criptografia do pacote (libsodium `secretstream`; chave simétrica com frase de recuperação): **decisão do dono**. Hoje o pacote tem segredos em claro; mitigação = 0600 + pasta negada à web.

## Consequências
Um só código testável; sem dependência nova; pacote sem cifra exige cuidado com a pasta e com destinos externos.

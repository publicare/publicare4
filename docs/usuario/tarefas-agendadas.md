---
titulo: Tarefas agendadas e o cron
resumo: Ligue o cron na hospedagem, confira a tela Tarefas agendadas e saiba o que o Publicare faz sozinho, como publicar na data marcada.
capitulo: manutencao
ordem: 30
---

# Tarefas agendadas e o cron

Algumas coisas o Publicare precisa fazer sem que ninguém clique: publicar uma notícia na data marcada, tirar do ar o que venceu, refazer as páginas depois de uma mudança grande, fazer a cópia de segurança. Para isso ele depende do **cron**, um despertador do servidor que chama o Publicare uma vez por minuto.

Sem o cron o site continua no ar e você continua editando, mas o que depende de data ou de tarefa em segundo plano fica parado.

![Tela Tarefas agendadas, com o estado do cron e a lista de tarefas](captura:painel-tarefas)

## Ligar o cron na hospedagem

Você faz isso uma vez, no painel da hospedagem (a área onde se cria "Cron Jobs" ou "Tarefas agendadas"). Se não achar, peça ao suporte.

1. Abra a área de cron da hospedagem e crie uma tarefa nova.
2. Escolha a frequência **a cada minuto** (cinco asteriscos: `* * * * *`).
3. No comando, use o caminho do PHP e o caminho da pasta do Publicare:

```bash
* * * * * /caminho/do/php /caminho/do/publicare/bin/pbl cron >/dev/null 2>&1
```

4. Troque `/caminho/do/php` pelo caminho completo do PHP na sua hospedagem (o suporte informa) e `/caminho/do/publicare` pela pasta onde o Publicare está.
5. Salve. Em até dois minutos, a tela **Tarefas agendadas** deve mostrar que o cron está rodando.

A tela **Tarefas agendadas** mostra a linha pronta, com o caminho da sua instalação, na seção **Como configurar o cron**. O usuário do cron precisa poder gravar na pasta `dados/`.

## A tela Tarefas agendadas

Abra **Mais** e clique em **Tarefas agendadas** (só administradores).

No topo, um cartão mostra a situação:

- **O cron do servidor está rodando**, com a hora da última rodada: tudo certo.
- **O cron do servidor está parado**: o servidor não chama o Publicare há mais de cinco minutos. Se o pseudo-cron estiver ligado, ele cobre as tarefas leves enquanto alguém usa o painel.
- **O cron nunca rodou** ou **O cron parou em** uma data: configure a linha acima. A página inicial do painel também avisa quando isso acontece.

Abaixo, a tabela traz, para cada tarefa, a **Frequência**, a **Última execução**, a **Próxima** e o **Resultado** (ok, em andamento, erro ou ainda sem trabalho). As horas aparecem em UTC. O botão **Executar agora** roda uma tarefa na hora, sem esperar, e mostra o resultado no alto da tela.

## O que roda sozinho

| Tarefa | O que faz |
|---|---|
| Publicação e saída por data | Publica na data marcada e tira do ar no vencimento. |
| Republicação pendente | Refaz as páginas, em lotes, depois de mudar menu, domínio, pele ou configurações. |
| Limpeza de sessões e tentativas | Apaga sessões expiradas e registros antigos de tentativas de entrada. |
| Retenção de dados pessoais | Zera o IP dos registros antigos e apaga os que passaram do prazo (veja [Privacidade](privacidade.md)). |
| Verificação de atualização | Avisa quando há versão nova. Não instala nada. |
| Limpeza de arquivos órfãos | Apaga arquivos enviados que nenhum conteúdo usa. |
| Cópia de segurança agendada | Cria a cópia no horário definido em **Cópias de segurança** e apaga as antigas. Fica desligada até você ligar (veja [Cópias de segurança](copias-de-seguranca.md)). |
| Atualização agendada | Executa a atualização que você pediu na tela Atualização, quando a hospedagem não deixa o painel atualizar na hora (veja [Como atualizar](atualizar.md)). |

Em instalações de demonstração aparece mais uma tarefa, que reinicia a demonstração.

## Hospedagem sem cron: o pseudo-cron

Na seção **Pseudo-cron (hospedagem sem cron)**, o botão **Ligar o pseudo-cron** faz as tarefas leves rodarem quando alguém usa o painel. É só um quebra-galho: não roda nas páginas públicas, e a atualização agendada nunca roda assim. Se o servidor fixa essa opção, a tela avisa e não deixa mudar.

## Pela linha de comando

Quem tem terminal no servidor pode usar:

```bash
php bin/pbl cron --listar              # mostra as tarefas e a última execução, sem rodar nada
php bin/pbl cron --tarefa=agendados    # roda só uma tarefa agora
php bin/pbl agendados --simular        # mostra o que entraria e sairia do ar por data
php bin/pbl publicar                   # refaz todas as páginas dos sites, com barra de progresso
```

`bin/pbl agendados` faz a publicação e a saída por data uma vez; o cron já faz isso sozinho, então você só precisa dele para testar. `bin/pbl publicar` serve quando o site mostra algo desatualizado ou depois de trocar arquivos de uma pele à mão (veja [Solução de problemas](solucao-de-problemas.md)). Se você usava linhas antigas de cron para `agendados`, `publicar` ou `verificar-atualizacao`, pode removê-las e deixar só a do `cron`.

## Veja também

- [Solução de problemas](solucao-de-problemas.md)
- [Como atualizar](atualizar.md)
- [Cópias de segurança](copias-de-seguranca.md)
- [Guia técnico do cron](../tecnica/cron.md)

---
titulo: Como atualizar o PBL4
resumo: Atualizar o Publicare pelo painel, pelo cron ou pelo terminal, e voltar atrás se for preciso.
capitulo: manutencao
ordem: 20
publico: administrador
---

# Como atualizar o PBL4

O PBL4 se atualiza com um comando e **não mexe nos seus dados**: o conteúdo, as contas, as peles e as fotos ficam na pasta `dados/`, que a atualização nunca toca.

Se você tem acesso a um terminal no servidor, dentro da pasta do PBL4:

```bash
php bin/pbl atualizar --verificar   # só olha se há versão nova
php bin/pbl atualizar               # atualiza
```

O que o PBL4 faz por você:
- só aceita atualizações **assinadas** pela equipe do projeto; uma versão falsificada é recusada;
- guarda uma **cópia do banco** antes de começar (em `dados/backups/`);
- mostra "site em manutenção" para os visitantes durante a troca, por alguns segundos;
- se algo der errado, **volta sozinho para a versão anterior** e diz o motivo.

**Pelo painel**, sem terminal: entre como administrador e abra **Atualização** na barra. A tela mostra a versão instalada e se há uma nova (com o que mudou e se é correção de segurança). Para atualizar, digite sua senha de novo, marque a confirmação e clique em **Atualizar agora**. A atualização roda em segundo plano e a seção **Andamento** da própria página mostra a barra de progresso (veja abaixo o que fazer quando o servidor não deixa iniciar na hora). Durante a troca o site mostra "em manutenção" por alguns instantes (a própria tela também). **A página inicial do painel avisa quando há versão nova** (só para administradores), sem precisar abrir a tela de Atualização — mas, se a última consulta estiver vencida, mostra só um link "Verificar" em vez de consultar ali mesmo.

![Tela Atualização, com a versão instalada e o botão para verificar](captura:painel-atualizacao)

## Sem terminal e sem "Atualizar agora": atualizar pelo cron

Em muitas hospedagens (Hestia, cPanel e parecidas) o painel **não pode iniciar a atualização na hora**. Nesses casos o botão passa a se chamar **Agendar atualização** e a tela explica que o servidor não deixa. Não é erro: a atualização é feita pelo **cron**, a tarefa que o próprio PBL4 já pede para ligar na instalação (uma vez por minuto). Passo a passo, sem terminal:

1. **Confira o cron.** No painel, abra **Tarefas agendadas**. Deve aparecer "O cron do servidor está rodando", com a hora da última rodada. Se não aparecer, crie no painel da hospedagem (Cron Jobs, "a cada minuto") a tarefa `php /caminho/do/projeto/bin/pbl cron` (veja o [guia do cron](../tecnica/cron.md)); sem ela, nada agendado acontece.
2. Abra **Atualização**, leia o que mudou, digite **sua senha** de novo, marque a confirmação e clique em **Agendar atualização**. (Em hospedagens que deixam o painel iniciar a atualização, existe a caixa "agendar para o cron" se você preferir esse caminho.)
3. A página mostra **"Agendada: aguardando o próximo ciclo do cron (até 1 minuto)"**. Pode deixar a página aberta: ela se atualiza sozinha.
4. Quando o cron pega o pedido, aparecem a **barra de progresso**, a etapa (baixando, conferindo, trocando o código, atualizando o banco…) e o tempo decorrido. Durante a troca o site mostra "em manutenção" por instantes e a barra fica parada: ela volta a andar sozinha.
5. No fim a página diz **Concluído** (ou **Falhou**, com o motivo; se falhar, o PBL4 já devolveu a versão anterior). Recarregue para ver a versão instalada.

Mudou de ideia? Enquanto estiver **Agendada** (ainda não começou), clique em **Cancelar o agendamento**. Depois que começa, não dá mais para cancelar. O pedido vale **30 minutos**; passado isso ele é descartado e você pede de novo. Só existe um pedido por vez.

**Se passar de 5 minutos e continuar "Agendada"**, a página avisa que o cron parece não estar rodando. Confira a tarefa no painel da hospedagem (a cada minuto, com o caminho completo do PHP, por exemplo `/usr/bin/php`) e a tela **Tarefas agendadas**. O mesmo vale para **voltar atrás**: o botão também agenda quando o servidor não deixa iniciar na hora, e o andamento aparece do mesmo jeito.

O cron **confere tudo de novo** antes de atualizar: a assinatura, a integridade do pacote e se a versão ainda é a que você confirmou. Se a versão disponível mudou entre o seu pedido e o cron, nada é atualizado e a página explica; basta ler as mudanças e pedir de novo. A barra de progresso aparece também quando a atualização é feita pelo terminal.

Antes de usar a tela é preciso dizer ao PBL4 onde ficam as atualizações: crie `dados/config/atualizacao.json` no servidor (o PBL4 não traz endereço pronto; veja o [guia técnico](../tecnica/atualizacao.md)). As versões oficiais do PBL4 já trazem as chaves públicas de assinatura; num build sem elas a tela avisa "Nenhuma chave de assinatura instalada; atualizações recusadas." e nada é baixado. Se o servidor não deixar o painel atualizar (falta de permissão, por exemplo), a tela diz o que falta; o terminal sempre funciona.

Se uma atualização falhar, o PBL4 volta sozinho ao código anterior e mostra o motivo.

## Voltar atrás de uma atualização que já terminou

Desfazer uma atualização que **terminou bem**, mas que você decidiu não querer, tem comando e botão: `bin/pbl reverter` (ou "Voltar para a X" na mesma tela Atualização). Diferente da volta sozinha quando a atualização **falha**, esta você pede, e ela devolve **o código e o banco** de como estavam antes da atualização — por isso, **o que foi criado no site depois da atualização se perde** (um texto novo, uma conta criada, uma mudança de configuração guardada no banco…). A tela e o comando avisam exatamente o que você perderia e pedem confirmação explícita antes de fazer qualquer coisa; pela linha de comando, sem `--confirmar` nada é alterado:

```bash
php bin/pbl reverter              # só mostra para qual versão voltaria e o que se perderia
php bin/pbl reverter --confirmar  # devolve de verdade
```

Pelo painel, é preciso digitar a senha de novo e marcar a confirmação, como ao atualizar. O PBL4 só oferece a opção (botão ou comando concordando) quando está seguro de que dá para voltar: se algo não confere (por exemplo, o banco mudou mais do que a atualização registrou), ele explica o motivo em vez de arriscar seus dados. Se a volta atrás falhar no meio, o PBL4 devolve sozinho o estado de hoje. O código e a cópia do banco de antes só valem até a **próxima** atualização.

Sem terminal nem painel (hospedagem só com FTP)? Dá para atualizar enviando o pacote e usando `--manifesto`; veja o [guia técnico](../tecnica/atualizacao.md).

A verificação de novidades pode ser desligada: veja `verificar` na [configuração](../tecnica/atualizacao.md). O PBL4 não envia nenhum dado do seu site ao consultar. Quem tem acesso ao servidor pode deixar essa verificação rodando por conta própria com um agendamento (`bin/pbl verificar-atualizacao`, veja o [guia técnico](../tecnica/atualizacao.md)), para a página inicial e a tela Atualização sempre mostrarem um resultado recente sem precisar clicar em "Verificar agora".

## Trocar de banco de dados

Hoje o Publicare usa SQLite. Outros bancos (MySQL, MariaDB e PostgreSQL) estão previstos, mas ainda não estão liberados. Já existe o comando que passa um site instalado, com todo o conteúdo, de um banco para outro: `bin/pbl banco-trocar`. Ainda não use em site de verdade.

```bash
# 1. Só conferir: mostra o que seria copiado e não grava nada (ainda não liberado)
php bin/pbl banco-trocar --simular --para=BANCO --host=SERVIDOR --nome=NOME --usuario=USUARIO
# 2. Trocar de verdade (ainda não liberado; pede a senha do banco novo)
php bin/pbl banco-trocar --para=BANCO --host=SERVIDOR --nome=NOME --usuario=USUARIO
```

O banco novo precisa existir e estar vazio. Faça antes uma cópia de segurança. Durante a cópia o site fica em manutenção. No fim, o Publicare compara cada tabela dos dois lados. Se algo não bater, não troca nada.

O banco antigo não é alterado. Guarde por alguns dias. Para voltar atrás, use `bin/pbl banco-trocar --voltar`: o que foi gravado no banco novo depois da troca não volta para o antigo.

Se o banco é definido por variáveis de ambiente (`PBL_BANCO_*`), o comando recusa: tire as variáveis antes.

## Outros comandos que você pode ver
- `bin/pbl versao`: mostra a versão instalada.
- `bin/pbl migrar`: aplica as mudanças pendentes do banco. A atualização já faz isso; use só se alguém do projeto pedir.

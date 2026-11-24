---
titulo: Solução de problemas
resumo: Tabela de sintomas, causas prováveis e o que fazer para os problemas mais comuns, e como pedir ajuda sem expor senhas.
capitulo: manutencao
ordem: 40
---

# Solução de problemas

Procure o seu sintoma na tabela e siga a coluna "O que fazer". Os comandos `bin/pbl` são para quem tem terminal no servidor; se não for o seu caso, passe o texto à pessoa que cuida da hospedagem.

## Tabela rápida

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| Não consigo entrar: "Login ou senha inválidos." | Senha ou login digitados errado, ou conta desativada. | Confira o login (letras minúsculas) e a senha. Use "Esqueci a senha" na tela de entrada. Se a conta estiver desativada, peça a um administrador para reativá-la em **Contas**. |
| "Muitas tentativas de entrar. Aguarde alguns minutos e tente de novo." | O sistema trava as tentativas por um tempo depois de várias falhas seguidas. | Espere alguns minutos. Se a pressa for grande, quem tem terminal libera com `php bin/pbl conta-destravar login`. |
| Não recebo o link de recuperação | E-mail não configurado, ou mensagem no spam. | Veja a linha "E-mail não chega" abaixo e [Recuperar o acesso](recuperar-acesso.md). |
| "Este link não é válido ou já foi usado. Peça um novo." | O link de recuperação vale 30 minutos e funciona uma vez; o de convite vale 72 horas. Um pedido novo cancela o anterior. | Peça um link novo e use sempre o mais recente. Abra-o no mesmo navegador, sem copiar com espaços. Para convite, um administrador clica em **Reenviar convite** na aba **Segurança** da conta. |
| O site mostra uma página antiga | A página pronta guardada pelo site não foi refeita. | Em **Site**, abra **Menu e publicação** e clique em **Republicar agora** (ou **Continuar**, se já houver uma em andamento). No terminal: `php bin/pbl publicar`. Depois limpe o cache do navegador. Veja também se o conteúdo está publicado e não agendado. |
| Publiquei com data marcada e não entrou no ar | O cron não está rodando. | Abra **Mais** e **Tarefas agendadas**. Se o cartão disser que o cron parou, veja [Tarefas agendadas](tarefas-agendadas.md). |
| Tela em branco ou erro 500 | Erro no servidor: permissão de pasta, pele com problema ou falta de memória. | Recarregue. Se persistir, veja o que mudou por último (pele, modelo de página, atualização). Volte o modelo pelo **Histórico**. Quem opera o servidor confere o registro de erros do PHP e se a pasta `dados/` aceita gravação. |
| A imagem não sobe | Arquivo grande demais, tipo não aceito, imagem corrompida ou limite do servidor. | Leia a mensagem. Exemplos: "O arquivo tem X MB; o máximo para este campo é Y MB.", "Tipo de arquivo não permitido." ou "O arquivo é maior do que o servidor aceita." Reduza a imagem, converta para JPG ou PNG, ou peça à hospedagem para aumentar o limite de envio. Veja [Arquivos e imagens](arquivos-e-imagens.md). |
| O e-mail não chega | Envio desligado, dados de SMTP errados, ou mensagem no spam. | Em **Mais** e **E-mail do site**, envie uma mensagem de teste e siga [E-mail do site](email-do-site.md). Veja o spam e confira o remetente. |
| O cron parece parado | A tarefa não foi criada na hospedagem, o caminho do PHP está errado ou a pasta `dados/` não aceita gravação. | Abra **Tarefas agendadas** e veja a última rodada. Confira a linha do cron, que deve ser a cada minuto. Teste no terminal com `php bin/pbl cron --listar`. |
| A atualização falhou | Pacote recusado, falta de permissão, ou a troca foi interrompida. | O Publicare devolve sozinho a versão anterior e mostra o motivo na tela **Atualização**. Leia o motivo e tente de novo. Veja [Como atualizar](atualizar.md). |
| A atualização agendada fica "Agendada" | O cron não está rodando. | Confira o cron (linha acima). Passados cinco minutos a tela avisa. |
| O site mostra "Site em manutenção" e não sai | Uma atualização foi interrompida no meio. | A marca de manutenção vence sozinha em até 30 minutos. Espere, recarregue e, se continuar, veja a tela **Atualização** (ela mostra se terminou ou falhou). Se passar disso, peça ajuda ao suporte da hospedagem. |
| A busca não acha nada | O conteúdo não está publicado ou a palavra está escrita diferente do que está no texto. | Confirme que o conteúdo está publicado. Teste com uma palavra do título. Veja [Busca](busca.md). |
| Sumiu o menu ou um item dele | O menu foi cortado por exceder o limite de níveis ou itens, ou a republicação está pendente. | Em **Menu e publicação**, leia o aviso e clique em **Republicar agora**. |
| Apaguei sem querer | Item na lixeira ou versão anterior guardada. | Veja a lixeira do conteúdo e o histórico (veja [Revisão e histórico](revisao-e-historico.md)). Para o site todo, use [Cópias de segurança](copias-de-seguranca.md). |

## Passo a passo dos casos mais comuns

### O site mostra uma página antiga

1. Confirme que o conteúdo foi salvo e está **publicado** (não privado nem agendado para depois).
2. Recarregue a página com Ctrl+F5 (ou Cmd+Shift+R) para descartar o cache do navegador.
3. No menu, abra **Site**, clique em **Menu e publicação** e depois em **Republicar agora**.
4. Acompanhe a barra de progresso. Se a conexão cair, volte à tela e clique em **Continuar**.
5. Se o problema for só uma notícia ou uma página, abra-a no painel, salve de novo e confira.

### Não consigo entrar

1. Veja se a tecla Caps Lock está ligada e se o login está em letras minúsculas.
2. Use **Esqueci a senha** (ou **Esqueci o usuário**) na tela de entrada e informe o login ou o e-mail.
3. Abra o link que chegar em até 30 minutos. Se não chegar, olhe o spam.
4. Sem e-mail configurado, peça a um administrador para trocar a senha em **Contas**, ou a quem tem terminal para rodar `php bin/pbl conta-recuperar login`.

### O cron parou

1. Abra **Mais** e **Tarefas agendadas** e leia o cartão no alto da tela.
2. Se disser que o cron nunca rodou, a linha ainda não foi criada na hospedagem. Crie-a como descrito em [Tarefas agendadas e o cron](tarefas-agendadas.md).
3. Se disser que parou em uma data, confira na hospedagem se a tarefa existe e se o caminho do PHP continua certo (uma mudança de versão do PHP no servidor costuma quebrá-lo).
4. Clique em **Executar agora** em uma tarefa para ver se ela roda e qual é a mensagem de erro.

## Antes de qualquer coisa

1. Respire e não apague nada. A maioria dos problemas tem conserto.
2. Anote a **mensagem exata** da tela, a hora e o que você tinha acabado de fazer.
3. Confira se já existe uma [cópia de segurança](copias-de-seguranca.md) recente.

## Como pedir ajuda sem expor senhas

Ao falar com o suporte da hospedagem, com a equipe técnica ou num fórum:

- Envie: a mensagem de erro, o que você estava fazendo, o endereço da tela (o que vem depois do domínio, como `/_pbl/tarefas`), a hora do problema e uma captura de tela.
- **Nunca envie**: senha de conta, senha do SMTP, links de recuperação ou de convite (são segredos de uso único), nem o conteúdo de arquivos da pasta `dados/config/`.
- Antes de enviar uma captura ou texto copiado, apague o que for senha, link com código longo ou e-mail de terceiros.
- Se alguém pedir a sua senha, desconfie: ninguém da equipe precisa dela. Se precisar de acesso, crie uma conta temporária em [Usuários e permissões](usuarios-e-permissoes.md) e desative-a depois.
- Se você já enviou uma senha por engano, troque-a em **Meu perfil** e encerre as outras sessões.

## Veja também

- [Perguntas frequentes](perguntas-frequentes.md)
- [Recuperar o acesso](recuperar-acesso.md)
- [Tarefas agendadas e o cron](tarefas-agendadas.md)
- [Como atualizar](atualizar.md)

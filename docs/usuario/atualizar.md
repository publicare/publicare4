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

**Pelo painel**, sem terminal: entre como administrador e abra **Atualização** na barra. A tela mostra a versão instalada e se há uma nova (com o que mudou e se é correção de segurança). Para atualizar, digite sua senha de novo, marque a confirmação e clique em **Atualizar agora**. A atualização roda em segundo plano; recarregue a página para acompanhar. Durante a troca o site mostra "em manutenção" por alguns instantes (a própria tela também). **A página inicial do painel avisa quando há versão nova** (só para administradores), sem precisar abrir a tela de Atualização — mas, se a última consulta estiver vencida, mostra só um link "Verificar" em vez de consultar ali mesmo.

Antes de usar a tela é preciso dizer ao PBL4 onde ficam as atualizações: crie `dados/config/atualizacao.json` no servidor (o PBL4 não traz endereço pronto; veja o [guia técnico](../tecnica/atualizacao.md)). E, por enquanto, **a primeira versão com chave de assinatura ainda não foi publicada**: sem essa chave a tela avisa "Nenhuma chave de assinatura instalada; atualizações recusadas." e nada é baixado. Se o servidor não deixar o painel atualizar (falta de permissão, por exemplo), a tela diz o que falta; o terminal sempre funciona.

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

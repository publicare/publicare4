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

**Pelo painel**, sem terminal: entre como administrador e abra **Atualização** na barra. A tela mostra a versão instalada e se há uma nova (com o que mudou e se é correção de segurança). Para atualizar, digite sua senha de novo, marque a confirmação e clique em **Atualizar agora**. A atualização roda em segundo plano; recarregue a página para acompanhar. Durante a troca o site mostra "em manutenção" por alguns instantes (a própria tela também).

Antes de usar a tela é preciso dizer ao PBL4 onde ficam as atualizações: crie `dados/config/atualizacao.json` no servidor (o PBL4 não traz endereço pronto; veja o [guia técnico](../tecnica/atualizacao.md)). E, por enquanto, **a primeira versão com chave de assinatura ainda não foi publicada**: sem essa chave a tela avisa "Nenhuma chave de assinatura instalada; atualizações recusadas." e nada é baixado. Se o servidor não deixar o painel atualizar (falta de permissão, por exemplo), a tela diz o que falta; o terminal sempre funciona.

Se uma atualização falhar, o PBL4 volta sozinho ao código anterior e mostra o motivo. Desfazer uma atualização que **terminou bem** ainda é manual: o código anterior fica em `dados/atualizacao/anterior/` e a cópia do banco em `dados/backups/`.

Sem terminal nem painel (hospedagem só com FTP)? Dá para atualizar enviando o pacote e usando `--manifesto`; veja o [guia técnico](../tecnica/atualizacao.md).

A verificação de novidades pode ser desligada: veja `verificar` na [configuração](../tecnica/atualizacao.md). O PBL4 não envia nenhum dado do seu site ao consultar.

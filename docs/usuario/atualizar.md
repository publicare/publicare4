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

Sem terminal (hospedagem só com FTP)? A atualização pelo painel chega junto com a administração. Até lá, dá para atualizar enviando o pacote e usando `--manifesto`; veja o [guia técnico](../tecnica/atualizacao.md).

A verificação de novidades pode ser desligada: veja `verificar` na [configuração](../tecnica/atualizacao.md). O PBL4 não envia nenhum dado do seu site ao consultar.

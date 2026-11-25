# Site oficial publicare.org: procedimento do operador

Este roteiro instala e mantém o site **oficial** do Publicare em `https://publicare.org`, com a pele `publicare` e o conteúdo público do projeto. A instalação no servidor e no DNS é do **operador**; o repositório entrega o conteúdo (comando `publicare-oficial-carregar`) e este procedimento.

O conteúdo oficial **não** é a demonstração. O `demo-popular` cria contas com senha pública, envios em revisão, rascunhos e aviso de demonstração, e **nunca** deve rodar em produção (nem `demo-resetar`, nem `PBL_DEMO`).

## O que a carga faz

`php bin/pbl publicare-oficial-carregar` cria ou atualiza só o conteúdo público: página inicial (promessa, pilares e chamadas), O que é, O que faz, Como facilita, O que inova, Instalação e requisitos, Perguntas frequentes, Conceitos e guias, Comunidade (com links úteis reais), Como acompanhar o projeto, Contato (formulário real) e rodapé com a licença e o GitHub. Não cria contas, rascunhos, envios em revisão, aviso de demonstração nem páginas de manual (o manual terá subdomínio próprio).

| Opção | Efeito |
|---|---|
| `--email-contato=ENDERECO` | **Obrigatória.** E-mail real do site (grava `email_contato`). |
| `--host=publicare.org` | Garante o domínio e o torna o principal. Sem ela, o site já precisa ter um domínio principal real (nunca `localhost`, IP ou `.invalid`, `.test`, `.local`). |
| `--site=ID` | Site (padrão: o primeiro). |
| `--sem-publicar` | Não publica ao final. |

Ordem interna, que não muda: domínio, configuração (indexação ligada), pele e modelos (`faq`, `destaque`, `bloco`), conteúdo e, por último, a publicação. A publicação vem depois do domínio porque o `canonical`, o `og:url` e o `og:image` são gravados na página com o host cadastrado.

### Regras de idempotência

- Cada objeto da carga é identificado pelo **endereço** (`url_amigavel`). Rodar de novo cria o que falta e atualiza só o que difere do texto oficial. Sem mudança, não há versão nova.
- O que o editor criou **fora** da carga (páginas, perguntas, arquivos, em qualquer pasta) não é lido, alterado, movido nem apagado.
- O que o editor mudou **num objeto da carga** volta ao texto oficial na próxima carga. Para mudar uma página oficial de forma duradoura, mude o texto no código (`Pbl\Demo\ConteudoPublicare`) e recarregue.
- Se o editor mandou um objeto da carga para a lixeira, a carga o recoloca no ar.
- Os textos iniciais da instalação (`sobre` e a notícia de boas-vindas) vão para a lixeira só se ainda estão como a instalação os criou. Editados, ficam.

## Passo a passo

### 1. Pré-requisitos

- PHP 8.2 ou mais novo, com as extensões de [instalacao.md](instalacao.md) (o assistente confere). `gd` é recomendada: sem ela, os destaques da home nascem sem imagem.
- Hestia com o usuário `publicare` e o domínio `publicare.org` já criado (hoje com a página "em construção"). Faça uma cópia dela antes de trocar.
- DNS na Cloudflare apontando `publicare.org` para o servidor.

### 2. Instalar no Hestia

1. No usuário `publicare`, coloque o programa de modo que `public_html` seja o que o servidor web serve, com `dados/` e `app/` fora do alcance da web (veja a seção Hestia/VestaCP de [instalacao.md](instalacao.md), inclusive `open_basedir`).
2. Instale com **banco novo**, nunca com o de uma demonstração:

   ```
   php bin/pbl instalar --nome="Publicare 4" --host=publicare.org --admin-login=SEU_LOGIN --admin-email=SEU@EMAIL
   ```

   A senha é pedida de forma oculta. Use uma senha forte e única. O login do administrador **não** pode ser `autor` nem `editor`.
3. Confira que `dados/` ficou com modo 0711 e que `/dados/` e `/app/` respondem 403 ou 404 no navegador.

### 3. Banco

SQLite, em `dados/`, arquivo de acesso restrito. Não há banco a criar no Hestia. Ele entra no backup (passo 8).

### 4. Domínio

`publicare.org` precisa ser o domínio **principal**. A carga com `--host` já faz isso; ou use `php bin/pbl dominio principal publicare.org`. Para `www`, cadastre `php bin/pbl dominio adicionar www.publicare.org --modo=redirecionar` (301 para o principal).

### 5. Carregar o conteúdo

```
php bin/pbl publicare-oficial-carregar --host=publicare.org --email-contato=ENDERECO_REAL
```

A saída traz os totais (criados, atualizados, inalterados) e o número de páginas publicadas. Confira:

```
php bin/pbl usuario listar     # só o administrador
curl -s https://publicare.org/ | grep -E 'canonical|og:url|og:image'
curl -s https://publicare.org/robots.txt
curl -s https://publicare.org/sitemap.xml | head
```

Os três endereços do `grep` devem começar com `https://publicare.org/`; `robots.txt` deve trazer `Sitemap: https://publicare.org/sitemap.xml` e liberar a indexação.

### 6. Cron

Uma linha, a cada minuto, no cron do Hestia: `php /caminho/bin/pbl cron` ([cron.md](cron.md)). Ela cuida de publicação agendada, cópias e verificação de atualização.

### 7. HTTPS atrás da Cloudflare

- Certificado válido no Hestia (Let's Encrypt) para `publicare.org`.
- Cloudflare em SSL/TLS **Full (strict)** e "Always Use HTTPS". **Não** use "Flexible": provoca laço de redirecionamento.
- Crie `dados/config/rede.json` para o IP real do visitante e o HTTPS serem reconhecidos (cabeçalho `CF-Connecting-IP`), como em [seguranca.md](seguranca.md). Sem isso, o limite de tentativas de login enxerga o IP da Cloudflare.
- Regras de cache: não guardar `/_pbl/` nem `/dados/`.
- O `canonical` vem do domínio cadastrado, não do cabeçalho `Host`, então acessos por `www` ou pelo IP de origem não vazam outro endereço. Nunca defina `PBL_ESQUEMA=http` em produção.

### 8. Backup

Agende em Cópias de segurança (painel) e rode `php bin/pbl backup criar` antes de qualquer mudança grande ([backup.md](backup.md)). A cópia inclui `dados/` inteiro. Guarde uma cópia **fora** do servidor e teste a restauração.

### 9. Contato

O formulário grava cada mensagem como conteúdo privado em **Mensagens recebidas**, que se lê no painel. **Não há e-mail automático** a cada mensagem; `email_contato` é o endereço exibido no site. Envie uma mensagem de teste e confira a caixa. Notificação por e-mail seria uma funcionalidade nova.

## Atualizar

- **Programa:** `php bin/pbl atualizar` (pacote assinado, com cópia do banco e volta atrás; [atualizacao.md](atualizacao.md)). O canal de atualização é um serviço separado e não faz parte deste roteiro.
- **Conteúdo:** uma versão nova do programa pode trazer texto novo. Depois de atualizar, rode de novo `php bin/pbl publicare-oficial-carregar --email-contato=ENDERECO_REAL`. Repetir é seguro (regras acima).
- **Mudar de domínio:** `php bin/pbl dominio principal NOVO` e `php bin/pbl publicar`.

## Roteiro de publicação (resumo e reversão)

1. **Backup:** `php bin/pbl backup criar`; copie o arquivo para fora do servidor.
2. **Simular:** em cópia local ou banco temporário, rode `publicare-oficial-carregar --sem-publicar` e confira os totais.
3. **Aplicar:** `php bin/pbl publicare-oficial-carregar --host=publicare.org --email-contato=ENDERECO_REAL`.
4. **Verificar:** `canonical`, `og:url`, `og:image`, `robots.txt` e `sitemap.xml` com `https://publicare.org/`; `usuario listar` só com o administrador; nenhuma página publicada com a palavra "exemplo"; links internos do menu abrindo.
5. **Reverter:** restaure a cópia do passo 1 (`php bin/pbl backup restaurar`, conforme [backup.md](backup.md)) ou, em atualização do programa, use a volta atrás de `php bin/pbl atualizar`. Se preciso, recoloque a página "em construção" do Hestia.

Este procedimento não toca `atualizacoes.publicare.org`, `.github/` nem `deploy/`.

# Como instalar o Publicare

O jeito mais simples: **baixe o `instalar.php`, envie para a pasta do seu site e abra no navegador.** Ele confere o servidor, baixa o Publicare, confere a assinatura de segurança e leva você a um assistente com poucas perguntas.

> **Estado:** a primeira versão estável (`0.1.0`) ainda não foi publicada; até lá o `instalar.php` oficial pode não estar no ar. Veja o [estado do projeto](estado-do-projeto.md).

## O que você precisa

- Uma hospedagem com **PHP 8.2 ou mais novo** (procure a opção "versão do PHP" no painel).
- Poder **enviar arquivos** (FTP ou gerenciador de arquivos do painel) para a pasta do site.
- Um **endereço** (domínio) já apontando para a hospedagem. Para testar, serve `localhost`.

Você não precisa criar banco de dados: o Publicare usa um arquivo (SQLite). Na `0.1.0` o banco é sempre o SQLite; MySQL e PostgreSQL chegam na 0.2.

## Passo a passo

1. **Baixe o `instalar.php`** em <https://atualizacoes.publicare.org/instalar.php> (ou da página da release no GitHub). É um arquivo só.
2. **Envie para a pasta do site**, a mesma onde ficaria a página inicial (por exemplo `public_html/`). A pasta precisa estar vazia ou só ter o que for seu.
3. **Crie ao lado dele um arquivo vazio** chamado `instalar.liberar`. Isso prova que o servidor é seu: ninguém mais consegue instalar no seu lugar. (Pelo gerenciador de arquivos: "novo arquivo".)
4. **Abra no navegador** `https://seu-endereco/instalar.php`.
5. Confira a **lista de requisitos**. Cada item com ✘ diz como corrigir; os ⚠ são só avisos.
6. Clique em **Baixar e instalar**. Pode levar alguns minutos. O instalador confere a assinatura e o SHA-256 do pacote; **se qualquer conferência falhar, nada é instalado** e ele diz o motivo.
7. Clique em **Continuar** para o assistente do site (etapa 2) e responda: nome e endereço do site, conta de administrador, modelos opcionais (pode pular) e uma revisão final.
8. Na tela **Pronto** estão os próximos passos: a linha do cron, onde fica a cópia de segurança, como atualizar e como entrar.

O `instalar.php` **apaga a si mesmo** no fim. Se não conseguir, a página avisa: apague-o à mão. Ele também se recusa a rodar se o site já estiver instalado.

### Pelo terminal

Quem tem acesso por terminal pode usar o `instalar.sh` (baixe de <https://atualizacoes.publicare.org/instalar.sh>), numa pasta vazia:

```sh
mkdir meusite && cd meusite
sh instalar.sh            # use --beta só se alguém do Publicare pedir
```

Ele baixa o mesmo `instalar.php`, confere a assinatura, extrai e pergunta nome, endereço e administrador. A **senha é pedida de forma oculta** (nunca vai em argumento de comando). Sem teclado, use as variáveis `PBL_ADMIN_SENHA`, `PBL_ADMIN_EMAIL` etc. (veja o início do `instalar.sh`).

## Qual pasta é a "raiz do site"?

- **Melhor:** aponte a raiz do site (no painel: "raiz do documento") para a subpasta **`public/`** que o instalador cria. Assim as pastas internas (`dados/`, `app/`) nem existem para o navegador.
- **Se não der para mudar a raiz:** deixe como está. O Publicare traz um `.htaccess` que protege as pastas internas e manda tudo para `public/` (funciona no **Apache**; em **nginx** puro esse arquivo não vale, e o instalador avisa se as pastas internas estiverem abertas).
- **Hestia/VestaCP:** veja a seção abaixo.

Depois de instalar, abra `https://seu-endereco/dados/`: deve dar "proibido" ou "não encontrado".

## Hestia/VestaCP e hospedagens com open_basedir

Nesses painéis o PHP só enxerga a pasta do seu domínio. O jeito mais seguro:

1. Use a pasta **`private/`** do domínio (`private/pbl4/`), e o `instalar.php` dentro dela.
2. A pasta pública do domínio (`public_html`) deve ser um **atalho (link simbólico)** para `private/pbl4/public`. Por SSH: `mv public_html public_html.orig` e depois `ln -s private/pbl4/public public_html`. Sem SSH, peça ao suporte.
3. Peça (ou instale) o pacote **`php8.3-sqlite3`** (o número acompanha a versão do PHP do domínio) e reinicie o PHP.

Se o `instalar.php` detectar um `public_html` com `private/` ao lado, ele lembra disso na tela final.

**Cloudflare na frente:** use o modo SSL **Full** e ative o certificado **Let's Encrypt** no painel. Sem certificado no servidor, o site entra em laço de redirecionamento.

## Depois de instalar

- Use **HTTPS** (cadeado). O login só fica totalmente protegido assim.
- Guarde a senha em um **gerenciador de senhas**; o Publicare não consegue recuperá-la.
- **Agende o cron** (a cada minuto) com a linha que a tela final mostra.
- Faça **cópia de segurança** da pasta `dados/`: é onde ficam o banco, os arquivos e as peles do seu site.
- **Atualizar:** na administração, abra *Atualização*. A atualização é assinada e confere tudo antes de trocar o código.
- O site já vem com exemplos (**Sobre**, **Notícias**, **Contato**). Edite ou apague o que quiser.

## Se algo der errado

| O que aparece | O que fazer |
|---|---|
| "PHP 8.2 ou mais novo" com ✘ | No painel, "versão do PHP": escolha 8.2 ou mais novo. |
| Falta uma extensão (`pdo_sqlite`, `sodium`…) | No painel, "extensões do PHP", ative; ou peça à hospedagem (`pdo_sqlite` = pacote `php8.x-sqlite3`). |
| "Escrita na pasta do site" com ✘ | Dê permissão 755 (ou 775) à pasta onde está o `instalar.php`. |
| "Conexão HTTPS para baixar o pacote" com ✘ | Ative `curl` (ou `allow_url_fopen` + `openssl`). Sem isso use o caminho manual do [guia técnico](../tecnica/instalacao.md). |
| "A assinatura do manifesto NÃO confere" | **Não instale.** Pode ser um download adulterado; avise a equipe do Publicare. |
| "O pacote baixado não confere com o SHA-256" | O download veio corrompido: tente de novo. Se repetir, avise. |
| "Falta uma confirmação" | Crie o arquivo vazio `instalar.liberar` ao lado do `instalar.php`. |
| "Falta proteger as pastas internas" | Mude a raiz do site para `public/`, ou (nginx) bloqueie `app/`, `dados/`, `vendor/`, `bin/`; depois use "Conferir e continuar". |
| "Este site já está instalado" | Apague o `instalar.php`: ele não roda de novo. |
| "open_basedir" com ✘ | Coloque o site dentro da pasta do domínio (veja a seção Hestia). |
| Erro 500 com o projeto na raiz do site | Confira que a hospedagem permite `.htaccess` (AllowOverride), ou aponte a raiz para `public/`. |
| Algo falhou no meio | Nada fica pela metade: corrija o que a mensagem pede e tente de novo. |

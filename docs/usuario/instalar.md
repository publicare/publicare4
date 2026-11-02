# Como instalar o Publicare

> **Estado:** o Publicare 4 ainda não tem um pacote `.zip` pronto para baixar (ele chega com a primeira versão, a `0.1.0`). Este guia descreve o caminho que **já funciona**, que exige alguém com um pouco de familiaridade com hospedagem. Veja o [estado do projeto](estado-do-projeto.md).

## O que você precisa

- Uma hospedagem com **PHP 8.2 ou mais novo** (a maioria das hospedagens de hoje oferece; procure a opção "versão do PHP" no painel).
- Poder **enviar arquivos** (por FTP ou pelo gerenciador de arquivos do painel) e **apontar o site para uma pasta**.
- Um **endereço** (domínio) já apontando para a hospedagem. Se for só para testar, serve `localhost`.

Você não precisa criar banco de dados: o Publicare usa um arquivo (SQLite) por padrão.

## Passo a passo

1. **Envie o Publicare** para a hospedagem (todas as pastas e arquivos).
2. **Aponte o site para a pasta `public/`.** No painel da hospedagem, procure "raiz do documento" ou "pasta do site" e escolha a pasta `public/` do Publicare. *Se não for possível mudar a raiz*, deixe como está: o Publicare já traz um arquivo `.htaccess` que protege as pastas internas e manda tudo para `public/` (em hospedagens com painel Hestia/VestaCP, veja a seção abaixo).
3. **Prove que o servidor é seu.** Para ninguém mais poder instalar no seu lugar, crie um arquivo **vazio** chamado `instalar.liberar` dentro da pasta `dados/` (se a pasta não existir, crie-a).
4. **Abra no navegador** `https://seu-endereco/_pbl/instalar`.
5. Confira a lista de **requisitos** (tudo deve estar com ✔). Se algo estiver com ✘, a própria página diz o que fazer.
6. Preencha o **nome do site**, o **endereço**, e os dados da **conta de administrador** (a senha precisa ter pelo menos 10 caracteres).
7. Clique em **Instalar**. Pronto: o site já aparece com uma página inicial de exemplo, e você pode **entrar** em `https://seu-endereco/_pbl/entrar`.

O arquivo `instalar.liberar` é apagado sozinho no fim, e o instalador deixa de existir.

## Hestia/VestaCP e hospedagens com open_basedir

Nesses painéis o PHP só enxerga a pasta do seu domínio. O jeito que funciona:

1. Envie o Publicare para a pasta **`private/`** do domínio (fica `private/pbl4/`, com `public/` dentro).
2. A pasta pública do domínio (`public_html`) deve ser um **atalho (link simbólico)** para `private/pbl4/public`. Por SSH: `mv public_html public_html.orig` e depois `ln -s private/pbl4/public public_html`. Sem SSH, peça ao suporte da hospedagem.
3. Peça (ou instale) o pacote **`php8.3-sqlite3`** (o número acompanha a versão do PHP do domínio) e reinicie o PHP.
4. Crie `private/pbl4/dados/instalar.liberar` e siga o passo a passo acima.

Se a lista de requisitos mostrar "Acesso do PHP à pasta dados/ (open_basedir)" com ✘, o projeto está fora da pasta do domínio: mova-o para `private/`.

**Cloudflare na frente:** use o modo SSL **Full** e ative o certificado **Let's Encrypt** no painel (domínio com "SSL" e "Let's Encrypt" ligados). Sem certificado no servidor, o site entra em laço de redirecionamento.

## Depois de instalar

- Use **HTTPS** (cadeado) no endereço do site. O login só fica totalmente protegido assim.
- Guarde a senha em um **gerenciador de senhas**; o Publicare não consegue recuperá-la.
- Confira que a pasta `dados/` **não** abre pelo navegador (`https://seu-endereco/dados/` deve dar "proibido" ou "não encontrado").
- Faça uma **cópia de segurança** da pasta `dados/`: é onde ficam o banco, os arquivos e as peles do seu site.

## O que ainda não dá para fazer pelo navegador

Depois de instalar, entre em `/_pbl/` com a conta de administrador: já dá para andar pelas páginas e corrigir título e descrição. Criar e mover páginas, trocar a aparência e gerenciar contas ainda se faz por linha de comando (veja a [documentação técnica](../tecnica/cli.md)); as demais telas vêm nas próximas versões. Acompanhe o [estado do projeto](estado-do-projeto.md).

## Se algo der errado

| Sintaxe da mensagem | O que fazer |
|---|---|
| "Pasta dados/ gravável" com ✘ | Dê permissão de escrita à pasta `dados/` (no gerenciador de arquivos: "permissões" → 755 ou 775). |
| Falta `pdo_sqlite` | Peça à hospedagem o pacote `php8.x-sqlite3` e que reinicie o PHP. |
| "open_basedir" com ✘ | Mova o projeto para dentro da pasta do domínio (veja a seção Hestia/VestaCP acima). |
| O navegador dá "redirecionamento em laço" atrás da Cloudflare | Ative o certificado (Let's Encrypt) no servidor e use SSL Full. |
| Erro 500 com o projeto na raiz do site | Aponte a raiz para `public/`; confira que a hospedagem permite `.htaccess` (AllowOverride). |
| Falta outra extensão do PHP | Peça à hospedagem para ativá-la (ou ative no painel, em "extensões do PHP"). |
| Falta `fileinfo` | Obrigatória para enviar arquivo/imagem num conteúdo; peça à hospedagem para ativá-la. |
| Falta `GD` (upload de imagem avisa) | Opcional: upload de imagem continua funcionando, só com uma verificação mais simples e sem miniaturas. Ative a extensão quando puder. |
| A página `/_pbl/instalar` dá "não encontrado" | Ou o PBL4 já está instalado, ou a raiz do site não está apontando para a pasta correta. |
| Erro ao instalar | Nada fica pela metade: corrija o que a mensagem pedir e tente de novo. |

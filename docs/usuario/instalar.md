# Como instalar o Publicare

> **Estado:** o Publicare 4 ainda não tem um pacote `.zip` pronto para baixar (ele chega com a primeira versão, a `0.1.0`). Este guia descreve o caminho que **já funciona**, que exige alguém com um pouco de familiaridade com hospedagem. Veja o [estado do projeto](estado-do-projeto.md).

## O que você precisa

- Uma hospedagem com **PHP 8.2 ou mais novo** (a maioria das hospedagens de hoje oferece; procure a opção "versão do PHP" no painel).
- Poder **enviar arquivos** (por FTP ou pelo gerenciador de arquivos do painel) e **apontar o site para uma pasta**.
- Um **endereço** (domínio) já apontando para a hospedagem. Se for só para testar, serve `localhost`.

Você não precisa criar banco de dados: o Publicare usa um arquivo (SQLite) por padrão.

## Passo a passo

1. **Envie o Publicare** para a hospedagem (todas as pastas e arquivos).
2. **Aponte o site para a pasta `public/`.** No painel da hospedagem, procure "raiz do documento" ou "pasta do site" e escolha a pasta `public/` do Publicare. *Se não for possível mudar a raiz*, deixe como está: o Publicare já traz um arquivo `.htaccess` que protege as pastas internas e manda tudo para `public/`.
3. **Prove que o servidor é seu.** Para ninguém mais poder instalar no seu lugar, crie um arquivo **vazio** chamado `instalar.liberar` dentro da pasta `dados/` (se a pasta não existir, crie-a).
4. **Abra no navegador** `https://seu-endereco/_pbl/instalar`.
5. Confira a lista de **requisitos** (tudo deve estar com ✔). Se algo estiver com ✘, a própria página diz o que fazer.
6. Preencha o **nome do site**, o **endereço**, e os dados da **conta de administrador** (a senha precisa ter pelo menos 10 caracteres).
7. Clique em **Instalar**. Pronto: o site já aparece com uma página inicial de exemplo, e você pode **entrar** em `https://seu-endereco/_pbl/entrar`.

O arquivo `instalar.liberar` é apagado sozinho no fim, e o instalador deixa de existir.

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
| Falta uma extensão do PHP | Peça à hospedagem para ativá-la (ou ative no painel, em "extensões do PHP"). |
| A página `/_pbl/instalar` dá "não encontrado" | Ou o PBL4 já está instalado, ou a raiz do site não está apontando para a pasta correta. |
| Erro ao instalar | Nada fica pela metade: corrija o que a mensagem pedir e tente de novo. |

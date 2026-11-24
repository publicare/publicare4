---
titulo: Domínios, redirecionamentos e vários sites
resumo: Defina o endereço principal do site, adicione outros nomes, redirecione endereços antigos e entenda como funcionam vários sites na mesma instalação.
capitulo: site
ordem: 20
---

# Domínios, redirecionamentos e vários sites

Um **domínio** é o nome pelo qual as pessoas chegam ao seu site, como `site.exemplo.org`. Cada site pode ter vários domínios, mas um deles é o **principal**: o endereço oficial, usado nos links de e-mail, no mapa do site e nos buscadores.

Só administradores veem essas telas. As ações que mexem no endereço pedem a sua senha de novo.

![Tela Domínios do site, com o domínio principal e a lista de outros nomes](captura:painel-dominios)

## O que cada tipo de domínio faz

| Tipo | O que acontece quando alguém digita esse nome |
|---|---|
| **Principal** | O site abre normalmente. Existe sempre um, e ele não pode ser removido nem mudar de tipo (só ser trocado por outro). |
| **Alias** | O site abre com o mesmo conteúdo, e as páginas informam que o endereço oficial é o principal. Serve para um apelido, como a versão com `www`. |
| **Redirecionador** | O visitante é levado ao domínio principal (redirecionamento permanente, código 301). Serve para nomes antigos. |

## Adicionar um domínio

1. No menu, abra **Site** e clique em **Domínios** no site desejado.
2. Em **Adicionar domínio**, digite só o nome, sem `https://` (por exemplo `www.exemplo.org`).
3. Escolha o **Tipo**: Alias ou Redirecionador.
4. Digite a sua senha e clique em **Adicionar**.

A tela avisa "Domínio adicionado." O mesmo domínio não pode estar em dois sites.

## Trocar o domínio principal

1. Na lista, na linha do domínio que será o novo principal, digite a senha e clique em **Tornar principal**.
2. O principal anterior passa a ser um redirecionador, para que os links antigos continuem funcionando.
3. O site é republicado para usar o endereço novo. Se for grande, a republicação termina aos poucos (veja [Tarefas agendadas](tarefas-agendadas.md)).

Para mudar um domínio entre Alias e Redirecionador, clique em **Tornar alias** ou **Tornar redirecionador** na linha dele.

## Remover um domínio

Clique em **Remover** na linha do domínio, com a senha. Não dá para remover o principal, nem o endereço pelo qual você está usando o painel agora. Para isso, abra o painel por outro domínio.

## Cuidados com DNS e HTTPS

Adicionar um domínio no painel **não** o faz funcionar sozinho. O Publicare não configura DNS nem certificado.

- **DNS:** no serviço onde você registrou o domínio, aponte o nome para a hospedagem onde o Publicare está. Quem fornece a hospedagem diz o que preencher. A propagação pode levar de minutos a horas.
- **HTTPS:** o certificado do domínio novo precisa ser emitido na hospedagem. Sem ele, o navegador mostra aviso de site inseguro.
- **Ordem sugerida:** configure DNS e certificado primeiro, teste se o nome abre o site e só então torne-o principal.
- Se trocar o principal antes de o nome funcionar, os links de e-mail (recuperar senha, convite) apontarão para um endereço que não abre. Veja [E-mail do site](email-do-site.md).

## Redirecionar endereços antigos

Um **redirecionamento** leva quem acessa um endereço que não existe mais a outro lugar. É diferente do domínio redirecionador: aqui você trata de caminhos dentro do site, como `/antigo/pagina`.

1. No menu, abra **Site** e clique em **Redirecionamentos**.
2. Em **Nova regra**, preencha **Endereço antigo** (um caminho, como `/antigo/pagina`) e **Destino** (um caminho do site, como `/novo`, ou um endereço completo com `https://`).
3. Em **Tipo**, escolha **301 — permanente** (padrão, o certo para mudanças definitivas) ou **302 — temporário**.
4. Marque **Vale também para tudo abaixo do endereço** se quiser que `/antigo/x` vá para `/novo/x`.
5. Clique em **Criar regra**.

A tabela mostra cada regra com **Editar** e **Remover**. O sistema recusa regras que formariam um laço (uma leva à outra e volta). Avisa quando o destino também é redirecionado e quando já existe uma página com o endereço antigo: nesse caso a página publicada vence e a regra não é usada.

## Pela linha de comando

Quem tem acesso ao servidor pode fazer o mesmo no terminal, dentro da pasta do Publicare:

```bash
php bin/pbl dominio listar
php bin/pbl dominio adicionar www.exemplo.org --modo=servir
php bin/pbl dominio adicionar antigo.exemplo.org --modo=redirecionar
php bin/pbl dominio principal site.exemplo.org
php bin/pbl dominio remover antigo.exemplo.org
php bin/pbl redirecionamento adicionar /antigo /novo --prefixo --codigo=301
php bin/pbl redirecionamento listar
php bin/pbl redirecionamento remover 3
```

`--modo=servir` cria um alias e `--modo=redirecionar` um redirecionador. O número em `remover` é o que aparece em `listar`. Em instalações com mais de um site, acrescente `--site=2` (o número do site); sem isso vale o primeiro.

## Mais de um site na mesma instalação

Uma instalação pode servir vários sites, cada um com os seus próprios domínios, conteúdo e pele, por exemplo `site.exemplo.org` e `outro.exemplo.org`. O sistema escolhe o site pelo nome digitado no navegador. Com mais de um site, o painel mostra uma seção por site em **Site**, e **Mais** lista as peles de cada um.

Contas, tipos de conteúdo e e-mail são compartilhados entre os sites; o que cada pessoa pode fazer em cada site depende do papel que recebeu (veja [Usuários e permissões](usuarios-e-permissoes.md)).

Ainda não existe tela nem comando para criar um segundo site do zero: o primeiro nasce na instalação. Para ter outro site, peça ajuda a quem cuida do servidor.

## Veja também

- [E-mail do site](email-do-site.md)
- [Peles e modelos de página](peles-e-moldes.md)
- [Usuários e permissões](usuarios-e-permissoes.md)
- [Documentação técnica da linha de comando](../tecnica/cli.md)

---
titulo: Usuários e permissões
resumo: Crie contas, convide pessoas por link, dê ou tire acesso a cada seção do site e cuide do seu próprio perfil e das sessões abertas.
capitulo: pessoas
ordem: 10
---

# Usuários e permissões

Cada pessoa que entra no painel tem uma **conta**. O que ela pode fazer depende do papel que recebeu. Quem administra as contas é o **administrador**; as outras pessoas só veem o próprio perfil.

![Tela Contas, com a lista de pessoas e o papel de cada uma](captura:painel-contas)

## Os papéis

| Papel | O que pode fazer |
|---|---|
| **Administrador** | Tudo, em todos os sites: contas, tipos de conteúdo, domínios, peles, e-mail, atualização, cópias. |
| **Editor** | Ver, criar, editar, publicar e apagar conteúdo na seção em que recebeu o papel. |
| **Autor** | Ver e criar conteúdo na seção; só edita o que ele mesmo criou. Não publica nem apaga. |
| **Leitor** | Só vê o conteúdo da seção; não cria nem altera nada. |
| **Conta de serviço** | Conta para scripts e agentes, sem senha e sem entrada pelo navegador (veja [Agentes e scripts](agentes-e-scripts.md)). |

Administrador é uma marca da conta. Editor, autor e leitor são papéis que se dão **por seção**: valem para o conteúdo escolhido e para tudo que está dentro dele. Se uma pessoa tem dois papéis que cobrem o mesmo conteúdo, vale o mais forte. Uma conta sem nenhum papel entra, mas não vê nem altera conteúdo.

## Criar uma conta com senha

1. Abra **Mais** e clique em **Contas**.
2. Clique em **Nova conta**.
3. Preencha **Login** (3 a 64 letras minúsculas, números, ponto, hífen ou sublinhado; não muda depois), **Nome** e **E-mail**.
4. Escolha a senha inicial (mínimo de 10 caracteres, nada óbvio) e repita.
5. Marque **Administrador** só se a pessoa precisa ver e alterar tudo.
6. Digite a sua senha para confirmar e clique em **Criar conta**.

Combine a senha inicial por um canal seguro e peça que a pessoa a troque em **Meu perfil**. Prefira o convite abaixo: assim você nunca vê a senha.

## Convidar por link

O convite cria a conta e envia um link para a pessoa escolher a própria senha. Precisa de [e-mail configurado](email-do-site.md).

1. Em **Contas**, clique em **Convidar por e-mail**.
2. Preencha **Login**, **Nome** e **E-mail** e, se for o caso, marque **Administrador**.
3. Digite a sua senha e clique em **Enviar convite**.
4. A conta aparece na lista com o selo **Convite pendente**. O link vale 72 horas e só funciona uma vez.

Se a pessoa não recebeu ou o prazo passou, abra a conta, vá à aba **Segurança** e clique em **Reenviar convite** (o link anterior deixa de valer) ou **Cancelar convite** (apaga a conta que nunca entrou).

Sem e-mail, quem tem acesso ao servidor gera o link:

```bash
php bin/pbl conta-convidar carlos carlos@exemplo.org --nome="Carlos" --sem-envio
```

O comando mostra o link uma única vez. Ele é um segredo: entregue só à pessoa convidada.

## Dar acesso a uma seção

1. Em **Contas**, clique na conta e abra a aba **Papéis e acessos**.
2. Em **Conceder papel**, escolha o **Conteúdo** (pasta, página ou início do site) ou digite o número dele em **Ou o número**.
3. Escolha o **Papel**: Leitor, Autor ou Editor.
4. Digite a sua senha e clique em **Conceder**.

A tabela mostra cada papel concedido e, abaixo, o **Acesso efetivo por site**. Em contas de administrador o formulário fica recolhido, porque o papel por seção só valeria se a marca de administrador saísse.

## Tirar o acesso

Na aba **Papéis e acessos**, na linha do papel, digite a sua senha e clique em **Revogar o papel em**.

## Desativar e reativar

Na aba **Segurança** da conta:

- **Desativar conta** bloqueia a entrada e encerra as sessões abertas. Papéis e histórico ficam guardados. Você não pode desativar a própria conta.
- **Reativar conta** devolve o acesso.

Ainda na aba **Segurança**, **Trocar senha** define uma senha nova e encerra as sessões da pessoa. A aba **Dados** altera nome, e-mail e a marca de administrador (você não pode tirar a sua própria).

## Meu perfil e sessões abertas

Cada pessoa abre **Mais** e **Meu perfil**:

1. **Nome:** altere e clique em **Salvar nome**.
2. **Trocar a senha:** informe a **Senha atual**, a **Nova senha** e repita; clique em **Trocar senha**. As outras sessões são encerradas.
3. **Sessões abertas:** cada linha mostra quando a sessão foi aberta e usada pela última vez. Clique em **Encerrar** numa delas ou em **Encerrar todas as outras**.

Se você vir uma sessão que não reconhece, encerre-a e troque a senha.

## Pela linha de comando

```bash
php bin/pbl usuario listar
php bin/pbl usuario criar bia --email=bia@exemplo.org --nome="Bia"
php bin/pbl usuario conceder bia editor 12
php bin/pbl usuario revogar bia 12
php bin/pbl usuario acessos bia
php bin/pbl usuario desativar bia
php bin/pbl usuario ativar bia
```

Em `conceder` o último número é o do conteúdo (a seção). A senha nunca vai na linha de comando: o terminal a pergunta. Para esquecimentos, veja [Recuperar o acesso](recuperar-acesso.md).

## Veja também

- [Recuperar o acesso](recuperar-acesso.md)
- [E-mail do site](email-do-site.md)
- [Revisão e histórico](revisao-e-historico.md)
- [Privacidade](privacidade.md)

---
titulo: E-mail do site
resumo: Configure o envio de e-mail para recuperar senha e convidar pessoas, faça um teste e saiba o que fazer quando as mensagens não chegam.
capitulo: site
ordem: 40
publico: administrador
---

# E-mail do site

O Publicare usa e-mail só para a parte de contas:

- **Recuperar senha ou usuário:** a pessoa recebe um link para escolher uma nova senha.
- **Convite:** o administrador convida alguém e a pessoa recebe um link para definir a própria senha.
- **Avisos da conta:** por exemplo, "Sua senha foi alterada".

Ele não envia boletim nem mala direta. Sem e-mail configurado o site funciona normalmente, mas essas mensagens não saem. Nesse caso veja [Recuperar o acesso](recuperar-acesso.md) para os caminhos pelo terminal.

Só administradores abrem a tela. Os links usam o **domínio principal** do site; sem ele o envio fica desativado (veja [Domínios](dominios-e-varios-sites.md)).

![Tela E-mail do site, com o tipo de envio e o botão para enviar um teste](captura:painel-email)

## Abrir a tela

No menu, abra **Mais** e clique em **E-mail do site**.

## As opções do campo Envio

| Opção | O que faz | Quando usar |
|---|---|---|
| **Desligado (recuperação por e-mail desativada)** | Nenhuma mensagem sai. É o estado inicial. | Quando você não quer e-mail ou ainda não tem um provedor. |
| **Arquivo (grava a mensagem em dados/email/caixa/)** | Em vez de enviar, guarda cada mensagem num arquivo no servidor. | Só para testes. Os links de recuperação ficam legíveis em disco, por isso a tela mostra um aviso. |
| **SMTP** | Envia de verdade, por um servidor de e-mail que você informa. | Uso normal. |

Para enviar pelo servidor de e-mail da própria máquina (o que muitas hospedagens já têm), use SMTP com servidor `127.0.0.1` (ou `localhost`), porta `25` e segurança **Nenhuma**. Essa segurança só é aceita para esses endereços locais; para qualquer outro servidor, TLS ou STARTTLS continuam obrigatórios. Nesse caso usuário e senha são opcionais, e o remetente deve ter o domínio do site, cadastrado e aprovado no servidor de e-mail pelo operador.

## Configurar o SMTP

Você precisa dos dados do serviço de e-mail (a sua hospedagem ou um provedor de envio). Eles informam o servidor, a porta e se a conexão é STARTTLS ou TLS direto.

1. Em **Envio**, escolha **SMTP**.
2. Em **Remetente**, escreva o nome e o endereço de quem aparece como autor, no formato nome seguido do endereço entre sinais de menor e maior, ou só o endereço (por exemplo `nao-responda@exemplo.org`).
3. Preencha **Servidor SMTP**, **Porta** e **Segurança da conexão**: **STARTTLS (porta 587)** ou **TLS direto (porta 465)**.
4. Preencha **Usuário do SMTP** e **Senha do SMTP**.
5. Digite a **sua** senha do painel para confirmar e clique em **Salvar**.

A senha do SMTP é guardada cifrada e nunca volta para a tela: o campo mostra só uma máscara. Para mantê-la, deixe o campo em branco; para trocar, digite a nova.

## Enviar uma mensagem de teste

1. Mais abaixo, em **Enviar mensagem de teste**, digite a sua senha do painel.
2. Clique em **Enviar teste**. A mensagem vai para o e-mail da sua conta, com o assunto "Teste de e-mail".
3. Se a tela disser "Mensagem de teste enviada para o seu e-mail.", procure na caixa de entrada e no spam.

## Remetente com domínio próprio

Prefira um remetente do seu domínio (`nao-responda@exemplo.org`) a um endereço de serviço gratuito. Mensagens "em nome de" um domínio que o servidor não representa costumam cair no spam ou ser recusadas.

Três registros, feitos no DNS do domínio, ajudam o destinatário a confiar na mensagem. Quem fornece o e-mail (hospedagem ou provedor) diz o que preencher:

- **SPF:** uma lista dos servidores que podem enviar e-mail em nome do domínio.
- **DKIM:** uma assinatura digital, que mostra que a mensagem não foi alterada no caminho.
- **DMARC:** a regra que diz ao destinatário o que fazer com mensagens que falham nos dois anteriores, e para onde mandar relatórios.

O Publicare não cria esses registros; ele só envia pelo servidor que você configurou.

## Quando o teste falha

| O que a tela diz | O que fazer |
|---|---|
| "O envio está desligado ou falta o domínio principal do site." | Escolha **Arquivo** ou **SMTP** em **Envio**, salve, e confirme que o site tem domínio principal em **Domínios**. |
| "Não foi possível enviar. Confira servidor, porta, segurança, usuário e senha." | Confira cada campo com os dados do provedor. A combinação mais comum é porta 587 com STARTTLS ou porta 465 com TLS direto. Digite a senha do SMTP de novo. |
| "Informe o remetente" ou "Servidor SMTP inválido" | Corrija o campo. O remetente precisa de um endereço completo; o servidor, só do nome, sem `https://`. |
| Pede a senha de novo ou diz que há muitas tentativas | Digite a senha do painel com calma e aguarde alguns minutos se houver bloqueio. |
| Diz que enviou, mas a mensagem não chega | Veja o spam, confira o remetente e o SPF, DKIM e DMARC do domínio. Alguns provedores só aceitam remetentes que eles conhecem. |

Se nada resolver, use o caminho sem e-mail em [Recuperar o acesso](recuperar-acesso.md) e peça ajuda ao provedor.

## Veja também

- [Recuperar o acesso](recuperar-acesso.md)
- [Usuários e permissões](usuarios-e-permissoes.md)
- [Domínios, redirecionamentos e vários sites](dominios-e-varios-sites.md)
- [Solução de problemas](solucao-de-problemas.md)

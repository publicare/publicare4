# Recuperar o acesso

## Com e-mail configurado
Na tela de entrada, use "Esqueci a senha" ou "Esqueci o usuário" (`/_pbl/esqueci` e `/_pbl/esqueci-usuario`) e informe o login ou o e-mail. A resposta é sempre a mesma, exista a conta ou não: se os dados estiverem cadastrados, chega uma mensagem.
- O link de nova senha vale **30 minutos** e funciona **uma vez**. Um novo pedido cancela o anterior.
- Ao definir a nova senha, as outras sessões são encerradas e você recebe um aviso por e-mail.
- Há limite de pedidos (5 por conta e 20 por origem a cada 15 minutos).

## Convite
O administrador usa Contas › Convidar por e-mail (login, nome, e-mail); a conta aparece como "Convite pendente" até a pessoa aceitar; você recebe um link (vale 72 horas, uso único) e escolhe a própria senha. Na conta, aba Segurança, o administrador pode **reenviar** (o link anterior deixa de valer) ou **cancelar** o convite (apaga a conta que nunca entrou).

## Trocar a própria senha
Mais › Meu perfil: informe a senha atual e a nova. As outras sessões da conta são encerradas e a atual continua.

## Sem e-mail
Peça a quem opera o servidor, que usa `bin/pbl`:

```bash
bin/pbl conta-recuperar <login>            # imprime um link de uso único
bin/pbl conta-senha <login> --gerar        # ou define uma senha nova, mostrada uma vez
bin/pbl conta-convidar <login> <e-mail> --nome="Nome" --sem-envio
```

Um administrador global também redefine a senha no painel (Contas).

## Configurar o e-mail
Mais › E-mail do site (administrador): **desligado**, **arquivo** (grava a mensagem em `dados/email/caixa/`; só para testes ou sem SMTP, pois os links ficam legíveis em disco) ou **SMTP** (servidor, porta, TLS ou STARTTLS, usuário, senha e remetente). A senha do SMTP é guardada cifrada, só aparece mascarada e só muda se você digitar outra. Há botão para enviar uma mensagem de teste. O site precisa de um domínio principal, pois os links o usam. Provedor e credenciais são escolha do dono do site.

## Ainda não existe
Lista e encerramento de sessões, troca de e-mail com confirmação nos dois endereços, "forçar troca de senha", tentativas falhas por conta e destravar no painel ficam para uma próxima entrega (ADR 0033). Destravar continua em `bin/pbl conta-destravar`.

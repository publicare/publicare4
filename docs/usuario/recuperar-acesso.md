# Recuperar o acesso

## Com e-mail configurado
Na tela de entrada, use "Esqueci a senha" ou "Esqueci o usuário" e informe o login ou o e-mail. A resposta é sempre a mesma, exista a conta ou não: se os dados estiverem cadastrados, chega uma mensagem.
- O link de nova senha vale **30 minutos** e funciona **uma vez**. Um novo pedido cancela o anterior.
- Ao definir a nova senha, as outras sessões são encerradas e você recebe um aviso por e-mail.
- Há limite de pedidos (5 por conta e 20 por origem a cada 15 minutos).

## Convite
O administrador convida pelo e-mail; você recebe um link (vale 72 horas, uso único) e escolhe a própria senha. O administrador pode reenviar ou cancelar o convite.

## Sem e-mail
Peça a quem opera o servidor, que usa `bin/pbl`:

```bash
bin/pbl conta-recuperar <login>            # imprime um link de uso único
bin/pbl conta-senha <login> --gerar        # ou define uma senha nova, mostrada uma vez
bin/pbl conta-convidar <login> <e-mail> --nome="Nome" --sem-envio
```

Um administrador global também redefine a senha no painel (Contas).

## Configurar o e-mail
Em Configurações › E-mail (administrador): **arquivo** (grava a mensagem em `dados/email/caixa/`, para testar) ou **SMTP** (servidor, porta, TLS, usuário, senha e remetente). A senha do SMTP é guardada cifrada e só aparece mascarada. O site precisa de um domínio principal, pois os links o usam. Provedor e credenciais são escolha do dono do site. A tela de configuração ainda não está no painel; hoje o arquivo `dados/config/email.json` é gravado pelo serviço `Pbl\Email\ConfigDeEmailRepositorio`.

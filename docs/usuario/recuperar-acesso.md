---
titulo: Recuperar o acesso
resumo: Esqueci a senha ou o usuário: como recuperar o acesso com e sem e-mail configurado.
capitulo: pessoas
ordem: 20
publico: editor, administrador
---

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

## Senha provisória
Quando quem administra o servidor cria a conta (ou redefine a senha) com a opção `--provisoria`, a senha é só de passagem. Ao entrar, qualquer tela do painel leva à tela **Defina sua nova senha**: informe a senha provisória, a nova (mesmas regras de sempre, e diferente da atual) e repita. Até trocar, só essa tela e o botão **Sair** funcionam; as outras sessões da conta são encerradas ao trocar e a atual continua. A marca também cai se a pessoa usar **Esqueci a senha**, aceitar um convite ou se a senha for redefinida pela linha de comando sem `--provisoria`.

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

### Enviar pelo servidor de e-mail da própria máquina (ex.: Exim do HestiaCP)
- Servidor `127.0.0.1` (ou `localhost`, `::1`), porta `25`, segurança **Nenhuma**. O servidor local costuma usar certificado autoassinado, e a verificação do certificado **não é enfraquecida**: por isso "Nenhuma" existe, e **só é aceita para esses três endereços** (qualquer outro servidor é recusado ao salvar e no envio). Como a conexão não sai da máquina, nada trafega sem proteção pela rede.
- Usuário e senha são **opcionais**: deixe em branco se o servidor aceita envio de 127.0.0.1 sem login (relay local). Em branco, o PBL4 não faz AUTH.
- **Remetente com o domínio do site**, ex.: `Site <nao-responda@site.exemplo.org>`. O domínio precisa estar cadastrado e aprovado no servidor de e-mail pelo operador (no HestiaCP, em Mail > domínio), senão o servidor recusa o remetente.
- **DNS do domínio** (para a mensagem não cair em spam): SPF (`TXT` do domínio: `v=spf1 a mx ip4:<IP do servidor> ~all`), DKIM (chave pública gerada pelo servidor de e-mail, publicada em `TXT` `<seletor>._domainkey`; no HestiaCP a zona DNS já traz isso) e DMARC (`TXT` `_dmarc`: `v=DMARC1; p=none; rua=mailto:postmaster@site.exemplo.org`, endurecendo para `quarantine` depois de conferir os relatórios).
- Use **Enviar e-mail de teste**: ele manda uma mensagem para o e-mail do administrador logado e diz se deu certo ou se foi falha de rede, de TLS, de login ou recusa do servidor, sem mostrar senha nem a resposta do servidor.

## Ainda não existe
Em Meu perfil › Sessões abertas você vê as sessões vigentes (abertura e último uso) e encerra uma ou todas as outras. Dispositivo e IP por sessão, troca de e-mail com confirmação nos dois endereços, "forçar troca de senha", tentativas falhas por conta e destravar no painel ficam para uma próxima entrega (ADR 0033). Destravar continua em `bin/pbl conta-destravar`.

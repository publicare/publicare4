# language: pt
Funcionalidade: Recuperação de acesso — esqueci a senha, esqueci o usuário e convite
  Cada cenário é coberto por um teste PHPUnit (RecuperacaoDeAcessoPontaAPontaTest, driver "arquivo", sem navegador)
  e, contra uma prévia no ar, pelas verificações públicas de steps/recuperacao.spec.mjs.

  Cenário: A resposta de esqueci é a mesma para conta que existe e que não existe
    Dado que o e-mail do site usa o driver "arquivo"
    Quando peço a recuperação com um login, um e-mail, um nome desconhecido e um campo vazio
    Então todas as respostas são idênticas em status, corpo e cabeçalhos
    E nada é enviado dentro da requisição

  Cenário: O e-mail de recuperação traz só o link e o token não fica em claro
    Dado que o e-mail do site usa o driver "arquivo"
    Quando peço a recuperação de "editor" e a fila é processada
    Então o e-mail tem um link de uso único com 64 caracteres hexadecimais
    E o e-mail não contém a senha nem o hash da conta
    E o banco guarda só o hash do token e a auditoria não o mostra

  Cenário: Um link expirado não redefine a senha
    Dado que recebi o link de recuperação de "editor"
    Quando o link passa dos 30 minutos de validade
    Então abrir e enviar o link dá erro e a senha antiga continua valendo

  Cenário: Um novo pedido invalida o link anterior
    Dado que recebi o link de recuperação de "editor"
    Quando peço de novo e recebo outro link
    Então o primeiro link dá erro e o segundo funciona

  Cenário: Depois de usar o link, entrar com a senha nova funciona e o link morre
    Dado que recebi o link de recuperação de "editor"
    Quando defino uma senha nova pelo link
    Então entro com a senha nova pelo formulário e não entro com a antiga
    E o mesmo link não abre mais

  Cenário: Pedidos em excesso não geram e-mails demais nem mudam a resposta
    Dado que o e-mail do site usa o driver "arquivo"
    Quando peço a recuperação de "editor" dez vezes seguidas
    Então a resposta é sempre a mesma e saem no máximo cinco e-mails

  Cenário: As telas públicas de recuperação protegem contra script em linha e vazamento do link
    Dado que o e-mail do site usa o driver "arquivo"
    Quando abro a tela de esqueci, a de esqueci o usuário e a de definir senha
    Então a CSP não permite script em linha e a página não tem script nem estilo em linha
    E a tela do link não envia o Referer e não fica em cache

  Cenário: Um convite define a senha por link de uso único
    Dado que o e-mail do site usa o driver "arquivo"
    Quando o administrador convida "bia" com o e-mail "bia@exemplo.org"
    E ela abre o link, é recusada com senha curta e depois define uma senha válida
    Então ela entra com a senha nova e o link do convite deixa de valer

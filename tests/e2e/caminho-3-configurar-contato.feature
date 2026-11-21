# language: pt
@caminho3 @celular
Funcionalidade: Configurar o contato do site
  Contexto:
    Dado que abro o protótipo em um celular de 390 px

  Cenário: E-mail inválido é apontado ao sair do campo
    Dado que estou em "configuracoes.html#painel-contato"
    Quando preencho "email" com "isso-nao-e-email"
    E saio do campo
    Então o campo "email" fica inválido
    Quando preencho "email" com "contato@exemplo.test"
    E saio do campo
    Então o campo "email" não fica inválido

  Cenário: As 5 abas existem e "Opções avançadas" começa recolhida
    Dado que estou em "configuracoes.html"
    Então vejo 5 abas
    E "Opções avançadas" está recolhido

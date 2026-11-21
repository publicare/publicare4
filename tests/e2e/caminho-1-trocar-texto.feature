# language: pt
@caminho1 @celular
Funcionalidade: Trocar o texto de um quarto e publicar
  Como dono do site, quero editar a Suíte Master e publicar,
  para validar o fluxo principal de edição no celular.

  Contexto:
    Dado que abro o protótipo em um celular de 390 px

  Cenário: Editar, ver o aviso de não salvo e salvar
    Quando abro "inicio.html"
    E toco em "Continuar: Suíte Master"
    E toco em "Editar"
    E troco o título para "Suíte Master Vista Mar"
    Então vejo o aviso "Alterações não salvas"
    Quando toco em "Salvar"
    Então o aviso "Alterações não salvas" desaparece

  Cenário: Publicar a partir da aba Publicação
    Dado que estou em "editar-pagina.html"
    Quando abro a aba "Publicação"
    E toco em "Publicar"
    Então o estado mostrado é "Publicado"

  Cenário: Contador de caracteres avisa ao passar do limite
    Dado que estou em "editar-pagina.html"
    Quando preencho "titulo" com 130 caracteres
    Então o campo "titulo" fica inválido

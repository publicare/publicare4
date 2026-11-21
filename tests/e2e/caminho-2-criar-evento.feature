# language: pt
@caminho2 @celular
Funcionalidade: Criar um evento com data de saída
  Contexto:
    Dado que abro o protótipo em um celular de 390 px

  Cenário: Data de saída antes da data de entrada é rejeitada
    Dado que estou em "editar-evento.html"
    Quando preencho "titulo" com "Festa de inverno"
    E abro a aba "Publicação"
    E ligo "Agendar publicação"
    E preencho "publicar-em" com "2026-07-10T10:00"
    E preencho "tirar-do-ar" com "2026-07-01T10:00"
    E saio do campo
    Então o campo "tirar-do-ar" fica inválido

  Cenário: Corrigir a data e publicar
    Dado que estou em "editar-evento.html"
    E abro a aba "Publicação"
    E ligo "Agendar publicação"
    Quando preencho "publicar-em" com "2026-07-01T10:00"
    E preencho "tirar-do-ar" com "2026-07-10T10:00"
    Então o campo "tirar-do-ar" não fica inválido

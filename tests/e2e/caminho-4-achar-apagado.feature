# language: pt
@caminho4 @celular
Funcionalidade: Achar algo apagado
  Contexto:
    Dado que abro o protótipo em um celular de 390 px

  Cenário: Buscar "Suíte" e recuperar a Suíte Antiga da lixeira
    Dado que estou em "inicio.html"
    Quando busco por "Suíte" na busca global
    Então vejo "Suíte Antiga" nos resultados
    Quando toco em "Recuperar"
    E toco em "Confirmar recuperação"
    Então vejo a mensagem "Recuperado como Privado"

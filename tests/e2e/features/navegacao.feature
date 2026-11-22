# language: pt
Funcionalidade: Navegação, tema e celular

  Cenário: Menu tem cinco itens e migalhas dizem onde estou
    Dado que abro o Início
    Então o menu principal tem 5 itens
    E há migalhas de navegação

  Cenário: Interruptor revela e esconde os campos opcionais
    Dado que abro o formulário de novo evento
    Quando ligo "Agendar publicação"
    Então o campo de data de publicação aparece

  Cenário: Isto ficou confuso anota e resume
    Dado que abro o Início
    Quando anoto "botão escondido" como confuso
    Então o resumo no roteiro contém "botão escondido"

  Cenário: Tema escuro e celular sem rolagem lateral
    Dado que abro a lista de conteúdo
    Quando alterno para o tema escuro
    Então o tema escuro fica ativo
    E não há rolagem horizontal

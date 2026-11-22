# language: pt
Funcionalidade: Botão "?" de ajuda pequeno e acessível

  Cenário: O "?" é discreto mas tem área de toque de 44 px
    Dado que abro a tela de configurações como administrador
    Quando olho o "?" ao lado de "Nome do site"
    Então o ícone visível mede no máximo 20 px
    E a área de toque mede pelo menos 44 por 44 px
    E a linha do rótulo não fica mais alta que o rótulo

  Cenário: Teclado abre e fecha a ajuda e o foco volta ao "?"
    Dado que abro a tela de configurações como administrador
    Quando foco o "?" de "Nome do site" e aperto Enter
    Então a ajuda do campo fica visível
    Quando aperto Esc
    Então a ajuda fecha e o foco volta ao "?"

  Cenário: Leitor de tela anuncia o botão e a ajuda do campo
    Dado que abro a tela de configurações como administrador
    Então o "?" de "Nome do site" se chama "Ajuda: Nome do site"
    E o campo "Nome do site" é descrito pelo texto da ajuda

  Cenário: No celular a ajuda abre abaixo do rótulo sem cobrir o campo
    Dado que abro a tela de configurações num celular de 360 px
    Quando abro a ajuda de "Nome do site"
    Então a ajuda fica acima do campo sem sobrepô-lo
    E não há rolagem horizontal

  Cenário: Sem JavaScript a ajuda abre e fecha
    Dado que abro a tela de configurações sem JavaScript
    Quando ativo o "?" de "Nome do site"
    Então a ajuda do campo fica visível

  Cenário: Tema escuro mantém o "?" legível
    Dado que abro a tela de configurações no tema escuro
    Então o contraste do "?" com o fundo é de pelo menos 4,5 para 1

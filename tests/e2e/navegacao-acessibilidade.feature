# language: pt
@navegacao @acessibilidade
Funcionalidade: Navegação, teclado e responsividade
  Esquema do Cenário: Sem rolagem horizontal
    Dado que a tela tem <largura> px de largura
    Quando abro "<tela>"
    Então a página não tem rolagem horizontal

    Exemplos:
      | largura | tela                  |
      | 360     | inicio.html           |
      | 360     | editar-pagina.html    |
      | 430     | configuracoes.html    |
      | 430     | conteudo.html         |

  Cenário: Link "Pular para o conteúdo" é o primeiro foco
    Dado que abro "inicio.html"
    Quando aperto Tab uma vez
    Então o foco está em "Pular para o conteúdo"

  Cenário: Tecla / leva o foco à busca global
    Dado que abro "inicio.html"
    Quando aperto a tecla "/"
    Então o foco está no campo "busca-global"

  Cenário: Alternar tema escuro
    Dado que abro "inicio.html"
    Quando toco em "Tema"
    Então o atributo data-bs-theme do documento é "dark"

  Cenário: Alvos de toque têm ao menos 44 px
    Dado que a tela tem 390 px de largura
    Quando abro "editar-pagina.html"
    Então todo botão e link do menu tem altura mínima de 44 px

  Cenário: Menu de 5 itens
    Dado que abro "inicio.html"
    Então o menu principal tem os itens "Início, Conteúdo, Revisar, Site, Mais"

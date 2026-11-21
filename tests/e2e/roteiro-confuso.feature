# language: pt
@roteiro
Funcionalidade: Roteiro e botão "Isto ficou confuso"
  Cenário: Roteiro lista 4 caminhos
    Dado que abro "index.html"
    Então vejo 4 caminhos de teste

  Cenário: Anotar um ponto confuso e copiar o resumo
    Dado que abro "index.html"
    Quando toco em "Isto ficou confuso" no topo
    E escrevo a anotação "Não achei o Publicar"
    Então o resumo contém "Não achei o Publicar"
    E o contador de pontos mostra 1

  Cenário: Apagar anotações zera o resumo
    Dado que há 1 anotação guardada
    Quando toco em "Apagar anotações"
    Então o contador de pontos mostra 0

# language: pt
Funcionalidade: Texto enxuto no painel
  # Verificados por regression/marcacao-e-texto.test.mjs (HTML real, sem navegador)
  # e, para as telas principais, também por steps/ajuda.spec.mjs.

  Cenário: telas principais: nenhum padrão proibido no texto visível
    Dado o HTML das telas principais do painel
    Então nenhuma tela contém padrão proibido do guia de voz

  Cenário: telas principais: ajuda de campo cabe em uma linha (≤ 140 caracteres)
    Dado o HTML das telas principais do painel
    Então nenhuma ajuda passa do limite de caracteres

  Cenário: onda 2 (demais telas): nenhum padrão proibido
    Dado o HTML das demais telas do painel
    Então nenhuma tela contém padrão proibido do guia de voz

  Cenário: avisos que evitam erro continuam nas telas
    Dado o HTML das telas principais do painel
    Então a tela de cópias avisa para não fechar a página
    E a lixeira avisa que apagar de vez não desfaz
    E a nova conta pede senha de pelo menos 10 caracteres

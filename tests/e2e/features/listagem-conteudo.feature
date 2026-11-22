# language: pt
Funcionalidade: Nova listagem de conteúdo (ícone, título em destaque, ações com menu ⋯)

  Contexto:
    Dado que estou autenticado como editor no painel
    E existem conteúdos de várias classes, com títulos longos, publicado, privado e agendado

  Cenário: Retrato do celular mostra cartões com ações em uma fileira
    Dado que uso um celular em retrato 390x844
    Quando abro a lista de conteúdo
    Então cada linha mostra o ícone da classe, o título e a linha de metadados
    E as ações ficam em uma fileira abaixo, todas com altura de pelo menos 44 px
    E a página não tem rolagem horizontal

  Cenário: Paisagem do celular mostra linha horizontal sem quebrar as ações
    Dado que uso um celular em paisagem 844x390
    Quando abro a lista de conteúdo
    Então o título ocupa o espaço restante e as ações ficam à direita em uma linha só

  Cenário: Tablet mostra linha horizontal
    Dado que uso um tablet 768x1024
    Quando abro a lista de conteúdo
    Então o título ocupa o espaço restante e as ações ficam à direita em uma linha só

  Cenário: Desktop mostra lista densa
    Dado que uso um desktop 1280x800
    Quando abro a lista de conteúdo
    Então a data e os filhos aparecem discretos à direita

  Cenário: Só três ações à vista e o resto no menu Mais
    Quando abro a lista de conteúdo
    Então cada linha mostra no máximo 3 ações à vista
    E abrir o menu "Mais ações" revela Copiar, Recortar e Mover
    E "Apagar" é o último item do menu

  Cenário: O menu abre e fecha pelo teclado, sem JavaScript
    Quando foco o botão "Mais ações" e aperto Enter
    Então o menu está aberto
    Quando aperto Enter de novo
    Então o menu está fechado e o foco continua no botão

  Cenário: A linha mostra o nome da classe como texto só na linha pequena
    Quando abro a lista de conteúdo
    Então o ícone tem o nome da classe em title e no rótulo acessível
    E não aparece "Por: —"

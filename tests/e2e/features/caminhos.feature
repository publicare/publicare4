# language: pt
Funcionalidade: Os quatro caminhos do roteiro

  Cenário: Trocar o texto da Suíte Master e publicar
    Dado que abro a lista de quartos
    Quando edito a "Suíte Master" e mudo o texto
    Então a barra mostra "Alterações não salvas"
    Quando salvo o rascunho
    Então a barra mostra "Salvo às"
    Quando publico
    Então vejo o aviso de publicado

  Cenário: Criar um evento com data de saída
    Dado que abro o formulário de novo evento
    Quando ligo "Ter data de validade" e informo uma data de saída no passado
    E tento publicar
    Então vejo erro na data de saída e nada é publicado

  Cenário: Configurar o contato do site com e-mail inválido
    Dado que abro a aba de contato do site
    Quando digito um e-mail incompleto e salvo
    Então vejo o aviso sob o campo de e-mail

  Cenário: Achar a Suíte Antiga e recuperá-la
    Dado que busco por "Suíte"
    Quando recupero a "Suíte Antiga"
    Então ela volta como privada

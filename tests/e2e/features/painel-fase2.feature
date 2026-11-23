# language: pt
Funcionalidade: Painel fase 2 — telas de sistema com abas, seções e ação principal
  Cada cenário é coberto por um teste PHPUnit (PainelFase2PontaAPontaTest, sem navegador)
  e, quando há navegador e prévia, por um teste Playwright (steps/painel-fase2.spec.mjs).

  Esquema do Cenário: Toda tela redesenhada tem título, migalhas e menu de cinco itens
    Dado que entrei como administrador
    Quando abro "<tela>"
    Então há um único título, migalhas e no máximo 5 itens no menu
    E o item "<ativo>" do menu está marcado como atual
    E a página não tem script nem estilo em linha

    Exemplos:
      | tela                      | ativo   |
      | Atualização               | Mais    |
      | Cópias de segurança       | Mais    |
      | Tarefas agendadas         | Mais    |
      | Contas                    | Mais    |
      | Tipos de conteúdo         | Mais    |
      | Peles e moldes            | Mais    |
      | Domínios                  | Site    |
      | Configurações do site     | Site    |
      | Revisão                   | Revisar |

  Cenário: Atualização tem uma ação principal e três abas
    Dado que entrei como administrador
    Quando abro "Atualização"
    Então vejo as abas "Atualizar", "Histórico e voltar atrás" e "Configuração"
    E há uma única ação principal no cabeçalho
    E o andamento fica escondido quando não há atualização em curso

  Cenário: Diagnóstico técnico fica recolhido na Configuração
    Dado que entrei como administrador
    Quando abro a aba "Configuração" da atualização
    Então o bloco "Diagnóstico" está recolhido

  Cenário: Abas funcionam sem JavaScript
    Dado que o JavaScript está desligado
    Quando abro a aba "Histórico e voltar atrás" pelo link
    Então o endereço tem "?aba=historico" e a aba aparece como atual

  Cenário: Quem não administra não abre as telas de sistema
    Dado que entrei como editor
    Quando tento abrir "Atualização"
    Então o painel recusa o acesso

  Cenário: Sem sessão vai para Entrar
    Dado que não entrei
    Quando abro "Atualização"
    Então sou levado para a tela de entrar

  Cenário: Revisão sem pendências explica o que fazer
    Dado que entrei como administrador
    Quando abro "Revisão"
    Então vejo "Nada aguardando revisão agora."

  Cenário: Rotas antigas redirecionam
    Dado que entrei como administrador
    Quando abro o endereço antigo "/_pbl/pendencias"
    Então o painel responde 303 para "/_pbl/revisar"

  Cenário: Celular em retrato e paisagem sem rolagem lateral
    Dado que a tela tem 390x844 ou 844x390
    Quando abro "Atualização"
    Então não há rolagem horizontal
    E o menu, a ação principal e as abas ficam visíveis

  Cenário: Tema escuro mantém o contraste
    Dado que o tema escuro está ativo
    Quando abro "Atualização"
    Então o título e a ação principal têm contraste mínimo de 4.5:1

---
titulo: Barra de edição nas páginas do site
resumo: Os ícones de edição que aparecem nas páginas do site para quem está logado.
capitulo: conteudo
ordem: 60
---

# Barra de edição nas páginas do site

Quando você está logado, ao lado do título de cada página (e de cada item de uma lista) aparecem ícones pequenos e discretos. Só aparecem as ações que a sua conta pode fazer naquele conteúdo.

- **Lista** (três linhas): abre a lista do que está dentro deste conteúdo, no painel.
- **Mais**: cria um novo conteúdo dentro deste. Se houver mais de um tipo permitido, o painel pergunta qual.
- **Lápis**: edita.
- **Olho / olho cortado**: publica ou tira do ar.
- **Setas**: move.
- **Lixeira**: abre a tela de confirmação do painel; nada é apagado sem você confirmar.

Passe o mouse (ou use a tecla Tab) para o ícone ficar mais forte. No celular ficam sempre visíveis. Visitantes não veem nada.

## Para quem faz o tema (pele)
No molde, marque a página com `data-pbl-objeto="ID"` no `<body>` e, em cada item de lista, um `<span class="pbl-acoes" hidden></span>` dentro do elemento que tem o `data-pbl-objeto`. Para mudar o visual, defina variáveis CSS como `--pbl-barra-cor`, `--pbl-barra-fundo`, `--pbl-barra-raio` e `--pbl-barra-opacidade`. A lista completa está em [docs/tecnica/barra-de-edicao.md](../tecnica/barra-de-edicao.md).

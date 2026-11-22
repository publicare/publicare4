# ADR 0032 — Listagem de conteúdo: linha única, menu ⋯ e ícone por tipo

Status: aceito. Complementa o ADR 0031 (novo painel).

## Contexto
A lista de conteúdo era uma tabela de 8 colunas com até 10 botões por linha, de alturas diferentes, que quebravam em 2–3 linhas no celular; o título ficava espremido e a data, quebrada.

## Decisão
1. **Componente único** `Pbl\Admin\LinhaDeConteudo` (renderização pura) com `DadosDaLinha` e `AcaoDeLinha`; usado por Conteúdo, Pendências, Meus envios, Vencidos e Lixeira. Marcação `<ul class="pbl-lista-conteudo"><li>`. As telas continuam decidindo **quais** ações existem (mesmas permissões, rotas, CSRF e origem).
2. **Ações**: 3 à vista (Editar, estado, Abrir no site ↗) e o resto em `<details class="pbl-mais">` (sem JavaScript; Apagar por último, vermelho, com a confirmação existente). Todos os controles têm a mesma altura (44 px no toque). «Agendar» não é ação de linha (é feito no editor). «Devolver» (Pendências) fica ao lado do menu, para não aninhar formulários.
3. **Layout**: um só HTML e três grades CSS: cartão (≤ 575 px), linha única (576–991 px) e lista densa (≥ 992 px), com tokens `--pbl-*` (claro/escuro), sem estilo em linha.
4. **Ícone por tipo**: `IconesDeClasse::resolver` usa o ícone gravado; senão o padrão do tipo fixo (pasta, página→documento, formulário, arquivo, imagem, link); senão `generico` (fora do seletor). SVG local, `currentColor`, com `title` e rótulo acessível = nome do tipo. Sem migração de dados.
5. A lista deixa de ser `<table>` e por isso sai da regra `tabelasEmCartoes`.

## Consequências
- Uma só marcação/CSS para todas as listas; sem novas dependências, rotas ou permissões.
- «Selecionar todos» vira rótulo acima da lista; a barra «Com os selecionados» fica após a lista, sem posição fixa.
- O menu ⋯ sem JavaScript só fecha ao tocar de novo (ESC/clique fora seria melhoria opcional).
- Testes que dependiam do HTML antigo foram migrados.

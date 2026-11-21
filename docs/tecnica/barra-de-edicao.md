# Barra de edição das páginas públicas

ADR 0022 §9. Arquivos: `public/_pbl/assets/pbl-barra.js`, `pbl-barra.css`, `app/src/Admin/ApiDeAcoes.php`, `app/src/Conteudo/TiposAceitos.php`.

## Quando aparece
Só com o cookie indicador `pbl4_ind=1` (legível por JS, sem valor secreto). Sem ele o script não pede nada, não injeta CSS e não cria DOM. Cookie forjado não ajuda: a autorização é refeita no servidor (`GET /_pbl/api/acoes` devolve 401 sem sessão e o script limpa o indicador). A página estática nunca recebe dados da sessão; tudo vem da API em JSON (`Cache-Control: no-store`).

## API
`GET /_pbl/api/acoes?objeto=ID` ou `?objetos=1,2,3` (até 100; 101 devolve 400 `muitos_objetos`). Cada ação só aparece se `Permissoes::pode` (ou `podeCriarSob`) permitir, e a ordem é a de exibição:

| Chave | Quando | URL |
|---|---|---|
| `listar` | pode editar e há filhos fora da lixeira | `/_pbl/conteudo/ID` |
| `conteudo` | alias obsoleto de `listar` (JS antigo em cache); sai numa versão futura | idem |
| `criar` | pode criar sob o objeto e há tipo aceito | `/_pbl/objeto/ID/novo?classe=T` (um tipo) ou `/novo` (seletor do painel) |
| `editar` | pode editar | `/_pbl/objeto/ID/editar` |
| `publicar` / `despublicar` | pode publicar | `POST /_pbl/api/estado` |
| `mover` | pode apagar | `/_pbl/objeto/ID/mover` |
| `excluir` | pode apagar | `/_pbl/objeto/ID/apagar` (tela de confirmação; nunca apaga direto) |

`POST /_pbl/api/estado` exige CSRF, origem igual e `versao` (bloqueio otimista); aceita só `publicar` e `despublicar`.

## Uso no molde
- `data-pbl-objeto="ID"` no `<body>`: as ações aparecem junto do `<h1>`.
- Em cada item de lista: elemento com `data-pbl-objeto="ID"` contendo `<span class="pbl-acoes" hidden></span>`.

## Variáveis CSS
Defina em `:root`, `body` ou `.pbl-acoes`; não é preciso sobrescrever regras.

| Variável | Padrão |
|---|---|
| `--pbl-barra-cor` | `#495057` (escuro: `#ced4da`) |
| `--pbl-barra-cor-destaque` | `#0d6efd` (escuro: `#6ea8fe`) |
| `--pbl-barra-cor-perigo` | cor do hover da lixeira |
| `--pbl-barra-fundo` / `--pbl-barra-fundo-hover` | transparente / sombra leve |
| `--pbl-barra-borda` | transparente |
| `--pbl-barra-raio` | `.375rem` |
| `--pbl-barra-opacidade` / `-hover` / `-toque` | `.4` / `1` / `.7` (celular) |
| `--pbl-barra-tamanho` | `16px` (ícone) |
| `--pbl-barra-alvo` | `1.75rem` (`2.75rem` em toque) |
| `--pbl-barra-espaco` | `.125rem` |
| `--pbl-barra-foco` | `#0a58ca` |
| `--pbl-barra-transicao` | `opacity .15s ease` |

Respeita `prefers-color-scheme`, `prefers-reduced-motion` e `forced-colors`. As classes `btn btn-*` não são mais usadas: peles que as sobrescreviam para a barra devem migrar para as variáveis.

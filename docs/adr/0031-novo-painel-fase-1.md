# ADR 0031 — Novo painel, fase 1: menu por tarefa, abas com interruptores e sistema de formulário

Status: aceito. Resume as três decisões da fase 1 (navegação, divulgação progressiva e formulário).

## Contexto
O menu tinha até 12 itens, exigia JavaScript abaixo de 1200 px (D1), marcava o item errado em `/objeto/…` e `/classe…` (D7) e usava vocabulário técnico (D12). A edição mostrava 16 controles de uma vez (D2) e painéis e ajudas dependiam do `collapse` do Bootstrap (D3, D15). A referência visual é `docs/ux/painel-proposta.md`, `docs/ux/formularios-proposta.md` e o protótipo aprovado pelo dono.

## Decisão
1. **Menu por tarefa**: Início, Conteúdo, Revisar (contador; só quem revisa ou envia), Site (só quem administra um site) e Mais (todos; quem não é administrador vê lá só Tipos de conteúdo, em leitura, como antes no menu). É um `<details open>` e funciona sem JavaScript; em 1024 px ou mais vira coluna lateral. O item ativo sai de um mapa de prefixos (`Pagina::itemAtivo`). Rotas novas `/_pbl/revisar` e `/_pbl/mais`; `/pendencias` e `/meus-envios` respondem **303** para a nova. `/objeto/…` e `/classe/…` seguem como endereços canônicos. Vocabulário: Objeto→Conteúdo, Classe→Tipo de conteúdo, Propriedade→Campo, Molde→Modelo de página, só no texto visível.
2. **`<details>`** no lugar de `collapse` (`recolhivel`, `ajuda`): abrem sem JS e com `open` quando há valor ou erro. **Abas de formulário**: um só `<form>` e um só POST; todos os painéis estão no HTML (empilhados sem JS) e o JS mostra um por vez (`role=tablist`, setas, Home, End; `?aba=`). O POST 422 volta na aba do primeiro erro. A aba padrão tem no máximo 6 controles nos tipos padrão. **Interruptores**: o envio traz `usar_x=0|1`; o **servidor é a autoridade** (`aplicarInterruptores`): desligado grava o padrão do grupo. Sem JS a caixa nasce marcada e os campos ficam visíveis, então nada digitado se perde; com JS nasce ligada só se há valor ou erro. "Salvar e publicar" (`acao=salvar_publicar`) exige `Acao::Publicar` (403 sem ela) e aparece como botão principal quando o conteúdo ainda não está publicado, como no protótipo.
3. **Sistema de formulário próprio**: `pbl-formulario.css` (tokens `--pbl-*`, claro e escuro, foco de 3 px, alvo de 44 px) e `pbl-formulario.js` (carregado só em telas com `data-pbl-form`), mais `pbl-js.js` (marca `html.js` e aplica o tema antes de pintar). Nada de inline; a CSP não muda, exceto `img-src blob:` nas telas com `data-pbl-imagem` (miniatura do arquivo escolhido). As prévias usam só `textContent`.

## Consequências
- Menu e painéis funcionam sem JS; navegação por teclado é nativa.
- Dois CSS convivem (`pbl.css` legado e `pbl-formulario.css`, que o vence) até as fases seguintes migrarem as outras telas.
- Sem JS a edição fica longa (abas empilhadas), mas completa.
- Fora desta fase: busca global, configurações do site, "Mais › Avançado", peles e tipos, lembrar aberto/fechado (F11). 

## Rodada 2 (prévia e seletor de conteúdo)
- **Seletor de conteúdo (campos `ref`)**: sem endpoint novo. O servidor renderiza um `<select data-pbl-seletor>` com os conteúdos do próprio site (do tipo pedido, até 400, fora da lixeira; o valor atual sempre aparece), e o `pbl-formulario.js` põe o campo de busca que já filtra os outros seletores. Sem JS é o `<select>` nativo; sem conteúdo do tipo ou acima do teto, volta ao campo numérico.
- **Modo prévia** (`Pbl\Previa\ModoPrevia`): só existe onde o gerador (`previa/gerar-pacote.php`) gravou `dados/previa.flag`, isto é, no pacote descartável. Com o marcador, (1) um host `pv-…` atende o primeiro site (o servidor da prévia usa host próprio, e `localhost` não pode ser o único aceito) e (2) `GET /_pbl/previa-entrar?conta=admin|editor|autor&ir=/_pbl/…&tema=claro|escuro` abre a sessão de uma conta fictícia, porque a captura só faz GET. O destino é restrito a `/_pbl/…`. Sem o marcador (instalação real) o host exato continua obrigatório e a rota responde 404. Risco aceito e mitigado: o marcador está em `dados/` (fora do Git e do pacote de release).

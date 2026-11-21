# Protótipo clicável do novo painel (PBL4), rodada 2

HTML/CSS/JS estático para testar **navegação e formulários** antes de qualquer PHP. Dados fictícios do "Site Exemplo"; nada é gravado. Fora de `app/` e `public/`; não entra no pacote de release (`.gitattributes`: `export-ignore`). Todas as telas têm `noindex`.

Base: `docs/ux/painel-proposta.md` e `docs/ux/formularios-proposta.md`. A rodada 1 foi reprovada; esta é uma **reescrita**, com o visual descrito em [`GUIA-VISUAL.md`](GUIA-VISUAL.md) (e ao vivo em `guia.html`).

## Abrir
- Por arquivo: `prototipo-ux/index.html`. Por servidor: `cd prototipo-ux && python3 -m http.server 8080`.
- Prévia VSF: modo estático, raiz `prototipo-ux`, entrada `index.html`.

`index.html` é o roteiro com 4 tarefas. "Isto ficou confuso" (no topo de toda tela) anota em `localStorage` e o resumo copiável fica em `index.html#anotacoes`.

## Telas
Início · Conteúdo (seções Quartos, Eventos, Novidades) · Ver · Criar · Editar e Novo (página, notícia, evento; abas Conteúdo, Publicação, Avançado) · Busca · Site (Configurações, 5 abas) · Revisar · Mais (Contas, Aparência, Atualização, Cópias) · Guia visual. Itens da lista abrem telas de exemplo (todos "Ver" mostram a Suíte Master).

## Estrutura
- `*.html`: **gerados** por `gerar/` (edite os `.mjs`, rode `npm run gerar`).
- `assets/proto.css`: tokens `--pbl-*` e componentes. `assets/regras.js`: validação e contador (puros, testados). `assets/proto.js`: interações. `assets/tema.js`: tema antes da pintura.
- Sem JavaScript: abas empilhadas, interruptores com campos visíveis, menu aberto. As interações dependem do JS.

## Testes
`cd prototipo-ux && npm test` (links, ids, rótulos, sem inline/CDN/sombra/gradiente, noindex, 5 itens de menu, interruptores, contraste AA nos dois temas, guia × CSS). Na raiz: `node --test "tests/unit/*.test.mjs" "tests/integration/*.test.mjs" "tests/smoke/*.test.mjs"`.

## Limites
Sem backend, sem upload real, sem conflito 409. Domínios, Redirecionamentos, Tipos de conteúdo e Tarefas ficam fora. Pergunta em aberto: feeds RSS ficam em Comportamento › Opções avançadas.

## Infra
`infra/`: Dockerfile (nginx sem root), compose, `gerar-pacote-previa.sh`, `runbook.md`; job `prototipo-ux` no CI.

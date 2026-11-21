# Protótipo clicável do novo painel (PBL4)

HTML/CSS/JS estático para testar **navegação e formulários** no celular antes de qualquer PHP. Dados fictícios do "Site Exemplo". Nada é gravado de verdade. Fora de `app/` e `public/`; não entra no pacote de release (`.gitattributes`: `export-ignore`).

Base: `docs/ux/painel-proposta.md` e `docs/ux/formularios-proposta.md`. Visual: Bootstrap 5.3.8 local do painel atual, tema claro/escuro.

## Abrir
- Por arquivo: abra `prototipo-ux/index.html`.
- Por servidor estático: `cd prototipo-ux && python3 -m http.server 8080` e abra `http://localhost:8080/`.
- Prévia VSF: pedido em modo estático, raiz `prototipo-ux`, entrada `index.html` (sem PHP; todas as páginas têm `noindex`).

`index.html` é o **roteiro** com 4 caminhos (trocar o texto de um quarto e publicar; criar um evento com data de saída; configurar o contato; achar algo apagado). O botão **Isto ficou confuso** (no topo de toda tela) anota o ponto em `localStorage` (`pbl-prototipo-confusoes`); o resumo copiável fica no fim do roteiro.

## Telas
Início · Conteúdo (+ Eventos, Novidades) · Ver conteúdo · Criar · Editar (página, notícia, evento; abas Conteúdo, Publicação, Avançado) · Busca · Configurações do site (5 abas) · Revisar · Mais (Contas, Peles, Atualização, Cópias de segurança).

## Estrutura
- `*.html`: telas **geradas** por `gerar/gerar.mjs` (edite os `.mjs` e rode `npm run gerar`; os `.html` ficam versionados).
- `assets/proto.css`: tokens (`--pbl-*`) e layout. `assets/proto.js`: abas, recolher, validação, contador, prévia, seletores, busca fake, salvo/não salvo.
- Sem JavaScript as telas continuam navegáveis (abas empilhadas, menu aberto); as interações dependem dele.

## Testes
`cd prototipo-ux && npm test` (Node 20+, sem dependências): links e âncoras, ids únicos, rótulos de formulário, sem `style`/`script` inline/CDN, `noindex`, alcance sem JS e contraste AA dos tokens nos dois temas.

## Limites
Sem backend, sem upload real, sem conflito 409. Domínios, Redirecionamentos, Tipos de conteúdo e Tarefas ficam fora. Não testado em navegador real nesta etapa: confira em 360, 390 e 430 px e com teclado.

## Infra
`infra/`: Dockerfile (nginx estático sem root), docker-compose.yml, `gerar-pacote-previa.sh` e `runbook.md`. Job `prototipo-ux` no `.github/workflows/ci.yml`.

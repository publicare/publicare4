# Runbook — protótipo clicável (prototipo-ux)

Estático, sem PHP, sem banco, sem segredos. Nada aqui altera `app/`, `public/` nem o painel atual. O protótipo fica fora do pacote de release (`.gitattributes`: `export-ignore`).

## Subir
| Como | Comando | Onde abrir |
|---|---|---|
| Arquivo | abrir `prototipo-ux/index.html` | navegador |
| Servidor estático | `cd prototipo-ux && python3 -m http.server 8080` | http://localhost:8080/ |
| Contêiner | `docker compose -f prototipo-ux/infra/docker-compose.yml up --build` | http://localhost:8090/ |
| Celular (dono) | prévia VSF (abaixo) | link da prévia |

## Prévia VSF (protocolo v2)
1. `prototipo-ux/infra/gerar-pacote-previa.sh [saida.tar.gz]` — falha se alguma tela não tiver `noindex`.
2. Pedido: `{"versao":2,"modo":"estatico","raiz":"prototipo-ux","entrada":"index.html","php":false,"noindex":true}` (o script o imprime).
3. A fábrica publica o pacote e devolve o link; abrir no celular e seguir o roteiro (`index.html`).
O pacote é binário: não versionar. O apply real (publicação) é feito pela fábrica, com aprovação humana; nada aqui faz deploy.

## Verificar (saúde)
- `curl -fsS http://localhost:8090/saude` → `ok`
- `curl -sI http://localhost:8090/ | grep -i x-robots-tag` → `noindex, nofollow`
- `cd prototipo-ux && npm test`; na raiz: `node --test "tests/unit/*.test.mjs" "tests/integration/*.test.mjs" "tests/smoke/*.test.mjs"`.

## Observabilidade
Não há métrica nem log de aplicação. Acesso: `docker compose -f prototipo-ux/infra/docker-compose.yml logs prototipo`. As anotações do botão "Isto ficou confuso" ficam só no `localStorage` do navegador do dono (chave `pbl-prototipo-confusoes`); nada é enviado.

## Problemas comuns
| Sintoma | Causa provável | Ação |
|---|---|---|
| Tela sem estilo | assets não copiados (contexto de build errado) | build a partir da raiz do repositório, como no compose |
| Teste "gerador reproduz os HTML" falha | editou `.html` à mão | editar `gerar/*.mjs` e rodar `npm run gerar` em `prototipo-ux` |
| Botão não responde na prévia | CSP bloqueou script inline | não usar `style=`/`<script>` inline (os testes barram) |
| Anotações sumiram | limpou dados do navegador | refazer; copiar o resumo ao fim do roteiro |

## Rollback
Sem estado. Voltar = `git revert` do commit e republicar o pacote anterior; ou parar o contêiner (`docker compose -f prototipo-ux/infra/docker-compose.yml down`). Para retirar tudo: apagar `prototipo-ux/` (nada mais depende dele, exceto o job `prototipo-ux` do CI e `tests/{unit,integration,smoke,e2e}`).

## Segurança
Contêiner sem root (nginx-unprivileged), `read_only`, `cap_drop: ALL`, `no-new-privileges`, porta ligada só a 127.0.0.1. CSP `default-src 'none'` com `self` para CSS/JS/imagens; `robots.txt` bloqueia tudo; dados 100% fictícios ("Site Exemplo").

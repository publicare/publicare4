# ADR 0009 — Peles de saída: HTML, JSON e outros formatos (headless/BFF)

Status: proposto (2026-11-01). Complementa os ADRs [0003](0003-plataforma-low-code.md) e [0007](0007-galeria-pacotes-peles.md).

## Contexto

O PBL3 já servia mais que HTML: RSS, XML, mapas de site e exportações eram "peles" com outro tipo de conteúdo. Hoje é comum separar back e front: um front em SPA, site estático ou app móvel consome dados de um back-end. O ADR 0003 prevê uma API JSON para o admin; falta o equivalente para **conteúdo publicado**, moldado por site.

## Decisão

1. **Pele tem tipo de saída.** O manifesto declara o formato: `html` (padrão), `json`, `rss`, `xml`, `csv`, `texto`. O `Content-Type`, o escape e o cache dependem dele. A mesma árvore de objetos pode ter várias peles (HTML para o portal, JSON para um app).
2. **Pele JSON é estruturada, não texto.** Em vez de montar JSON por concatenação (frágil e inseguro), o PBL4 ganha comandos de **construção de árvore** (objeto, lista, campo, repetição sobre consulta), e o serializador gera JSON válido com escape garantido. Dá para escrever em PBL ou, no modo simples, **declarativamente** (mapa campo → chave, `expand` de referências, limite e ordenação), sem escrever molde.
3. **API de conteúdo separada da API do núcleo.**
   - **API do núcleo**: CRUD, admin, autenticação (ADR 0003, §9); privada, protegida, estável por versão.
   - **API de conteúdo (peles JSON)**: leitura do **publicado**, moldada por site, cacheável e pública por padrão. É o BFF: o front recebe exatamente o formato de que precisa, sem ligar para o schema interno.
4. **Roteamento por tipo.** Prefixo ou extensão (`/api/v1/...` ou `.json`) e/ou cabeçalho `Accept` escolhem a pele de saída. `url_amigavel` e a raiz do site valem igual (fluxo 1).
5. **Cache por formato.** Página/resposta publicada é cacheada por (objeto, pele, parâmetros relevantes) e invalidada por evento (ADR 0003 §8). Respostas JSON levam `ETag` e `Cache-Control`. Quando o formato é escolhido pelo cabeçalho `Accept` (mesma URL, HTML ou JSON), a resposta leva **`Vary: Accept`** para CDN e proxies não servirem um formato ao cliente que pediu o outro; formato escolhido por prefixo ou extensão na URL não precisa de `Vary`.
6. **Segurança.**
   - Só conteúdo **publicado e permitido**; os campos expostos são os declarados na pele (lista branca), nunca "tudo do objeto".
   - **CORS** configurável por site (origens permitidas); credenciais só quando necessário.
   - Para áreas protegidas, **token de API** com escopo e expiração (distinto da sessão do admin); limitação de taxa por token e IP.
   - Escrita (formulários, inscrições, comentários) passa por endpoints declarados nos formulários do fluxo 10, com CSRF/captcha adaptados a clientes sem sessão (token de formulário, captcha plugável).
7. **Versionamento.** Peles JSON são versionadas (`v1`, `v2`) para que mudar um front não quebre outro; mudança incompatível = nova versão da pele.
8. **Publicação estática (opcional).** Como a renderização acontece ao publicar (ADR 0003 §8), a pele JSON pode também **gravar arquivos** (`.json`) em disco ou bucket/CDN, permitindo front 100% estático e back fora do ar sem derrubar o site. É o caminho para o modo "gerador de site estático" sem outro produto.
9. **Descoberta e documentação.** Cada pele JSON expõe um esquema (JSON Schema/OpenAPI gerado da definição) para o front e para testes de contrato.

## Consequências

- Separar back e front fica possível sem trocar de produto; portais, apps e sites estáticos consomem o mesmo conteúdo.
- O custo é decidir cedo o **tipo de saída da pele** (manifesto e cache), mesmo que o JSON só chegue depois do MVP. O restante entra em "Em seguida".
- Cuidado de modelagem: a API de conteúdo é contrato público; mudar sem versionar quebra clientes.

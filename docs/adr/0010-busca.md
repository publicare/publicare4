# ADR 0010 — Busca: normalização, relevância e tolerância a erros de grafia

Status: proposto (2026-11-01). O nível A já está implementado. Complementa o [fluxo 8](../fluxos/08-busca-indexacao.md) e o [ADR 0003](0003-plataforma-low-code.md).

## Contexto

Quem pesquisa num portal digita sem acento, em maiúsculas, em outra ordem das palavras, no plural ou com erro de grafia (`Fernandha`, `advogdo`). Uma busca que só acha o texto exatamente como foi escrito é percebida como defeito, e é uma demanda recorrente de portais públicos. No PBL3 a busca dependia de um serviço externo (indexador Lucene em Java), inviável para quem instala em hospedagem comum.

## Decisão

Busca em **três níveis**, cada um útil sozinho, todos **dentro do banco** (SQLite, MySQL ou PostgreSQL) e sem serviço externo na instalação padrão.

### Nível A — normalizar (implementado)
`Pbl\Busca\Texto::normalizar()`: minúsculas, sem acentos, espaços reduzidos, feito **em PHP** na gravação e na consulta, com mapa próprio. O resultado é igual nos três bancos e não depende de `intl` nem de `unaccent`. Guardado em `objeto.titulo_busca` e `objeto_indice.texto_norm`; o operador `contem` e o parâmetro `busca` usam essas colunas.

### Nível B — busca de verdade
- Tabela `objeto_termo (objeto_id, termo, peso)`: os termos normalizados de cada objeto, com peso por campo (título 3, descrição 2, texto 1), preenchida ao salvar e ao publicar, **só para objetos publicados e vigentes**, sempre com o site.
- Consulta com várias palavras (todas precisam aparecer, em qualquer ordem), **prefixo** (`presid` acha `presidência`) e **plural/feminino simples** (normalização de sufixos do português: `casas`→`casa`, `ões`→`ão`).
- Relevância = soma dos pesos dos termos achados, com bônus quando o termo está no título. Ordenação `ordem="relevancia"`.
- Remove do índice ao despublicar, vencer ou apagar.

### Nível C — grafias parecidas
- Vocabulário do próprio site: `busca_vocabulario (termo, frequencia)` e `busca_trigrama (trigrama, termo)`.
- Termo sem resultado (ou com poucos): candidatos por trigramas em comum, filtrados por distância de Levenshtein (1 para termos curtos, 2 para os longos). Devolve "você quis dizer…" e, opcionalmente, busca automaticamente pelo termo corrigido.
- **Opcional por site** (`busca.tolerancia`), desligada por padrão: custo de índice e de consulta maior, e nem todo portal quer correção automática.
- Fonética do português (Soundex e afins) fica de fora: dá muito falso positivo.

### Escala
Interface `BuscaInterface` com o driver padrão (acima) e drivers opcionais para portais grandes (FTS5 do SQLite, `tsvector` do PostgreSQL, FULLTEXT do MySQL, Meilisearch), escolhidos por configuração, sem mudar os moldes (`localizar busca=…`, comando de busca).

## Segurança e privacidade

- Só indexa conteúdo publicado e vigente; o que sai do ar sai do índice na publicação seguinte (como o cache de páginas).
- Toda consulta é do site do chamador; nenhum texto digitado entra no SQL (parâmetros, curingas escapados); termos e tamanho da consulta têm teto.
- Registro das buscas dos visitantes (para melhorar o portal) é uma decisão à parte e, se existir, será opt-in e sem dado pessoal.

## Consequências

- A demanda de "ignorar maiúsculas e acentos" fica resolvida já no nível A, sem custo de índice.
- B e C exigem migrações novas (somente adicionam tabelas) e reindexação dos objetos existentes.
- Funciona em hospedagem comum até algumas dezenas de milhares de objetos; acima disso, o driver opcional.

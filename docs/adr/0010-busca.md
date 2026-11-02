# ADR 0010 — Busca: normalização, relevância e tolerância a erros de grafia

Status: parcialmente implementado (2026-11-01). Os níveis A e B estão implementados; o nível C e os drivers para portais grandes seguem propostos. Complementa o [fluxo 8](../fluxos/08-busca-indexacao.md) e o [ADR 0003](0003-plataforma-low-code.md).

## Contexto

Quem pesquisa num portal digita sem acento, em maiúsculas, em outra ordem das palavras, no plural ou com erro de grafia (`Fernandha`, `advogdo`). Uma busca que só acha o texto exatamente como foi escrito é percebida como defeito, e é uma demanda recorrente de portais públicos. No PBL3 a busca dependia de um serviço externo (indexador Lucene em Java), inviável para quem instala em hospedagem comum.

## Decisão

Busca em **três níveis**, cada um útil sozinho, todos **dentro do banco** (SQLite, MySQL ou PostgreSQL) e sem serviço externo na instalação padrão.

### Nível A — normalizar (implementado)
`Pbl\Busca\Texto::normalizar()`: minúsculas, sem acentos, espaços reduzidos, feito **em PHP** na gravação e na consulta, com mapa próprio. O resultado é igual nos três bancos e não depende de `intl` nem de `unaccent`. Guardado em `objeto.titulo_norm` e `objeto_indice.texto_norm` (colunas novas; `titulo_busca`, a antiga, não é reescrita, para a versão anterior do código continuar funcionando se for preciso voltar atrás). A normalização pode alongar o texto (`ß`→`ss`), então as colunas têm o dobro do tamanho das originais; o operador `contem` e o parâmetro `busca` usam essas colunas.

### Nível B — busca de verdade (implementado, fase 1c)
- Tabela `objeto_termo (objeto_id, termo, pontos)` (migração 10): os termos normalizados e reduzidos ao singular de cada objeto, com os pontos do melhor campo onde aparecem (título 300, descrição 200, texto 100, mais 30 se é a primeira palavra do campo). Alimentada por `IndexadorDeObjeto` ao criar e ao alterar título, descrição ou dados (inclui restaurar versão). O texto vem das propriedades `texto` e `html` (sem marcação); no máximo 1500 termos e 60 mil caracteres por objeto.
- Consulta com várias palavras (todas precisam aparecer, em qualquer ordem e em qualquer campo), **prefixo** (`presid` acha `presidência`) e **plural simples** (`Termos::singular`: `ões/ães`→`ão`, `ais/eis/ois`→`al/el/ol`, `ns`→`m`, `res/zes`→`r/z`, `s` final), igual na gravação e na consulta. Feminino e conjugação de verbos **não** entram. Palavras vazias (`de`, `da`, `para`...) e letras soltas são ignoradas; busca só com elas não acha nada.
- Relevância = soma, por palavra da consulta, dos pontos do campo (+50 se a palavra é inteira, não só prefixo), mais 200 se o título começa pela frase digitada (400 se é igual). `ordem="relevancia"`; é o padrão quando há `busca` e nenhuma ordem; combina com outras (`relevancia, -data_publicacao`). Sem `busca`, é ignorada.
- **Decisão (difere do rascunho original): o índice guarda objetos em qualquer estado e a visibilidade é aplicada na consulta**, pelo mesmo filtro de estado e datas de todas as consultas públicas. Assim publicar, despublicar, agendar e vencer valem na hora, sem reindexar nem depender de um evento (a vigência acaba sozinha com o tempo), e a busca do administrador (`apenasVisiveis: false`) usa o mesmo índice. O texto de rascunhos fica na tabela, mas nunca sai numa consulta pública.
- Limites: 8 palavras, 200 caracteres de consulta, palavras de 2 a 40 caracteres.
- Desempenho medido (SQLite em memória, 5 mil objetos com ~45 palavras cada, teste `BuscaNivelBTest`): criar e indexar 5 mil objetos ≈ 3 s; busca de duas palavras com contagem ≈ 0,09 s; de uma palavra ≈ 0,03 s. A partir de dezenas de milhares de objetos, ver "Escala".

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
- B exigiu uma migração que só adiciona a tabela e já indexa os objetos existentes; C exigirá outra. Não há (ainda) comando para reindexar tudo à mão.
- Funciona em hospedagem comum até algumas dezenas de milhares de objetos; acima disso, o driver opcional.

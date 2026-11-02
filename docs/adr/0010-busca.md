# ADR 0010 — Busca: normalização, relevância e tolerância a erros de grafia

Status: parcialmente implementado (2026-11-02). Os níveis A, B e C estão implementados; só os drivers para portais grandes seguem propostos. Complementa o [fluxo 8](../fluxos/08-busca-indexacao.md) e o [ADR 0003](0003-plataforma-low-code.md).

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

### Nível C — grafias parecidas (implementado, fase 1c)
- **Decisão (difere do rascunho original): sem tabela nova.** O rascunho previa `busca_vocabulario (termo, frequencia)` e `busca_trigrama (trigrama, termo)`, povoadas à parte. Na prática isso duplicaria a visibilidade (o nível B já decidiu guardar `objeto_termo` com qualquer estado e filtrar na consulta): manter um vocabulário separado exigiria o mesmo cuidado de visibilidade **duas vezes**, com risco de desalinhar. A sugestão consulta `objeto_termo` direto, filtrado pela mesma condição de visibilidade pública de toda consulta (`Pbl\Busca\Visibilidade`, extraída do `MotorDeConsulta` para não ter dois textos do mesmo filtro) — sem índice extra, sem reindexação própria, sem custo de gravação.
- Para cada palavra da consulta que **não é prefixo de nenhum termo visível**, busca candidatos no vocabulário visível com tamanho parecido (até 2 caracteres de diferença, teto de 300 candidatos avaliados), calcula a distância de Levenshtein em PHP (1 para termos de até 5 letras, 2 para os maiores) e escolhe o mais próximo (desempate pelos pontos, like uma frequência). Palavra já encontrável fica como está. Se **qualquer** palavra não tem candidato dentro do limiar, a função inteira devolve nada: uma sugestão parcial ou arriscada é pior que nenhuma.
- Devolve "você quis dizer…" (`BuscaInterface::sugerir`); **não** busca automaticamente pelo termo corrigido — quem usa o molde decide (`%SUGESTAO`, ver [linguagem-pbl4.md](../linguagem-pbl4.md)), por exemplo montando um link para a busca corrigida.
- **Opcional por site** (`site.busca_tolerancia`, migração 12), desligada por padrão: analisar candidatos e calcular Levenshtein a cada busca sem resultado é mais caro que a busca comum, e nem todo portal quer correção automática. Liga/desliga pelo painel (`/_pbl/site/{id}/busca`).
- Portável entre SQLite, MySQL e PostgreSQL: só `SELECT`/`GROUP BY`/`LENGTH` e PHP puro; sem DDL em tempo de execução, sem extensão do banco.
- Fonética do português (Soundex e afins) fica de fora: dá muito falso positivo.
- **Ponto de extensão para portais grandes**: `BuscaInterface::sugerir` pode sempre devolver `null` num driver de escala (FTS5, `tsvector`, FULLTEXT, Meilisearch) sem quebrar nada — a sugestão é um extra do nível C, não um requisito do nível B. Um driver de escala que queira sugestão própria (por exemplo, a correção nativa do Meilisearch) implementa o método com sua própria lógica; não há, ainda, um driver assim neste repositório.

### Escala
Interface `BuscaInterface` com o driver padrão (acima) e drivers opcionais para portais grandes (FTS5 do SQLite, `tsvector` do PostgreSQL, FULLTEXT do MySQL, Meilisearch), escolhidos por configuração, sem mudar os moldes (`localizar busca=…`, comando de busca).

## Segurança e privacidade

- O índice (`objeto_termo`) guarda termos de objetos em qualquer estado (nível B); a visibilidade pública é aplicada **em toda consulta** que lê o índice — a busca de verdade (`fragmento`) e a sugestão de grafia parecida (`sugerir`) — pela mesma condição (`Pbl\Busca\Visibilidade`), nunca na gravação. Conteúdo privado, não publicado ou na lixeira nunca aparece num resultado nem numa sugestão.
- Toda consulta é do site do chamador; nenhum texto digitado entra no SQL (parâmetros, curingas escapados); termos e tamanho da consulta têm teto.
- Registro das buscas dos visitantes (para melhorar o portal) é uma decisão à parte e, se existir, será opt-in e sem dado pessoal.

## Consequências

- A demanda de "ignorar maiúsculas e acentos" fica resolvida já no nível A, sem custo de índice.
- B exigiu uma migração que só adiciona a tabela e já indexa os objetos existentes; C só adiciona uma coluna (`site.busca_tolerancia`, migração 12) — sem tabela nova, sem reindexação. Não há (ainda) comando para reindexar tudo à mão.
- Funciona em hospedagem comum até algumas dezenas de milhares de objetos; acima disso, o driver opcional.

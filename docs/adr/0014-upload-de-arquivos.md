# ADR 0014 — Upload de arquivos

Status: aceito e implementado (2026-11-02, fase 7a). Detalha a parte de upload e entrega do [fluxo 7](../fluxos/07-upload-blobs.md)
e usa a mesma receita de permissão do [ADR 0012](0012-administracao-web.md) (sessão, `Acao::Editar`, CSRF e origem no POST).

## Contexto

O PBL3 guardava upload (`tbl_blob`) sem whitelist nem checagem de MIME: um `.php`/`.phtml` enviado como "imagem" virava
webshell se a pasta de upload estivesse dentro do `docroot`, e SVG era servido inline (XSS armazenado). O PBL4 precisa de
upload de verdade (classes com propriedade `arquivo`/`imagem`) sem repetir nenhum desses erros, com o mesmo padrão das
demais telas da administração: sem JavaScript, permissão em toda rota, CSRF e origem no POST, escape total.

## Decisão

### 1. Onde mora
- Novo namespace `Pbl\Arquivos`: `TiposDeArquivo` (a whitelist fechada), `ArmazenamentoDeArquivos` (caminho em disco e
  gravação atômica), `ServicoDeUpload` (valida e grava um envio), `ServicoDeMiniaturas` (miniaturas sob demanda),
  `ArquivoRepositorio` (metadados), `Arquivo` (o valor), `ArquivoController` (entrega pública), `LimpadorDeUploads`
  (`bin/pbl limpar-uploads`).
- Nova tabela `arquivo` (migração M012, só acrescenta: a versão anterior do código a ignora, ADR 0006). Uma propriedade de
  classe do tipo `arquivo` ou `imagem` guarda, no JSON do objeto, o **id de uma linha de `arquivo`** — o mesmo desenho de
  `ref`, mas apontando para `arquivo` em vez de `objeto`.
- `ClasseRepositorio::TIPOS` ganha `arquivo` e `imagem`; a propriedade pode configurar `tipos` (subconjunto das extensões
  aceitas) e `tamanho_max_mb` (até `TiposDeArquivo::TETO_MB`); sem configurar, vale a lista inteira da categoria e
  `TiposDeArquivo::PADRAO_MB`. Sempre não indexável (não há como ordenar/filtrar por um arquivo).

### 2. Whitelist fechada, nunca pela extensão do nome
- `TiposDeArquivo::TODOS`: cada extensão aceita tem o `mime` que o servidor sempre entrega, se é `imagem` e os `mime`s que o
  `finfo` pode devolver ao ler o conteúdo. **Nunca** há `php`, `phtml`, `html`, `svg`, `js` ou qualquer formato que um
  navegador execute.
- Imagem: `jpg`, `png`, `gif`, `webp`. Documento: `pdf`, `txt`, `csv` (detecção confiável pelo `finfo`) e, com a ressalva
  abaixo, `docx`, `xlsx`, `pptx`, `odt`, `ods`, `odp`.
- **Limitação conhecida**: os formatos acima que são contêineres ZIP (Office/OpenDocument) às vezes são detectados pelo
  `finfo` só como `application/zip`/`octet-stream`; nesse caso o `ServicoDeUpload` confere também a assinatura
  `PK\x03\x04` do ZIP, mas não valida a estrutura interna do documento. Mais fraco que a verificação de PDF/imagem/texto;
  aceito nesta fatia porque o arquivo nunca é interpretado (serve só como download, com `nosniff`), não porque a
  verificação seja perfeita.

### 3. Pipeline do envio (`ServicoDeUpload::enviar`)
Ordem: erro do PHP (`upload_max_filesize`/`post_max_size`, mensagem clara) → tamanho configurado da propriedade → tipo real
do conteúdo por `finfo` contra a whitelist → se imagem, decodificação de verdade pelo GD (`imagecreatefromstring`) e
**recodificação** (recria os pixels: payload escondido e EXIF somem, `sanitizada = true`) → sem GD, só confere os bytes
mágicos do formato (mais fraco, `sanitizada = false`, registrado no instalador como aviso) → `sha256` do conteúdo final →
grava em `dados/uploads/<2 primeiros da chave>/<chave>.<extensão>` (chave aleatória de 16 bytes, nunca derivada do nome
enviado; se o mesmo conteúdo já existe, reaproveita o arquivo em disco) → linha nova em `arquivo`.

O `ObjetoRepositorio::validar` confere de novo, como defesa em profundidade: o id apontado existe, é deste site e a
categoria (imagem/documento) do arquivo bate com o tipo da propriedade — um formulário forjado não troca o valor por um
arquivo de outro site ou da categoria errada.

### 4. Onde se envia: só na tela de editar
- O upload entra pela `TelasDeObjetos` (tela de editar, `multipart/form-data`), porque o arquivo precisa de um
  **objeto dono** (`objeto_id`, fixado no upload) desde o começo — a tela de criar ainda não existe o objeto. Criar um
  conteúdo com arquivo é salvar primeiro (sem arquivo) e depois editar; a tela de criar mostra essa explicação no lugar do
  campo.
- Campo com arquivo já guardado mostra o arquivo atual (nome, tamanho e, se imagem, a miniatura) e uma caixa "remover o
  arquivo atual". Sem enviar nada novo e sem marcar remover, o valor guardado continua (consistente com o resto do
  formulário: campo que não veio mantém o que já estava salvo).
- `Acao::Editar`, CSRF e origem são conferidos pela mesma rota de editar objeto; nada de permissão nova.

### 5. Entrega pública (`ArquivoController`)
- Rotas `GET /_pbl/arquivo/{id}` e `GET /_pbl/arquivo/{id}/miniatura/{tamanho}`, tratadas pela `Aplicacao` **antes** do
  `Painel` (mesmo padrão de `EnvioDeFormulario`): não exigem sessão, mas respondem só dentro do site do domínio da
  requisição.
- Visível **só** se o objeto dono (fixado no upload, nunca reavaliado por "quem referencia agora") está `publicado` e
  vigente agora (`Objeto::visivel`); qualquer outro caso — privado, fora do ar, de outro site, id inexistente — dá **404**,
  sempre a mesma resposta (não revela qual existe).
- Cabeçalhos: `Content-Type` sempre da tabela fechada `TiposDeArquivo` (nunca do que foi detectado no upload, embora sejam
  o mesmo valor), `X-Content-Type-Options: nosniff`, `Content-Disposition` (`inline` só para o que é feito para abrir no
  navegador — imagem e PDF —, `attachment` para o resto; nome ASCII saneado em `filename` e o nome de verdade em
  `filename*`, RFC 5987), `Cache-Control: public, max-age=31536000, immutable` (o conteúdo nunca muda depois de gravado:
  trocar o arquivo cria uma linha nova), `ETag` do `sha256` e suporte a **um trecho de `Range`** (`BinaryFileResponse` do
  Symfony).
- Miniatura: só de imagem, só com GD, gerada sob demanda e cacheada em `dados/cache/miniaturas/` (nunca no `docroot`, como
  o resto do cache); tamanho só de `ServicoDeMiniaturas::TAMANHOS` (nunca o que vier na URL, para não gerar um arquivo por
  pixel pedido). Sem GD, a tela da administração mostra o arquivo original.

### 6. Versionamento e limpeza (`bin/pbl limpar-uploads`)
- O valor de uma propriedade é o id do arquivo: uma versão antiga (`objeto_versao`) continua apontando para o arquivo de
  quando foi salva, e restaurar uma versão antiga traz o arquivo de volta, de graça (nenhuma cópia extra).
- Trocar ou remover o valor de uma propriedade **nunca apaga o arquivo antigo na requisição** — seria caro e arriscado numa
  corrida (outra aba ainda pode estar salvando uma versão que o referencia). O arquivo e a linha continuam existindo até
  virarem órfãos de verdade.
- `bin/pbl limpar-uploads [--simular]`: lista as propriedades `arquivo`/`imagem` de todas as classes, varre `objeto` e
  **todas** as linhas de `objeto_versao` coletando os ids ainda referenciados, e apaga (arquivo físico + linha) o que não
  está nessa lista e foi criado há mais de 1 hora (carência contra um envio cujo formulário ainda não terminou de salvar).
  Pensado para o cron, de vez em quando — não para rodar a cada requisição.

### 7. Instalador
- `fileinfo` passa a ser **obrigatório** (`Requisitos`): é o que identifica o tipo real do conteúdo enviado.
- `gd` é **opcional**, como `zip` já era: sem ele, upload de imagem funciona com uma verificação mais fraca (sem
  recodificar, `sanitizada = false`) e não há miniaturas. A tela do instalador explica a diferença.

## Consequências

- Nenhum arquivo enviado pode ser executado pelo servidor: a whitelist nunca inclui `php`/`html`/`svg`/`js`, o `Content-Type`
  nunca vem do que o cliente mandou, e `dados/uploads/` fica fora do `docroot` (como o resto de `dados/`).
- A limitação da detecção de contêiner ZIP (§2) é uma dívida conhecida: quando houver tempo, vale olhar a estrutura interna
  (por exemplo, `[Content_Types].xml` do OOXML) antes de aceitar `docx`/`xlsx`/`pptx`.
- Fora desta fatia: upload na criação do conteúdo, comando de molde para montar o link (hoje é só `#propriedade` → id →
  `/_pbl/arquivo/<id>`, montado por quem lê o id), editor de `tipos`/`tamanho_max_mb` por propriedade na tela de tipos de
  conteúdo, anexos de formulário público (fluxo 6, feito à parte quando vier), armazenamento em object storage (S3/B2 —
  já isolado em `ArmazenamentoDeArquivos`, troca sem tocar no resto), remoção de EXIF quando o GD não está disponível.

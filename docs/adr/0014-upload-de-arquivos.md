# ADR 0014 — Upload de arquivos

Status: aceito e implementado (2026-11-02, fase 7a; §4.1, §4.2 e §4.3 completadas na fase 7b, mesma data). Detalha a parte
de upload e entrega do [fluxo 7](../fluxos/07-upload-blobs.md) e usa a mesma receita de permissão do
[ADR 0012](0012-administracao-web.md) (sessão, `Acao::Editar`, CSRF e origem no POST).

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
- Nova tabela `arquivo` (migração M013, só acrescenta: a versão anterior do código a ignora, ADR 0006). Uma propriedade de
  classe do tipo `arquivo` ou `imagem` guarda, no JSON do objeto, o **id de uma linha de `arquivo`** — o mesmo desenho de
  `ref`, mas apontando para `arquivo` em vez de `objeto`.
- `ClasseRepositorio::TIPOS` ganha `arquivo` e `imagem`; a propriedade pode configurar `tipos` (subconjunto das extensões
  aceitas) e `tamanho_max_mb` (até `TiposDeArquivo::TETO_MB`); sem configurar, vale a lista inteira da categoria e
  `TiposDeArquivo::PADRAO_MB`. Sempre não indexável (não há como ordenar/filtrar por um arquivo).

### 2. Whitelist fechada, nunca pela extensão do nome
- `TiposDeArquivo::TODOS`: cada extensão aceita tem o `mime` que o servidor sempre entrega, se é `imagem` e os `mime`s que o
  `finfo` pode devolver ao ler o conteúdo. **Nunca** há `php`, `phtml`, `html`, `svg`, `js` ou qualquer formato que um
  navegador execute.
- Imagem: `jpg`, `png`, `gif`, `webp`. Documento: `pdf`, `txt`, `csv` (detecção direta pelo `finfo`) e `docx`, `xlsx`,
  `pptx`, `odt`, `ods`, `odp`.
- **Office e OpenDocument são contêineres ZIP**: o `finfo` às vezes só diz `application/zip`/`octet-stream` para eles (o
  MIME não distingue um `docx` de um `xlsx`). Quando isso acontece, `ServicoDeUpload::identificarPacoteZip` confere a
  assinatura `PK\x03\x04` e **olha dentro do pacote** com `ZipArchive` antes de aceitar: `word/document.xml` → `docx`,
  `xl/workbook.xml` → `xlsx`, `ppt/presentation.xml` → `pptx`; para ODF, o arquivo `mimetype` (sempre o primeiro do ZIP,
  sem compressão) diz o tipo exato. Pacote que não bate com nenhum desses é **ambíguo e recusado** (nunca aceito pelo
  primeiro candidato da whitelist, que trocaria um formato pelo outro). Sem a extensão `zip` do PHP (opcional, como no
  atualizador) não há como olhar dentro do pacote, então esses seis formatos são sempre recusados — a tela de
  requisitos do instalador avisa.

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

### 4. Onde se envia: editar **e**, desde a fase 7b, criar
- O upload entra pela `TelasDeObjetos` (`multipart/form-data`), nas telas de editar e de criar. O arquivo precisa de um
  **objeto dono** (`objeto_id`, fixado no upload) desde o começo; na edição o objeto já existe, então o envio é direto
  (`ServicoDeUpload::enviar` chamado na hora). Na criação o objeto ainda não existe quando o formulário chega — ver §4.1.
- Campo com arquivo já guardado (edição) mostra o arquivo atual (nome, tamanho e, se imagem, a miniatura) e uma caixa
  "remover o arquivo atual". Sem enviar nada novo e sem marcar remover, o valor guardado continua (consistente com o resto
  do formulário: campo que não veio mantém o que já estava salvo). Na criação não há "arquivo atual" para mostrar.
- `Acao::Editar`/`podeCriarSob`, CSRF e origem são conferidos pela mesma rota de editar/criar objeto; nada de permissão nova.

#### 4.1 Upload na criação é atômico (fase 7b)

Criar um conteúdo com arquivo na mesma submissão precisa resolver uma ordem: o upload grava `objeto_id` no arquivo, mas o
objeto só ganha um id depois do `INSERT`. A solução é um **gancho dentro da mesma transação**, não duas chamadas separadas:

1. `TelasDeObjetos::lerFormulario`, ao **criar** (`$atual === null`), não chama `ServicoDeUpload` na hora: só confere se um
   campo obrigatório ficou sem nenhum arquivo (erro imediato, sem nem tentar criar) e guarda cada `UploadedFile` pendente,
   por nome de propriedade.
2. `ObjetoRepositorio::criar`/`criarDentro` ganham um parâmetro opcional `$aposInserir` (`\Closure`): roda **dentro da
   mesma transação**, logo depois do `INSERT` do objeto (já com o id novo) e antes de `registrarVersao`/indexação. O
   retorno dele (propriedade → id do arquivo) é mesclado nos `dados` e gravado num segundo `UPDATE` da mesma linha, ainda
   na transação.
3. `TelasDeObjetos::criar` passa um `$aposInserir` que chama `ServicoDeUpload::enviar` para cada pendência, agora com o id
   do objeto novo como dono. Se algum envio falhar (`ErroDeUpload`), a exceção atravessa o `Closure` e o `transactional()`
   do Doctrine desfaz **tudo**: o objeto nunca chega a existir, nenhuma linha de `arquivo` fica órfã referenciada por nada.
   `ErroDeUpload` carrega o nome da propriedade (`propriedade`) para o erro aparecer ligado ao campo certo no formulário,
   em vez de um erro genérico.
4. Propriedades `arquivo`/`imagem` obrigatórias ficam fora da primeira conferência de obrigatoriedade (antes do insert,
   ainda não há valor possível) e entram de novo, já com os dados mesclados, depois do `$aposInserir` — a mesma proteção
   da edição, só que em dois momentos da mesma transação.
5. **O que não é transacional**: o arquivo físico em disco (`ArmazenamentoDeArquivos::gravar`) é gravado **antes** do
   `INSERT` na tabela `arquivo` e não participa da transação do banco (é o sistema de arquivos, não o SQLite/Postgres). Se
   a transação do banco for desfeita depois disso, o conteúdo gravado fica **órfão de fábrica**: sem nenhuma linha em
   `arquivo` apontando para ele, nunca listado por `bin/pbl limpar-uploads` (que varre `arquivo`, não o disco), e
   reaproveitado de graça se alguém enviar o mesmo conteúdo depois (dedup por `sha256`+extensão). Não é um vazamento ativo
   (nada o referencia), mas também não se autolimpa; aceito pela mesma razão do §6: apagar arquivo é raro e caro de fazer
   com segurança numa corrida.

#### 4.2 Comando de molde (fase 7b)

`Pbl\Linguagem`: comandos `<@arquivo valor=… info="url|nome|tamanho|tipo"@>` e `<@miniatura valor=… tamanho=n@>` (gramática,
exemplos e regra de visibilidade em [linguagem-pbl4.md](../linguagem-pbl4.md#arquivos-arquivominiatura)). Sempre escapados
(como `eco_limite`/`formatadata`, nunca com variante "cru") e só imprimem algo para um arquivo cujo **objeto dono** está
publicado e vigente **no site do objeto corrente** — a mesma regra do `ArquivoController`, repetida em
`FonteRepositorio::arquivo` (que já tem a conexão com `ArquivoRepositorio`/`ObjetoRepositorio`; `Runtime` continua sem
depender de `Pbl\Arquivos`, só da interface `FonteDeDados`).

#### 4.3 Configuração por propriedade na tela de tipos de conteúdo (fase 7b)

`ClasseRepositorio::normalizarRestricoesDeArquivo` (fase 7a) já validava `tipos`/`tamanho_max_mb`; faltava a tela.
`TelasDeClasses` ganhou, por propriedade `arquivo`/`imagem` (existente ou nova), caixas de seleção com toda a whitelist
global (`TiposDeArquivo::imagens()`/`documentos()`) e um campo numérico para `tamanho_max_mb`. Sem JavaScript a tela não
sabe esconder esses controles quando o tipo do campo não é arquivo/imagem, então ficam sempre visíveis (com um aviso) e o
`ClasseRepositorio` ignora os dois fora desses dois tipos — mesma filosofia do seletor "aponta para" de uma `ref`, que
também fica sempre visível. Uma combinação inválida (extensão fora da categoria, tamanho fora de 1–`TETO_MB`) vira erro
claro na própria tela (`TelasDeClasses::erroDeArquivo`, antes de chegar ao repositório).

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
- `zip` (opcional) agora também é usado para identificar `docx`/`xlsx`/`pptx`/`odt`/`ods`/`odp` (§2); sem ela, upload
  desses seis formatos é sempre recusado como ambíguo, e a tela do instalador avisa.

## Consequências

- Nenhum arquivo enviado pode ser executado pelo servidor: a whitelist nunca inclui `php`/`html`/`svg`/`js`, o `Content-Type`
  nunca vem do que o cliente mandou, e `dados/uploads/` fica fora do `docroot` (como o resto de `dados/`).
- Criar e anexar um arquivo é atômico (§4.1): nenhum conteúdo novo fica com um upload pela metade, ao custo de um arquivo
  físico órfão de fábrica no raro caso de a transação do banco ser desfeita depois do arquivo já gravado em disco (nunca
  referenciado, nunca listado por `bin/pbl limpar-uploads`, reaproveitado se o mesmo conteúdo for enviado de novo).
- Fora desta fatia: anexos de formulário público (fluxo 6, feito à parte quando vier), armazenamento em object storage
  (S3/B2 — já isolado em `ArmazenamentoDeArquivos`, troca sem tocar no resto), remoção de EXIF quando o GD não está
  disponível.

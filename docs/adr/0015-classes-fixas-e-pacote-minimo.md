# ADR 0015 — Classes fixas e pacote mínimo de instalação

Status: aceito e implementado (2026-11-02, fase 5l). Complementa o [ADR 0012](0012-administracao-web.md) (tela de tipos de
conteúdo) e o [ADR 0013](0013-formularios-publicos.md) (formulários). O menu, a busca e as páginas dinâmicas desta mesma fase
estão no [ADR 0016](0016-menu-busca-e-paginas-dinamicas.md).

## Contexto

Até aqui o instalador criava duas classes (`pasta` e `pagina`) e uma página de exemplo. Um site recém-instalado não tinha
notícia, imagem, arquivo, link nem formulário de contato, e nada impedia a administração de **apagar** ou **esvaziar** uma
classe de que o sistema depende (a pele básica usa `resumo` e `corpo`; o formulário público usa `destino` e `campos`). Também
não havia onde guardar título, descrição e imagem para buscadores e redes sociais.

## Decisão

### 1. Seis classes fixas
`pasta`, `pagina`, `formulario`, `arquivo`, `imagem` e `link` são **fixas** (coluna `classe.fixa`). Só `noticia` (e o que a
administração criar) é apagável. Uma classe fixa:

- **não pode ser apagada** (`ClasseRepositorio::apagar` recusa, mesmo vazia; a tela não mostra o botão);
- **não pode ser renomeada** (o nome técnico já é imutável; nada muda);
- **não perde a base**: as propriedades que o sistema usa (`ClassesPadrao::BASE`) não podem ser removidas nem arquivadas.
  Dá para **acrescentar** propriedades, trocar rótulos e ajustar a descrição.

A lista de tipos mostra o selo **fixa**.

### 2. Pacote mínimo, na mesma transação
`Instalador` chama `ClassesPadrao::garantir()` e cria o conteúdo de exemplo dentro de **uma** transação: ou o site nasce
inteiro, ou não nasce. O pacote: classes fixas + `noticia` + `mensagem_contato`; página inicial; **Sobre**; pasta
**Notícias** com a notícia "Bem-vindo ao novo site"; pasta privada **Mensagens recebidas**; e o formulário **Contato** (campos
nome, e-mail e mensagem; os envios vão para a pasta privada). Sobre, Notícias e Contato entram no menu
([ADR 0016](0016-menu-busca-e-paginas-dinamicas.md)).

`ClassesPadrao::garantir()` é idempotente: cria o que falta e nunca sobrescreve uma classe que a administração já ajustou.

### 3. Migração 16, idempotente
`M016ClassesFixas` acrescenta `classe.fixa` (e as colunas de menu, ADR 0016) **se faltarem** e marca as seis classes que já
existirem. O `Migrador` só guarda a versão máxima, então a migração confere a coluna antes de alterar; rodar de novo é
inofensivo. Não existe migração 15 (a numeração pulou; não há lacuna a preencher).

### 4. Link
Propriedades `destino_interno` (`ref`), `destino_externo` (texto), `nova_aba` (booleano) e `descricao`. O nome é
`destino_externo` (e não `url`) porque `url` é nome **reservado** de propriedade (o contexto do molde já usa `#url`).

Regra (`RegrasDeLink`, aplicada em `ObjetoRepositorio::criar` e `atualizar`): **exatamente um** dos dois destinos. O externo
só aceita `http`, `https`, `mailto` e `tel`; `javascript:`, `data:` e semelhantes são recusados. O interno guarda o **id**
do alvo, então o link acompanha o endereço dele se mudar. Na pele, o `<a>` ganha `rel="noopener noreferrer"` quando é externo
ou abre em nova aba (`target="_blank"`).

### 5. SEO opcional
`pasta`, `pagina` e `noticia` ganham `seo_titulo`, `seo_descricao`, `seo_imagem` e `seo_nao_indexar`. A pele básica
(`cabeca.pbl`) emite `<title>`, `description`, `canonical`, `og:title`, `og:description`, `og:type`, `og:url`, `og:image` e
`robots noindex`, tudo escapado. Sem SEO preenchido, cai no título, no resumo e na imagem de destaque.

## Consequências

- Uma instalação nova já tem o que um site pequeno precisa; a documentação e o conversor podem contar com essas classes.
- `canonical`, `og:url` e `og:image` saem **relativos** (o molde ainda não conhece o host do domínio principal). Passar o host
  ao molde, junto de `sitemap.xml` e `robots.txt`, fica como pendência no [plano](../plano.md).
- Instalações antigas ganham as colunas pela migração, mas **não** ganham as classes novas sozinhas: `bin/pbl instalar` só
  roda uma vez. Quem quiser o pacote cria as classes pela tela de tipos.
- Uma classe fixa com a base alterada à mão no banco (fora do painel) não é corrigida automaticamente.

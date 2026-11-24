---
titulo: Tipos de conteúdo
resumo: O que é um tipo de conteúdo, quais já vêm prontos, como criar um tipo próprio com campos, definir onde ele pode ficar e escolher o ícone.
capitulo: conteudo
ordem: 30
---

# Tipos de conteúdo

Um **tipo de conteúdo** é o molde de um conteúdo: define quais campos ele tem e onde pode ficar. "Notícia", por exemplo, tem título, resumo, texto e imagem de destaque. Quando você cria um conteúdo, a primeira escolha é o tipo.

Só administradores criam e alteram tipos. Os demais podem apenas ver a lista.

![Lista de tipos de conteúdo e seus campos](captura:painel-tipos)

## Tipos que já vêm no site

| Tipo | Para quê |
|---|---|
| **pagina** | Página de texto, com resumo e texto. Também serve de página inicial. |
| **noticia** | Notícia ou artigo, com resumo, texto e imagem de destaque. Aceita imagens, arquivos e links dentro. |
| **pasta** | Agrupa outros conteúdos (seções, listas). Aceita qualquer tipo dentro. |
| **imagem** | Uma imagem, com legenda e texto alternativo. |
| **arquivo** | Um arquivo para baixar, como um PDF. |
| **link** | Um atalho para um conteúdo do site ou para um endereço externo, com a opção de abrir em nova aba. |
| **formulario** | Formulário público; cada envio chega como conteúdo privado numa seção de destino. |

Seis deles são **fixos**: pagina, pasta, imagem, arquivo, link e formulario. O sistema depende deles, então não podem ser apagados nem renomeados, e os campos de base não podem ser removidos. Acrescentar campos é permitido. Os demais, como noticia, são pontos de partida que podem ser apagados.

Pasta, pagina e noticia têm os campos de busca e redes sociais (título, descrição e imagem de compartilhamento), que ficam atrás do interruptor **Aparecer bem nas buscas e nas redes sociais** ao editar.

## Criar um tipo próprio

1. Abra **Mais** e clique em **Tipos de conteúdo**.
2. Clique em **Novo tipo de conteúdo**.
3. Em **Nome técnico**, use minúsculas, números e `_`, começando por letra (exemplo: `evento`). O nome não muda depois e não pode repetir outro tipo.
4. Clique em **Criar tipo de conteúdo**. Os campos entram na tela seguinte.

## Adicionar campos

Na tela do tipo, abra a aba **Campos**.

1. Em **Campos deste tipo**, preencha o **Nome técnico** do campo novo (minúsculas, números e `_`; nomes do sistema como `titulo`, `descricao` e `url` não valem).
2. Escreva o **Rótulo**, o nome que aparece nos formulários.
3. Escolha o **Tipo do campo**: **Texto curto**, **Número**, **Texto formatado (HTML)**, **Data**, **Sim ou não**, **Referência a outro conteúdo**, **Imagem** ou **Arquivo**.
4. Marque **Obrigatório** se o campo não pode ficar vazio. Use **Ordem** para escolher a posição (menor número primeiro; em branco vai para o fim).
5. Clique em **Salvar alterações**.

Alguns detalhes:

- **Indexável** permite filtrar e ordenar por aquele campo nos modelos de página. Não muda depois que houver valores.
- Campos de **Imagem** e **Arquivo** têm **Arquivos permitidos** e **Tamanho máximo (MB)**; veja [Arquivos e imagens](arquivos-e-imagens.md).
- Um campo com dados não troca de tipo nem se remove. Escolha **Arquivado** em **Situação**: ele sai dos formulários, mas os valores continuam guardados, e dá para voltar a **Ativo** depois.
- Um tipo pode ter até cem campos.

## Onde cada tipo pode ficar

Abra a aba **Onde pode ficar**.

1. Marque **Pode ter conteúdo dentro (filhos)** se este tipo funciona como pasta.
2. Em **Tipos aceitos dentro**, marque os tipos permitidos. Sem nenhum marcado, vale qualquer tipo.
3. Clique em **Salvar alterações**.

A regra vale ao criar ou mover conteúdo. Por exemplo, se noticia aceita só imagem, arquivo e link, o painel recusa uma página dentro de uma notícia.

## Escolher o ícone

1. Abra o tipo e vá à aba **Aparência**.
2. Em **Ícone na listagem do painel**, escolha uma opção (ou **Sem ícone**).
3. Clique em **Salvar ícone**.

O ícone aparece em cada linha das listas de conteúdo. Sem escolha, os tipos fixos usam o ícone padrão deles.

## Apagar um tipo

Só dá para apagar um tipo que não é fixo e que nenhum conteúdo usa. O painel pede a sua senha e avisa que não tem volta: os modelos de página com esse nome deixam de ser usados. Para isso, abra o tipo e clique em **Apagar este tipo de conteúdo**.

## Veja também

- [Escrever e publicar conteúdo](escrever-e-publicar.md)
- [Arquivos e imagens](arquivos-e-imagens.md)
- [Modelos e demonstração](modelos-e-demo.md)
- [Glossário](glossario.md)

---
titulo: Arquitetura, decisões e releases
resumo: Onde ficam a arquitetura, os registros de decisão (ADRs), o versionamento e o passo a passo das releases.
capitulo: projeto
ordem: 30
publico: contribuidor
---

# Arquitetura, decisões e releases

Quem contribui com o código precisa saber onde as coisas são decididas e registradas. Tudo isso fica no repositório, em Markdown, e muda junto com o código.

## Arquitetura

- [Arquitetura](../tecnica/arquitetura.md): do pedido do visitante à página publicada, e o que cada parte faz.
- [Fluxos](../fluxos/01-requisicao-publica.md): o desenho detalhado de cada processo (requisição pública, compilação do PBL, instalação, edição de peles, autenticação, busca e outros).
- [Banco de dados](../tecnica/banco-de-dados.md) e [segurança](../tecnica/seguranca.md).
- [Preparar o ambiente de desenvolvimento](../tecnica/desenvolvimento.md).

## Decisões (ADRs)

Cada decisão importante vira um **registro de decisão de arquitetura** (ADR): o contexto, a escolha e as consequências. Antes de propor uma mudança grande, procure se já existe um ADR sobre o assunto. O índice está na [pasta de ADRs](../adr/0001-base-p3-sem-framework.md); por exemplo, a [fonte única do manual](../adr/0040-fonte-unica-do-manual.md) explica por que este manual vive no repositório.

## Versões e releases

- [Versionamento](../versionamento.md): como o número da versão é decidido.
- [Releases](../releases.md): o passo a passo para publicar, quem escreve o CHANGELOG e as notas da release.
- O [CHANGELOG](../../CHANGELOG.md) lista o que mudou em cada versão; todo PR o atualiza.
- A [linguagem PBL4](../linguagem-pbl4.md) tem a referência técnica completa dos moldes, que o capítulo [Linguagem PBL4](pbl4-visao-geral.md) deste manual explica por tarefa.

## Este manual

O manual nasce da pasta `docs/usuario` e é publicado a cada release. Veja [como ele é gerado](../tecnica/manual.md). Quem muda uma tela ou um comando atualiza o guia correspondente no mesmo PR.

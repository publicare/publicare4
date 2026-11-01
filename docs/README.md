# PBL4 — documentação de design

PBL4 é a retomada do Publicare (CMS público brasileiro, EAV + linguagem de templates PBL). A base é o **publicare3** (código e schema provados em produção); o rascunho anterior do `publicare4` (abr/2025) foi descartado.

Objetivos:

1. Amadurecer o core e o sistema de templates (PBL).
2. Instalador web para usuário leigo, sem conhecimento de PBL.
3. Editor interno de templates, layouts e peles.

## Índice

**Decisões (ADRs)**
- [0001 — p3 como base, sem framework](adr/0001-base-p3-sem-framework.md)
- [0002 — Schema: evolução aditiva](adr/0002-schema-evolucao-aditiva.md)

**Fluxos** (como é hoje no p3 → como fica no PBL4)
1. [Requisição pública](fluxos/01-requisicao-publica.md)
2. [Compilação do PBL](fluxos/02-compilacao-pbl.md)
3. [Instalação](fluxos/03-instalacao.md)
4. [Edição de peles e templates](fluxos/04-edicao-peles.md)
5. [Conteúdo e workflow](fluxos/05-conteudo-workflow.md)
6. [Autenticação e permissões](fluxos/06-autenticacao-permissoes.md)
7. [Upload e blobs](fluxos/07-upload-blobs.md)
8. [Busca e indexação](fluxos/08-busca-indexacao.md)

**Plano**
- [Fases e questões em aberto](plano.md)

## Convenções

- Referências `arquivo:linha` apontam para o `publicare3` (repo irmão) no estado de 2021-03.
- Diagramas em Mermaid.
- Estado atual do `src/` deste repo: rascunho antigo (migrações com UUID). Será substituído na Fase 0, depois que este design for aprovado.

# PBL4 — documentação de design

PBL4 é a retomada do Publicare e uma **plataforma low-code de sites, dados e fluxos** para sistemas pequenos de órgãos públicos ([ADR 0003](adr/0003-plataforma-low-code.md)). Mantém as ideias do PBL3 (classes definidas pelo admin, árvore de objetos, perfis por subárvore, workflow, templates em português) e troca a implementação. O rascunho anterior do `publicare4` (abr/2025) foi descartado.

Objetivos:

1. Amadurecer o core e o sistema de templates (PBL).
2. Instalador web para usuário leigo, sem conhecimento de PBL.
3. Editor interno de templates, layouts e peles.

## Índice

**Decisões (ADRs)**
- [0003 — Plataforma low-code de sites, dados e fluxos](adr/0003-plataforma-low-code.md) (vigente)
- [0004 — Identidade pública](adr/0004-identidade-publica.md) (vigente)
- [0005 — Pagamentos e conectores](adr/0005-pagamentos-conectores.md) (vigente)
- [0006 — Atualizador automático](adr/0006-atualizador-automatico.md) (proposto)
- [0007 — Galeria e pacotes de peles](adr/0007-galeria-pacotes-peles.md) (proposto)
- [0008 — Peles avançadas pagas e comunidade](adr/0008-marketplace-comunidade.md) (futuro)
- [0009 — Peles de saída: HTML, JSON (headless/BFF)](adr/0009-peles-de-saida-headless.md) (proposto)
- [0001 — Sem framework full-stack](adr/0001-base-p3-sem-framework.md) (parcialmente substituído)
- [0002 — Schema: evolução aditiva](adr/0002-schema-evolucao-aditiva.md) (substituído)

**Fluxos** (como é hoje no p3 → como fica no PBL4)
1. [Requisição pública](fluxos/01-requisicao-publica.md)
2. [Compilação do PBL](fluxos/02-compilacao-pbl.md)
3. [Instalação](fluxos/03-instalacao.md)
4. [Edição de peles e templates](fluxos/04-edicao-peles.md)
5. [Conteúdo e workflow](fluxos/05-conteudo-workflow.md)
6. [Autenticação e permissões](fluxos/06-autenticacao-permissoes.md)
7. [Upload e blobs](fluxos/07-upload-blobs.md)
8. [Busca e indexação](fluxos/08-busca-indexacao.md)
9. [Armazenamento, referências e consulta](fluxos/09-armazenamento-consulta.md)
10. [Formulários, fluxos, participação e pagamento](fluxos/10-formularios-fluxos-participacao.md)
11. [Atualização automática](fluxos/11-atualizacao.md)

Cada fluxo traz a seção "Hoje (PBL3)" e o desenho PBL4 alinhado aos ADRs 0003 a 0009.

**Referência**
- [Linguagem PBL4](linguagem-pbl4.md): gramática, comandos e conversão do PBL3

**Plano**
- [Fases e questões em aberto](plano.md)

## Convenções

- Referências `arquivo:linha` apontam para o `publicare3` (repo irmão) no estado de 2021-03.
- Diagramas em Mermaid.
- Estado atual do `src/` deste repo: rascunho antigo (migrações com UUID). Será substituído na Fase 0, depois que este design for aprovado.

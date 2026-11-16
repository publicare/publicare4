# Documentação do PBL4

PBL4 é a retomada do Publicare: uma **plataforma livre para sites, dados e fluxos**. Escolha o caminho que serve a você.

## Para quem usa

Quer entender o projeto ou criar e cuidar de um site, sem programar.

- [Publicare para quem usa](usuario/README.md): [o que é](usuario/o-que-e.md), [estado do projeto](usuario/estado-do-projeto.md), [glossário](usuario/glossario.md), [perguntas frequentes](usuario/perguntas-frequentes.md), [como participar](usuario/como-participar.md)

## Para quem instala e desenvolve

- [Documentação técnica](tecnica/README.md): [desenvolvimento](tecnica/desenvolvimento.md), [arquitetura](tecnica/arquitetura.md), [linha de comando](tecnica/cli.md), [segurança](tecnica/seguranca.md)
- [Linguagem de moldes PBL4](linguagem-pbl4.md): gramática, comandos e conversão do PBL3

## Para quem colabora e mantém

- [Como colaborar](../CONTRIBUTING.md) · [Código de Conduta](../CODE_OF_CONDUCT.md) · [Política de segurança](../SECURITY.md)
- [Versionamento](versionamento.md) · [Releases e release notes](releases.md) · [CHANGELOG](../CHANGELOG.md)

## Projeto e design

Objetivos: amadurecer o núcleo e a linguagem de moldes; instalador web para quem não é técnico; editor de peles; atualização automática segura.

**Decisões (ADRs)**
- [0003 — Plataforma low-code de sites, dados e fluxos](adr/0003-plataforma-low-code.md) (vigente)
- [0004 — Identidade pública](adr/0004-identidade-publica.md) (vigente)
- [0005 — Pagamentos e conectores](adr/0005-pagamentos-conectores.md) (vigente)
- [0006 — Atualizador automático](adr/0006-atualizador-automatico.md) (proposto)
- [0007 — Galeria e pacotes de peles](adr/0007-galeria-pacotes-peles.md) (proposto)
- [0008 — Peles avançadas pagas e comunidade](adr/0008-marketplace-comunidade.md) (futuro)
- [0009 — Peles de saída: HTML, JSON (headless/BFF)](adr/0009-peles-de-saida-headless.md) (proposto)
- [0010 — Busca: normalização, relevância e grafias parecidas](adr/0010-busca.md) (níveis A, B e C implementados; drivers para portais grandes propostos)
- [0011 — Autenticação, sessão, permissões e auditoria](adr/0011-autenticacao-sessao-permissoes.md) (implementado)
- [0012 — Administração pelo navegador](adr/0012-administracao-web.md) (aceito e implementado em parte; ver o cabeçalho do ADR)
- [0013 — Formulários públicos declarativos](adr/0013-formularios-publicos.md) (fatia 6a implementada)
- [0014 — Upload de arquivos](adr/0014-upload-de-arquivos.md) (aceito e implementado)
- [0015 — Classes fixas e pacote mínimo de instalação](adr/0015-classes-fixas-e-pacote-minimo.md) (aceito e implementado)
- [0016 — Menu por snapshot, busca e páginas dinâmicas da pele básica](adr/0016-menu-busca-e-paginas-dinamicas.md) (aceito e implementado)
- [0017 — Configurações do site](adr/0017-configuracoes-do-site.md) (aceito e implementado)
- [0018 — Licenciamento: Apache-2.0](adr/0018-licenciamento-apache-2.md) (aceito)
- [0019 — Custódia da chave de assinatura e origem de distribuição](adr/0019-custodia-da-chave-de-assinatura.md) (aceito)
- [0020 — Editor de texto rico para campos `html`](adr/0020-editor-de-texto-rico.md) (aceito)
- [0021 — Modelos opcionais de conteúdo e demo](adr/0021-modelos-opcionais.md) (aceito)
- [0022 — Pele e molde por objeto, peles com JavaScript opcional e barra de edição](adr/0022-pele-e-molde-por-objeto.md) (aceito)
- [0023 — Cron central e republicação em lotes com progresso](adr/0023-cron-central-e-republicacao-em-lotes.md) (aceito)
- [0024 — Tags nos objetos](adr/0024-tags-nos-objetos.md) (aceito)
- [0025 — Pilha: copiar, recortar e colar objetos](adr/0025-pilha-copiar-recortar-colar.md) (aceito)
- [0026 — Itens menores do PBL3: vencidos, lixeira, registro, ícone e QR code](adr/0026-itens-menores-do-pbl3.md) (aceito)
- [0027 — Importador de bases do PBL3](adr/0027-importador-pbl3.md) (aceito)
- [0001 — Sem framework full-stack](adr/0001-base-p3-sem-framework.md) (parcialmente substituído)
- [0002 — Schema: evolução aditiva](adr/0002-schema-evolucao-aditiva.md) (substituído)

**Fluxos** (como era no PBL3 → como fica no PBL4)
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
12. [Administração pelo navegador](fluxos/12-administracao-web.md)

**Plano**
- [Fases e questões em aberto](plano.md)

## Convenções

- Os fluxos trazem a seção "Hoje (PBL3)" e o desenho do PBL4; onde divergirem, valem os ADRs.
- Referências `arquivo:linha` nos fluxos apontam para o `publicare3` (repositório irmão).
- Diagramas em Mermaid. Links relativos.
- Repositório público: **sem nome de cliente, órgão ou dado de terceiros**.

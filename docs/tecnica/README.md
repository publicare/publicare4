# Documentação técnica

Para quem **instala, opera e desenvolve** o PBL4. Se você quer entender o projeto sem entrar em detalhes técnicos, comece pela [documentação para quem usa](../usuario/README.md).

| Documento | Conteúdo |
|---|---|
| [Desenvolvimento](desenvolvimento.md) | Ambiente, comandos `make`, testes, análise estática e fluxo de trabalho |
| [Arquitetura](arquitetura.md) | Como uma requisição vira página; camadas, tabelas e pastas |
| [Instalação e hospedagem](instalacao.md) | Requisitos, assistente web, Apache, nginx, proxy |
| [Cron central](cron.md) | a linha única `bin/pbl cron`, tarefas, pseudo-cron e como conferir |
| [Atualização](atualizacao.md) | `bin/pbl atualizar`, pacote, assinatura e publicação de versões |
| [Banco de dados](banco-de-dados.md) | Camada de dialeto, `banco.json` e variáveis `PBL_BANCO_*` (fundação do ADR 0028) |
| [Site oficial publicare.org](site-oficial.md) | Procedimento do operador: Hestia, banco, domínio, HTTPS na Cloudflare, backup, carga e atualização do conteúdo oficial |
| [Linha de comando](cli.md) | Comandos de `bin/pbl` |
| [Manual de uso: geração e publicação](manual.md) | Como `docs/usuario` vira o manual (`bin/pbl manual-gerar`), edições, capturas e o passo da release |
| [CLI de conteúdo](cli-conteudo.md) | `bin/pbl conteudo`: editar conteúdo por agentes e scripts, contrato JSON, códigos de saída, conta de serviço |
| [Como migrar um site do PBL3](migrar-do-pbl3.md) | Importar conteúdo, arquivos e contas de uma base do PBL3 |
| [Linguagem de moldes PBL4](../linguagem-pbl4.md) | Gramática, comandos, ordenação e conversão do PBL3 |
| [Licenças de terceiros](terceiros.md) | Arquivos de terceiros distribuídos (Bootstrap) e suas licenças |
| [LGPD](lgpd.md) | Inventário de dados pessoais, retenção configurável, exportar e anonimizar contas |
| [Segurança](seguranca.md) | Defesas em vigor, modelo de ameaças e como relatar falhas |
| [Versionamento](../versionamento.md) e [Releases](../releases.md) | Numeração, changelog e como publicar |
| [ADRs](../adr/) e [Fluxos](../fluxos/) | Decisões de design e desenho de cada parte |
| [Plano](../plano.md) | Fases e o que vem a seguir |

## Requisitos

- **Produto**: PHP 8.2+ (extensões `pdo`, `mbstring`, `sodium`, `gd`, `zip`, além de `dom`, `fileinfo` e `libxml`), SQLite no 0.1.0; MySQL 8 e PostgreSQL 16 chegam na 0.2 ([ADR 0028](../adr/0028-suporte-mysql-postgresql.md)).
- **Desenvolvimento**: Docker e `make`. O computador não precisa ter PHP.

## Estado

O núcleo (instalação, modelo de conteúdo, linguagem de moldes, publicação em cache, conversor de moldes) funciona e é coberto por testes. O login da equipe (sessão, CSRF, limite de tentativas), as permissões e o instalador web já existem; a administração pelo navegador cuida do conteúdo, dos tipos de conteúdo, das contas e dos domínios (faltam redirecionamentos e peles). O atualizador funciona pela linha de comando (`bin/pbl atualizar`). Veja o [estado do projeto](../usuario/estado-do-projeto.md) e o [plano](../plano.md).

# Documentação técnica

Para quem **instala, opera e desenvolve** o PBL4. Se você quer entender o projeto sem entrar em detalhes técnicos, comece pela [documentação para quem usa](../usuario/README.md).

| Documento | Conteúdo |
|---|---|
| [Desenvolvimento](desenvolvimento.md) | Ambiente, comandos `make`, testes, análise estática e fluxo de trabalho |
| [Arquitetura](arquitetura.md) | Como uma requisição vira página; camadas, tabelas e pastas |
| [Linha de comando](cli.md) | Comandos de `bin/pbl` |
| [Linguagem de moldes PBL4](../linguagem-pbl4.md) | Gramática, comandos, ordenação e conversão do PBL3 |
| [Segurança](seguranca.md) | Defesas em vigor, modelo de ameaças e como relatar falhas |
| [Versionamento](../versionamento.md) e [Releases](../releases.md) | Numeração, changelog e como publicar |
| [ADRs](../adr/) e [Fluxos](../fluxos/) | Decisões de design e desenho de cada parte |
| [Plano](../plano.md) | Fases e o que vem a seguir |

## Requisitos

- **Produto**: PHP 8.2+ (extensões `pdo`, `mbstring`, `sodium`), SQLite (padrão), MySQL 8 ou PostgreSQL (previstos).
- **Desenvolvimento**: Docker e `make`. O computador não precisa ter PHP.

## Estado

O núcleo (instalação, modelo de conteúdo, linguagem de moldes, publicação em cache, conversor de moldes) funciona e é coberto por testes. Ainda não há autenticação, administração web, instalador web nem atualizador. Veja o [estado do projeto](../usuario/estado-do-projeto.md) e o [plano](../plano.md).
